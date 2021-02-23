<?php

/**
 * Plugin Name: Droppa Shipping
 * Plugin URI: https://droppa.co.za/droppa/woocommerce-shipping-plugin
 * Description: Goods Delivery Plugin
 * Version: 0.0.1
 * Author: Nathi Khanyile & Jack Manamela
 * Author URI: https://profiles.wordpress.org/droppagroup/
 * Requires at least: 5.6.0
 * Tested up to: 5.6.2
 * Requires PHP: 7.0
 * WC requires at least: 3.0
 * WC tested up to: 4.8
 * Copyright: 2020 Droppa
 * License: MIT
 * License URI: https://droppa/licenses/MIT
 */

//prevent execution outside woocommerce
if (!defined('ABSPATH')) {
    exit;
}

$filters = apply_filters('active_plugins', get_option('active_plugins'));
if (in_array('woocommerce/woocommerce.php', $filters)) {

    class DroppaShipping
    {
        public function __construct()
        {
            define('DROPPA_DELIVERY_VERSION', '0.0.1');
            define('WPS_DEBUG_DOM', true);

            define('DROPPA_DELIVERY_VERSION_DEBUG', defined('WP_DEBUG') &&
                'true' == WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || 'true' == WP_DEBUG_DISPLAY));
            add_action('plugins_loaded', array($this, 'init'));
        }

        public function add_to_shipping_methods($shipping_methods)
        {
            $shipping_methods['droppa_shipping'] = 'DroppaShippingMethod';
            return $shipping_methods;
        }

        public function init()
        {
            add_filter('woocommerce_shipping_methods', array($this, 'add_to_shipping_methods'));
            add_action('woocommerce_shipping_init', array($this, 'shipping_init'));
            add_action('init', array($this, 'load_plugin_textdomain'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 100);
        }

        public function enqueue_scripts()
        {
            wp_enqueue_style('woocommerce-droppa-shipping-style', plugins_url('/assets/css/style.css', __FILE__));
            wp_enqueue_script('woocommerce-droppa-shipping-script', plugins_url('/assets/js/main.js', __FILE__));
        }

        public function load_plugin_textdomain()
        {
            load_plugin_textdomain('woocommerce-droppa-delivery', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        }

        public function shipping_init()
        {
            include_once('includes/droppa-shipping-method.php');
            include_once('includes/functions.php');
        }
    }

    new DroppaShipping();
}
