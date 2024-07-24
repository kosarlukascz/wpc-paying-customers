# WPC Paying Customer Data for WP CLI

## Description
The "WPC Paying Customer Data for WP CLI" plugin counts paying customers for a product and stores the data in a database table. This plugin is intended to be used with WP CLI (WordPress Command Line Interface).

## Installation

1. **Ensure WP CLI is installed and configured on your WordPress setup.** If not, refer to the [WP CLI documentation](https://wp-cli.org/) for installation instructions.

2. **Download the plugin files.**

3. **Upload the plugin files to the `/wp-content/plugins/wpc-paying-customer-data/` directory.**

4. **Activate the plugin through the 'Plugins' menu in WordPress** or using WP CLI:
```
wp plugin activate wpc-paying-customer-data
```

## Usage 

This plugin provides several WP CLI commands to manage and retrieve paying customer data. The available commands are:

### 1. Install Plugin 

To create the necessary database table, use the following command:


```
wp wpc-paying-install
```

### 2. Count Paying Customers 

To count the paying customers for a specific date range, use the following command:


```
wp wpc-paying-count --start_date=<yyyy-mm-dd> --end_date=<yyyy-mm-dd>
```
 
- `start_date`: The start date of the interval.
 
- `end_date`: The end date of the interval.

Example:


```
wp wpc-paying-count --start_date=2024-07-15 --end_date=2024-07-23
```

### 3. Remove All Data 

To remove all data from the plugin's database table, use the following command:


```
wp wpc-paying-remove-all-data
```

## Plugin Functions 
`count_platici_cli($args, $assoc_args)`
This function counts paying customers for the given date range and stores the results in the database.

`remove_all_data()`This function removes all data from the `wpc_paying_customers_stats` database table.

`get_wc_products_and_order_counts_with_percentage($start_date_interval, $end_date_interval)`
This function retrieves WooCommerce products and their order counts within the specified date range.

`install_plugin()`
This function creates the necessary database table for storing paying customer data.

`insert_paying_customer_stat($product_id, $percentage, $orders_count, $interval)`
This function inserts a new record into the paying customer statistics table.

`get_paying_customer_stats_by_interval($interval)`
This function retrieves paying customer statistics for a specific interval.

`update_paying_customer_stat($id, $product_id, $percentage, $orders_count, $interval)`
This function updates an existing record in the paying customer statistics table.

## Singleton Instance 
The plugin uses a singleton pattern to ensure only one instance of the `wpc_platici` class is created.

```php
public static function get_instance()
{
    if (self::$instance === null) {
        self::$instance = new self;
    }

    return self::$instance;
}
```

## Conclusion 

The "WPC Paying Customer Data for WP CLI" plugin is a powerful tool for managing and analyzing paying customer data within your WordPress and WooCommerce setup using WP CLI. Ensure WP CLI is properly installed and configured to utilize the full capabilities of this plugin.
