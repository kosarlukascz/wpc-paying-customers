<?php

if (!class_exists('wpc_platici')) {
    class wpc_platici
    {
        private static $instance; //singleton instance
        private $prefix = 'wpc_platici';

        public function __construct()
        {
            if (class_exists('WP_CLI')) {
                WP_CLI::add_command('wpc-paying-install', array($this, 'install_plugin'));
                WP_CLI::add_command('wpc-paying-remove-all-data', array($this, 'remove_all_data'));
                WP_CLI::add_command('wpc-paying-count', array($this, 'count_platici_cli'));
            }
        }

        public function count_platici_cli($args, $assoc_args)
        {
            //--start_date=2024-07-15 --end_date=2024-07-23
            $start_date = $assoc_args['start_date'];
            $end_date = $assoc_args['end_date'];
            if (empty($start_date) || empty($end_date)) {
                WP_CLI::error('Please provide start_date and end_date yyyy-mm-dd');
            }

            $results = $this->get_wc_products_and_order_counts_with_percentage($start_date, $end_date);
            WP_CLI::success('Results: ' . count($results) . ' products found, wrote.');


        }

        public function remove_all_data()
        {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';
            $wpdb->query("TRUNCATE TABLE $table_name");
            WP_CLI::success('All data removed from table ' . $table_name);
        }


        public function get_wc_products_and_order_counts_with_percentage($start_date_interval, $end_date_interval)
        {
            global $wpdb;

            // Najdeme produkty prodané více než jednou v zadaném intervalu
            $query_products = $wpdb->prepare(
                "
        SELECT
            IFNULL(parent_meta.meta_value, itemmeta.meta_value) as parent_id,
            post_parent.post_title as product_name,
            COUNT(*) as sale_count
        FROM {$wpdb->prefix}woocommerce_order_items AS order_items
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
        INNER JOIN {$wpdb->prefix}posts AS posts ON order_items.order_id = posts.ID
        LEFT JOIN {$wpdb->prefix}postmeta AS parent_meta ON itemmeta.meta_value = parent_meta.post_id AND parent_meta.meta_key = '_parent'
        LEFT JOIN {$wpdb->prefix}posts AS post_parent ON IFNULL(parent_meta.meta_value, itemmeta.meta_value) = post_parent.ID
        WHERE posts.post_type = 'shop_order'
        AND posts.post_status IN ('wc-completed', 'wc-processing', 'wc-old')
        AND itemmeta.meta_key = '_product_id'
        AND posts.post_date BETWEEN %s AND %s
        GROUP BY parent_id
        HAVING sale_count > 0
        ",
                $start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'
            );
            $products = $wpdb->get_results($query_products, ARRAY_A);

            $results = array();

            $all_products_count = count($products);
            WP_CLI::line('Found ' . $all_products_count . ' products.');
            $i = 0;
            foreach ($products as $product) {
                $product_id = $product['parent_id'];
                $product_name = $product['product_name'];

                $query_completed = $wpdb->prepare(
                    "
            SELECT COUNT(DISTINCT order_items.order_id) as order_count
            FROM {$wpdb->prefix}woocommerce_order_items AS order_items
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
            INNER JOIN {$wpdb->prefix}posts AS posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND posts.post_status IN ('wc-completed', 'wc-processing', 'wc-old')
            AND itemmeta.meta_key = '_product_id'
            AND (itemmeta.meta_value = %d OR itemmeta.meta_value IN (
                SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_parent' AND meta_value = %d
            ))
            AND posts.post_date BETWEEN %s AND %s
            ",
                    $product_id, $product_id, $start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'
                );

                $count_completed = $wpdb->get_var($query_completed);

                $query_other = $wpdb->prepare(
                    "
            SELECT COUNT(DISTINCT order_items.order_id) as order_count
            FROM {$wpdb->prefix}woocommerce_order_items AS order_items
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
            INNER JOIN {$wpdb->prefix}posts AS posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND posts.post_status NOT IN ('wc-completed', 'wc-processing', 'wc-old')
            AND itemmeta.meta_key = '_product_id'
            AND (itemmeta.meta_value = %d OR itemmeta.meta_value IN (
                SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_parent' AND meta_value = %d
            ))
            AND posts.post_date BETWEEN %s AND %s
            ",
                    $product_id, $product_id, $start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'
                );

                $count_other = $wpdb->get_var($query_other);
                $total_orders = $count_completed + $count_other;

                $percentage_paid = ($total_orders > 0) ? ($count_completed / $total_orders) * 100 : 0;
                $interval = $start_date_interval . '-' . $end_date_interval;
                $this->insert_paying_customer_stat($product_id, number_format($percentage_paid, 2), $total_orders, $interval);
                WP_CLI::log($i . ' /' . $all_products_count);
                $i++;
            }

            return $results;
        }


        public function install_plugin()
        {
            global $wpdb;

            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';

            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $charset_collate = $wpdb->get_charset_collate();

                $sql = "CREATE TABLE $table_name (
          id int(11) NOT NULL AUTO_INCREMENT,
          product_id int(11) NOT NULL,
          percentage decimal(65, 3) NOT NULL,
          orders_count int(11) NOT NULL,
          interval_time varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          PRIMARY KEY (id)
        ) $charset_collate;";


                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                $wpdb->query($sql);

                if ($wpdb->last_error) {
                    WP_CLI::error("Error creating table: " . $wpdb->last_error);
                } else {
                    WP_CLI::success("Table '$table_name' created successfully.");
                }
            } else {
                WP_CLI::success("Table '$table_name' already exists.");
            }
        }

        public function insert_paying_customer_stat($product_id, $percentage, $orders_count, $interval)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';

            $result = $wpdb->insert(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'percentage' => $percentage,
                    'orders_count' => $orders_count,
                    'interval_time' => $interval
                ),
                array(
                    '%d',
                    '%f',
                    '%d',
                    '%s'
                )
            );

            if ($result === false) {
                return $wpdb->last_error;
            } else {
                return $wpdb->insert_id;
            }
        }

        public function get_paying_customer_stats_by_interval($interval)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE `interval` = %s",
                    $interval
                ),
                ARRAY_A
            );

            return $results;
        }

        public function update_paying_customer_stat($id, $product_id, $percentage, $orders_count, $interval)
        {
            global $wpdb;

            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';

            $result = $wpdb->update(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'percentage' => $percentage,
                    'orders_count' => $orders_count,
                    'interval' => $interval
                ),
                array('id' => $id),
                array(
                    '%d',
                    '%f',
                    '%s'
                ),
                array('%d')
            );

            if ($result === false) {
                return $wpdb->last_error;
            } else {
                return $result;
            }
        }


        public static function get_instance()
        {
            if (self::$instance === null) {
                self::$instance = new self;
            }

            return self::$instance;
        }


        public static function get_help()
        {

        }


    }
}


?>
