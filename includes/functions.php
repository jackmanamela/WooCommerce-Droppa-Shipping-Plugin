<?php

/**
 * WooCommerce Droppa Plugin functions and definitions file
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Droppa
 */

require_once('vendor/autoload.php');

\Dotenv\Dotenv::createImmutable(__DIR__, '.env')->load();

# WC_Checkout shipping phone
add_filter('woocommerce_checkout_fields', 'add_shipping_phone_checkout');

add_action('woocommerce_review_order_before_payment', 'review_order_before_payment', 10);
# WC_Order_Note_Action
add_action('woocommerce_order_actions', 'sv_wc_add_order_meta_box_action') . 10;
add_action('woocommerce_order_action_wc_custom_order_action', 'sv_wc_process_order_meta_box_action', 10);
# Instantiate the Droppa Object
add_filter('woocommerce_after_order_details', 'after_order_details', 1);
# Hide shipping on cart
add_filter('woocommerce_cart_ready_to_calc_shipping', 'disable_shipping_calc_on_cart', 99);
# Remove Total amount on Cart Page
remove_action('woocommerce_cart_collaterals', 'woocommerce_cart_totals', 10);
# ----------------------------------------------------------------------------------------------------------
function after_order_details($order)
{
    session_start();

    if ($order->get_id()) {
        $_droppaPluginInstance = new  DroppaShippingMethod();
        if ($_droppaPluginInstance instanceof DroppaShippingMethod)
            if (isset($_SESSION['return_booking_object_ID'])) {
                $response = $_droppaPluginInstance->curl_endpoint($_ENV['UAT_CONFIRM_PAYMENT_SERVEICE'] . $_SESSION['return_booking_object_ID'], '', 'POST');
                return $response;
            } else {
                unset($_SESSION['return_booking_object_ID']);
                session_destroy();
            }
    }
}
# ----------------------------------------------------------------------------------------------------------

/**
 * @snippet -   Add a heading to the Checkout page on Payments
 */
function review_order_before_payment()
{
    echo '<h3>' . esc_html__('Payment methods') . '</h3>';
}

function sv_wc_add_order_meta_box_action($actions)
{
    global $theorder;

    // bail if the order has been paid for or this action has been run
    if (!$theorder->is_paid() || get_post_meta($theorder->id, '_wc_order_marked_printed_for_packaging', true)) {
        return $actions;
    }

    // add "mark printed" custom action
    $actions['wc_custom_order_action'] = __('Dispatch To Droppa', 'my-textdomain');
    return $actions;
}

function sv_wc_process_order_meta_box_action($order)
{
    $message = sprintf(__('Order information updated by %s for Droppa Group.', 'my-textdomain'), wp_get_current_user()->display_name);
    $order->add_order_note($message);

    update_post_meta($order->id, '_wc_order_marked_printed_for_packaging', 'yes');
}
/**
 * @snippet -   Hide shipping on cart
 */
function disable_shipping_calc_on_cart($show_shipping)
{
    if (is_cart()) {
        return false;
    }
    return $show_shipping;
}
/**
 * @snippet - Shipping Phone to the Checkout page
 */
function add_shipping_phone_checkout($fields)
{
    $fields['shipping']['shipping_phone'] = [
        'label' => 'Phone',
        'required' => false,
        'class' => ['form-row-wide'],
        'priority' => 90,
    ];
    return $fields;
}