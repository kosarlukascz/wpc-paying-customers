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
                WP_CLI::add_command('wpc-paying-update-products', array($this, 'assign_meta_for_product_with_low_ratio_of_paying_customers'));

            }
            add_action('admin_menu', array($this, 'add_admin_menu'));

        }

        public function add_admin_menu()
        {
            add_menu_page(
                'Paying customers Products',
                'Paying customers Products',
                'manage_options',
                'paying-customers-products',
                array($this, 'display_admin_page'),
                'dashicons-admin-users',
                6
            );
        }

        public function display_admin_page()
        {
            global $wpdb;

            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 40;

            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';
            $results = $wpdb->get_results("SELECT * FROM $table_name WHERE percentage < {$limit} ORDER BY orders_count DESC");

            // Get current URL to retain query parameters
            $current_url = admin_url('admin.php?page=paying-customers-products');

            echo '<div class="wrap">';
            echo '<h1>Bad paying customers - Products</h1>';
            echo '<h3>Products with paying ratio under ' . esc_html($limit) . '</h3>';
            echo '<p>Found <u>' . esc_html(count($results)) . '</u> products.</p>';
            echo '<form method="get" action="' . esc_url($current_url) . '">';
            echo '<input type="hidden" name="page" value="paying-customers-products">';
            echo '<label for="limit">Set limit:</label>';
            echo '<input type="number" name="limit" id="limit" value="' . esc_attr($limit) . '">';
            echo '<input type="submit" value="Set limit">';
            echo '</form>';

            if (empty($results)) {
                echo '<p>No data found.</p>';
            } else {


                echo '<table class="widefat fixed" cellspacing="0">';
                echo '<thead>';
                echo '<tr>';
                echo '<th id="order_id" class="manage-column column-order_id" scope="col">ID</th>';
                echo '<th id="product_name" class="manage-column column-product_name" scope="col">Product Name</th>';
                echo '<th id="order_count" class="manage-column column-order_count" scope="col">Order Count</th>';
                echo '<th id="percentage" class="manage-column column-percentage" scope="col">Percentage (% paid)</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($results as $row) {
                    $product = wc_get_product($row->product_id);
                    //true is product is in published state
                    $published = true;
                    if ($product) {
                        $published = $product->get_status() === 'publish';
                        if ($published == 'publish') {
                            $badge = true;
                        } else {
                            $badge = false;
                        }
                    }
                    $product_name = $product ? $product->get_name() : 'Product not exists anymore';
                    echo '<tr>';
                    echo '<td>' . esc_html($row->product_id) . '</td>';
                    if ($product) {
                        //badge if product is published
                        echo '<td><a href="' . esc_url(get_permalink($row->product_id)) . '">' . esc_html($product_name) . '</a> ' . ($badge ? '<span class="badge" style="margin-left: 5px; color: #721c24; background-color: #f8d7da; padding: 2px 5px; border-radius: 5px;">Published</span>' : '') . '</td>';
                    } else {
                        echo '<td>' . esc_html($product_name) . '</td>';
                    }
                    echo '<td>' . esc_html($row->orders_count) . '</td>';
                    echo '<td>' . esc_html($row->percentage) . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';
            }

            echo '</div>';
        }

        public function assign_meta_for_product_with_low_ratio_of_paying_customers($args, $assoc_args)
        {
            $limit = $assoc_args['limit'];
            if (empty($limit)) {
                WP_CLI::error('Please provide --limit=<number>');
            }
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpc_paying_customers_stats';
            $product_ids = $wpdb->get_results("SELECT product_id FROM $table_name WHERE percentage < {$limit}", ARRAY_A);

            $i = 0;
            $count = $product_ids;
            foreach ($product_ids as $product_id) {
                update_psot_meta($product_id, '_wpc_low_paying_ratio', 'yes');
                WP_CLI::log($i . ' /' . count($count));
                $i++;
            }
            WP_CLI::success('All products with low paying ratio were updated.');


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

        public function get_wc_products_and_order_counts_with_percentage($start_date_interval, $end_date_interval) {
            global $wpdb;

            // Find products sold more than once in the given interval
            $query_products = $wpdb->prepare(
                "SELECT IFNULL(parent_meta.meta_value, itemmeta.meta_value) AS parent_id, 
                post_parent.post_title AS product_name, 
                COUNT(*) AS sale_count 
         FROM {$wpdb->prefix}woocommerce_order_items AS order_items 
         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta 
             ON order_items.order_item_id = itemmeta.order_item_id 
         INNER JOIN {$wpdb->prefix}posts AS posts 
             ON order_items.order_id = posts.ID 
         LEFT JOIN {$wpdb->prefix}postmeta AS parent_meta 
             ON itemmeta.meta_value = parent_meta.post_id AND parent_meta.meta_key = '_parent' 
         LEFT JOIN {$wpdb->prefix}posts AS post_parent 
             ON IFNULL(parent_meta.meta_value, itemmeta.meta_value) = post_parent.ID 
         WHERE posts.post_type = 'shop_order' 
             AND posts.post_status IN('wc-completed', 'wc-processing', 'wc-old') 
             AND itemmeta.meta_key = '_product_id' 
             AND posts.post_date BETWEEN %s AND %s 
         GROUP BY parent_id 
         HAVING sale_count > 0",
                $start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'
            );

            $products = $wpdb->get_results($query_products, ARRAY_A);
            $results = array();
            $all_products_count = count($products);
            WP_CLI::line('Found ' . $all_products_count . ' products.');

            // Pre-fetch all necessary data in one go to reduce query load
            $product_ids = array_column($products, 'parent_id');
            $product_ids_placeholder = implode(',', array_fill(0, count($product_ids), '%d'));
            $query_counts = $wpdb->prepare(
                "SELECT itemmeta.meta_value AS product_id, 
                posts.post_status, 
                COUNT(DISTINCT order_items.order_id) AS order_count 
         FROM {$wpdb->prefix}woocommerce_order_items AS order_items 
         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta 
             ON order_items.order_item_id = itemmeta.order_item_id 
         INNER JOIN {$wpdb->prefix}posts AS posts 
             ON order_items.order_id = posts.ID 
         WHERE posts.post_type = 'shop_order' 
             AND itemmeta.meta_key = '_product_id' 
             AND (itemmeta.meta_value IN ($product_ids_placeholder) 
                  OR itemmeta.meta_value IN (SELECT post_id 
                                             FROM {$wpdb->prefix}postmeta 
                                             WHERE meta_key = '_parent' 
                                             AND meta_value IN ($product_ids_placeholder))) 
             AND posts.post_date BETWEEN %s AND %s 
         GROUP BY itemmeta.meta_value, posts.post_status",
                array_merge($product_ids, $product_ids, [$start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'])
            );

            $counts = $wpdb->get_results($query_counts, ARRAY_A);

            // Organize counts by product and status
            $counts_by_product = [];
            foreach ($counts as $count) {
                $product_id = $count['product_id'];
                $post_status = $count['post_status'];
                if (!isset($counts_by_product[$product_id])) {
                    $counts_by_product[$product_id] = ['completed' => 0, 'other' => 0];
                }
                if (in_array($post_status, ['wc-completed', 'wc-processing', 'wc-old'])) {
                    $counts_by_product[$product_id]['completed'] += $count['order_count'];
                } else {
                    $counts_by_product[$product_id]['other'] += $count['order_count'];
                }
            }

            $i = 0;
            foreach ($products as $product) {
                $product_id = $product['parent_id'];
                $product_name = $product['product_name'];

                $count_completed = $counts_by_product[$product_id]['completed'] ?? 0;
                $count_other = $counts_by_product[$product_id]['other'] ?? 0;
                $total_orders = $count_completed + $count_other;
                $percentage_paid = ($total_orders > 0) ? ($count_completed / $total_orders) * 100 : 0;
                $interval = $start_date_interval . '-' . $end_date_interval;

                $this->insert_paying_customer_stat($product_id, number_format($percentage_paid, 2), $total_orders, $interval);
                WP_CLI::log($i . ' / ' . $all_products_count);
                $i++;
            }

            return $results;
        }


        public function _get_wc_products_and_order_counts_with_percentage($start_date_interval, $end_date_interval)
        {
            global $wpdb;

            // Najdeme produkty prodané více než jednou v zadaném intervalu
            $query_products = $wpdb->prepare(
                "
        SELECT
            IFNULL(parent_meta . meta_value, itemmeta . meta_value) as parent_id,
            post_parent . post_title as product_name,
            COUNT(*) as sale_count
        FROM {
            $wpdb->prefix}woocommerce_order_items as order_items
        INNER JOIN {
            $wpdb->prefix}woocommerce_order_itemmeta as itemmeta ON order_items . order_item_id = itemmeta . order_item_id
        INNER JOIN {
            $wpdb->prefix}posts as posts ON order_items . order_id = posts . ID
        LEFT JOIN {
            $wpdb->prefix}postmeta as parent_meta ON itemmeta . meta_value = parent_meta . post_id and parent_meta . meta_key = '_parent'
        LEFT JOIN {
            $wpdb->prefix}posts as post_parent ON IFNULL(parent_meta . meta_value, itemmeta . meta_value) = post_parent . ID
        WHERE posts . post_type = 'shop_order'
        and posts . post_status IN('wc-completed', 'wc-processing', 'wc-old')
        and itemmeta . meta_key = '_product_id'
        and posts . post_date BETWEEN % s and %s
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
            SELECT COUNT(DISTINCT order_items . order_id) as order_count
            FROM {
            $wpdb->prefix}woocommerce_order_items as order_items
            INNER JOIN {
            $wpdb->prefix}woocommerce_order_itemmeta as itemmeta ON order_items . order_item_id = itemmeta . order_item_id
            INNER JOIN {
            $wpdb->prefix}posts as posts ON order_items . order_id = posts . ID
            WHERE posts . post_type = 'shop_order'
        and posts . post_status IN('wc-completed', 'wc-processing', 'wc-old')
        and itemmeta . meta_key = '_product_id'
        and (itemmeta . meta_value = %d or itemmeta . meta_value IN(
            SELECT post_id FROM {
            $wpdb->prefix}postmeta WHERE meta_key = '_parent' and meta_value = %d
            ))
            and posts . post_date BETWEEN % s and %s
            ",
                    $product_id, $product_id, $start_date_interval . ' 00:00:00', $end_date_interval . ' 23:59:59'
                );

                $count_completed = $wpdb->get_var($query_completed);

                $query_other = $wpdb->prepare(
                    "
            SELECT COUNT(DISTINCT order_items . order_id) as order_count
            FROM {
            $wpdb->prefix}woocommerce_order_items as order_items
            INNER JOIN {
            $wpdb->prefix}woocommerce_order_itemmeta as itemmeta ON order_items . order_item_id = itemmeta . order_item_id
            INNER JOIN {
            $wpdb->prefix}posts as posts ON order_items . order_id = posts . ID
            WHERE posts . post_type = 'shop_order'
        and posts . post_status NOT IN('wc-completed', 'wc-processing', 'wc-old')
        and itemmeta . meta_key = '_product_id'
        and (itemmeta . meta_value = %d or itemmeta . meta_value IN(
            SELECT post_id FROM {
            $wpdb->prefix}postmeta WHERE meta_key = '_parent' and meta_value = %d
            ))
            and posts . post_date BETWEEN % s and %s
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
          PRIMARY KEY(id)
        ) $charset_collate;";


                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                $wpdb->query($sql);

                if ($wpdb->last_error) {
                    WP_CLI::error("Error creating table: " . $wpdb->last_error);
                } else {
                    WP_CLI::success("Table '$table_name' created successfully . ");
                }
            } else {
                WP_CLI::success("Table '$table_name' already exists . ");
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
