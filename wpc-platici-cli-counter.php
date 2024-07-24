<?php
/**
 * Plugin Name: WPC Paying customer data for WP CLI
 * Description: Count paying customers for a product and store the data in a database table. This plugin is intended to be used with WP CLI.
 * Version: 1.1
 * Author: kosarlukascz */



if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
include_once('includes/wpc-platici-cli-counter.php');
add_action('plugins_loaded', function () {
    wpc_platici::get_instance();
});
