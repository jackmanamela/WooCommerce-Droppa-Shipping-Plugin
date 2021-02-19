<?php
# The maximum execution time, in seconds. If set to zero, no time limit is imposed.
set_time_limit(0);

# Make sure to keep alive the script when a client disconnect.
ignore_user_abort(true);

if (!defined('ABSPATH')) {
    exit;
}

require_once('vendor/autoload.php');

class DroppaShippingMethod extends WC_Shipping_Method
{
    public $total_cost;
    public $_bookingConfirmedOrderID;
    public $_api_key_endpoint;
    public $_service_id_endpoint;
    public $curl_response;

    public function __construct($instance_id = 0)
    {
        $this->id                 = 'droppa_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('Droppa Shipping');
        $this->method_description = __('Droppa Shipping');
        $this->supports           = array(
            'zones',
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->enabled              = 'yes';
        $this->last_response        = array();

        if ($instance_id == 0) return;

        \Dotenv\Dotenv::createImmutable(__DIR__, '.env')->load();

        $this->init();
    }

    public function init()
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->title              = $this->get_option('title', __('Label', 'droppa_shipping'));
        $this->method_title       = $this->get_option('Droppa Shipping');
        $this->description        = $this->get_option('description');
        $this->method_description = $this->description;

        add_filter('_no_shipping_available_html', array($this, 'filter_cart_no_shipping_available_html'), 10, 1);
        add_action('woocommerce_proceed_to_checkout', array($this, 'action_add_text_before_proceed_to_checkout'));
        add_action('woocommerce_proceed_to_checkout', array($this, 'maybe_clear_wc_shipping_rates_cache'));

        add_filter('woocommerce_cart_ready_to_calc_shipping', [$this, 'disable_shipping_calc_on_cart'], 99);

        add_action('woocommerce_checkout_order_processed', [$this, 'is_courier_delivery'], 1, 1);
    }
    /**
     * @description - Creates booking
     * @param $package Array of products and their variants
     * @param $response plugin/quotes api from the [calculate_shipping] method
     */
    function is_courier_delivery($order_id)
    {
        $order = new WC_Order($order_id);
        # Product Order Attributes
        $order_data = [
            'customer_id'       => $order->get_customer_id(),
            'order_id'          => $order->get_id(),
            'order_number'      => $order->get_order_number(),
            'order_total'       => wc_format_decimal($order->get_total(), 2),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_company'   => $order->get_billing_company(),
            'billing_email'     => $order->get_billing_email(),
            'billing_phone'     => $order->get_billing_phone(),
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_postcode'  => $order->get_billing_postcode(),
            'billing_city'      => $order->get_billing_city(),
            'billing_state'     => $order->get_billing_state(),
            'billing_country'   => $order->get_billing_country(),
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name' => $order->get_shipping_last_name(),
            'shipping_company'   => $order->get_shipping_company(),
            'shipping_address_1' => $order->get_shipping_address_1(),
            'shipping_address_2' => $order->get_shipping_address_2(),
            'shipping_postcode'  => $order->get_shipping_postcode(),
            'shipping_city'     => $order->get_shipping_city(),
            'shipping_state'    => $order->get_shipping_state(),
            'shipping_country'  => $order->get_shipping_country(),
            'customer_note'     => $order->get_customer_note()
        ];

        # Get and Loop Over Order Items
        foreach ($order->get_items() as $item_id => $item) {
            $product = json_decode($item->get_product(), true);

            // $product_description = $product['description'];
            $product_length = (int) $product['length'];
            $product_width  = (int) $product['width'];
            $product_height = (int) $product['height'];
            $product_weight = (int) $product['weight'];

            $_bookingDimensions[] = $this->booking_plugin_attributes($product_length, $product_width, $product_height, $product_weight);
        }


        switch (WC()->countries->get_base_state()):
            case 'EC':
                $pickUpProvince = "EASTERN_CAPE";
                break;

            case 'FS':
                $pickUpProvince = "FREE_STATE";
                break;

            case 'GP':
                $pickUpProvince = "GAUTENG";
                break;

            case 'KZN':
                $pickUpProvince = "KWA_ZULU_NATAL";
                break;

            case 'LP':
                $pickUpProvince = "LIMPOPO";
                break;

            case 'MP':
                $pickUpProvince = "MPUMALANGA";
                break;

            case 'NC':
                $pickUpProvince = "NORTHERN_CAPE";
                break;

            case 'NW':
                $pickUpProvince = "NORTHERN_WEST";
                break;

            case 'WC':
                $pickUpProvince = "WESTERN_CAPE";
                break;

        endswitch;

        $_get_dropOffProvice = $order_data['billing_state'] ?? $order_data['shipping_state'];

        switch ($_get_dropOffProvice):
            case 'EC':
                $dropOffProvince = "EASTERN_CAPE";
                break;

            case 'FS':
                $dropOffProvince = "FREE_STATE";
                break;

            case 'GP':
                $dropOffProvince = "GAUTENG";
                break;

            case 'KZN':
                $dropOffProvince = "KWA_ZULU_NATAL";
                break;

            case 'LP':
                $dropOffProvince = "LIMPOPO";
                break;

            case 'MP':
                $dropOffProvince = "MPUMALANGA";
                break;

            case 'NC':
                $dropOffProvince = "NORTHERN_CAPE";
                break;

            case 'NW':
                $dropOffProvince = "NORTHERN_WEST";
                break;

            case 'WC':
                $dropOffProvince = "WESTERN_CAPE";
                break;

        endswitch;

        $_postcode      = $order_data['shipping_postcode'] ? $order_data['shipping_postcode'] : $order_data['billing_postcode'];
        $_city          = $order_data['shipping_city'] ? $order_data['shipping_city'] : $order_data['billing_city'];
        $_address_1     = $order_data['shipping_address_1'] ? $order_data['shipping_address_1'] : $order_data['billing_address_1'];
        $_company       = $order_data['shipping_company'] ? $order_data['shipping_company'] : $order_data['billing_company'];
        $_address_2_unit    = $order_data['shipping_address_2'] ? $order_data['shipping_address_2'] : $order_data['billing_address_2'];
        $_first_name        = $order_data['shipping_first_name'] ? $order_data['shipping_first_name'] : $order_data['billing_first_name'];
        $_last_name         = $order_data['shipping_last_name'] ? $order_data['shipping_last_name'] : $order_data['billing_last_name'];
        $_phone             = $order_data['billing_phone'] ? $order_data['billing_phone'] : $order_data['shipping_phone'];
        $customer_note      = $order_data['customer_note'];
        $_comapny_name      = get_bloginfo('name');

        # POST Body
        $create_plugin_booking_service = [
            'serviceId'         => $_ENV['PROD_RETAIL_SERVICE_ID'],
            'pickUpPCode'       => WC()->countries->get_base_postcode(),
            'dropOffPCode'      => $_postcode,
            'fromSuburb'        => WC()->countries->get_base_city(),
            'toSuburb'          => $_city,
            'province'           => $pickUpProvince,
            'destinationProvince'   => $dropOffProvince,
            'pickUpAddress'         => WC()->countries->get_base_address(),
            'dropOffAddress'        => $_address_1,
            'pickUpCompanyName'     => $_comapny_name,
            'dropOffCompanyName'    => $_company,
            'pickUpUnitNo'          => WC()->countries->get_base_address_2(),
            'dropOffUnitNo'         => $_address_2_unit,
            'customerName'          => $_first_name . ' ' . $_last_name,
            'customerPhone'         => $_phone ? $_phone : $this->get_shipping_phone_number($_phone),
            'customerEmail'         => $order->get_billing_email(),
            'instructions'          => $customer_note ? $customer_note : '',
            'price'                 => $this->total_cost,
            'parcelDimensions'      => $_bookingDimensions,
            'storeName'             => ''
        ];

        # UAT
        $this->curl_response = $this->curl_endpoint($_ENV['PROD_BOOKINGS_SERVICE'], $create_plugin_booking_service, 'POST');

        $response_status_code = wp_remote_retrieve_response_code($this->curl_response);

        if (!is_array($this->curl_response) && is_wp_error($this->curl_response)) return $this->curl_response->get_error_message();

        if (200 >= $response_status_code || 308 <= $response_status_code) {

            foreach ((array) $this->curl_response['body'] as $value) :
                $results = json_decode($value, true);

                if ((float) $results['price'] >= '05.00') {
                    session_start();

                    $this->_bookingConfirmedOrderID = $results['oid'];

                    $_SESSION['return_booking_object_ID'] = $this->_bookingConfirmedOrderID;

                    return $_SESSION['return_booking_object_ID'];
                }

            endforeach;
        }
    }
    /**
     * @Description         Gets an Array of the product's attributes
     * @param $_length      Item Length
     * @param $_breadth     Item Width
     * @param $_height      Item Height
     * @param $_mass        Item Weight
     */
    public function booking_plugin_attributes($_length, $_breadth, $_height, $_mass)
    {
        (array) $parcels = new Parcel($_mass, $_breadth, $_height, $_length);

        $_dimensions = [
            "parcel_length"     => $parcels->get_length(),
            "parcel_breadth"    => $parcels->get_width(),
            "parcel_height"     => $parcels->get_height(),
            "parcel_mass"       => $parcels->get_itemMass()
        ];

        if (is_array($_dimensions)) return $_dimensions;
    }
    /**
     * @Description             - Gets the custom shipping phone number from the UserMeta table
     * @param $_current_user_id - Gets the current logged in User
     * @return $results         - Returns the User's shipping number
     */
    public function get_shipping_phone_number($_current_user_number)
    {
        global $wpdb;

        $_user_billing_number = (string) $_current_user_number ? (string) $_current_user_number : strval($_current_user_number);

        if ('' != $_user_billing_number) $_billing_number = $wpdb->get_results($wpdb->prepare('SELECT meta_value FROM wp_postmeta WHERE meta_value = %s', $_user_billing_number), ARRAY_A);

        return $_billing_number[0]['meta_value'];
    }
    /**
     * @description     Hide the shipping calculation method on the Cart Page
     * @param           $show_shipping - Checks if the user is on the Cart Page
     * @return          $show_shipping - Returns false once on the Cart Page
     */
    public function disable_shipping_calc_on_cart($show_shipping)
    {
        if (is_cart()) return false;

        return $show_shipping;
    }

    public function maybe_clear_wc_shipping_rates_cache()
    {
        if ($this->get_option('clear_wc_shipping_cache') == 'yes') {
            $packages = WC()->cart->get_shipping_packages();
            foreach ($packages as $key => $value) {
                $shipping_session = "shipping_for_package_$key";
                unset(WC()->session->$shipping_session);
            }
        }
    }

    public function action_add_text_before_proceed_to_checkout()
    {
        echo $this->last_response['before_checkout_button_html'];
    }
    /**
     * @descriptio  - There are no shipping methods available. Please double check your address
     */
    public function filter_cart_no_shipping_available_html($previous)
    {
        return $previous . $this->last_response['cart_no_shipping_available_html'];
    }

    public function get_option($key, $empty_value = null)
    {
        // Instance options take priority over global options
        if (in_array($key, array_keys($this->instance_form_fields))) {
            return $this->get_instance_option($key, $empty_value);
        }

        // Return global option
        return parent::get_option($key, $empty_value);
    }

    public function get_instance_option($key, $empty_value = null)
    {
        if (empty($this->instance_settings)) {
            $this->init_instance_settings();
        }

        // Get option default if unset.
        if (!isset($this->instance_settings[$key])) {
            $form_fields = $this->instance_form_fields;

            if (is_callable(array($this, 'get_field_default'))) {
                $this->instance_settings[$key] = $this->get_field_default($form_fields[$key]);
            } else {
                $this->instance_settings[$key] = empty($form_fields[$key]['default']) ? '' : $form_fields[$key]['default'];
            }
        }

        if (!is_null($empty_value) && '' === $this->instance_settings[$key]) {
            $this->instance_settings[$key] = $empty_value;
        }

        return $this->instance_settings[$key];
    }

    public function get_instance_option_key()
    {
        return $this->instance_id ? $this->plugin_id . $this->id . '_' . $this->instance_id . '_settings' : '';
    }

    public function init_instance_settings()
    {
        // 2nd option is for BW compat
        $this->instance_settings = get_option($this->get_instance_option_key(), get_option($this->plugin_id . $this->id . '-' . $this->instance_id . '_settings', null));

        // If there are no settings defined, use defaults.
        if (!is_array($this->instance_settings)) {
            $form_fields             = $this->get_instance_form_fields();
            $this->instance_settings = array_merge(array_fill_keys(array_keys($form_fields), ''), wp_list_pluck($form_fields, 'default'));
        }
    }

    public function init_form_fields()
    {
        $this->form_fields     = array(); // No global options for table rates
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __('Method Title', 'woocommerce-droppa-shipping'),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-droppa-shipping'),
                'default'     => __('Droppa shipping', 'woocommerce-droppa-shipping')
            ),
            'description' => array(
                'title'       => __('Method Description', 'woocommerce-droppa-shipping'),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-droppa-shipping'),
                'default'     => __('Droppa ships everywhere across ZAR', 'woocommerce-droppa-shipping')
            ),
            'store_Api_key_endpoint' => array(
                'title'         => __('API Endpoint for the store', 'woocommerce-droppa-shipping'),
                'type'          => 'text',
                'desc_tip'      => true,
                'description'   => __('This service API key allows the plugin to integrate with the Droppa retail store.', 'woocommerce-droppa-shipping'),
                'placeholder'   => __('API KEY generated from Droppa Admin', 'woocommerce-droppa-shipping'),
                'default'       => ''
            ),
            'store_Service_Key_endpoint' => array(
                'title'         => __('Service ID for the store', 'woocommerce-droppa-shipping'),
                'type'          => 'text',
                'desc_tip'      => true,
                'description'   => __('This service ID key allows the plugin to integrate with the Droppa retail store.', 'woocommerce-droppa-shipping'),
                'placeholder'   => __('Service ID generated from Droppa Admin', 'woocommerce-droppa-shipping'),
                'default'       => ''
            ),
            'fallback_cost' => array(
                'title'       => __('Fallback cost', 'woocommerce-droppa-shipping'),
                'type'        => 'text',
                'desc_tip'    => true,
                'default'     => '0',
                'description' => __('Use this shipping cost when service unavailable', 'woocommerce-droppa-shipping'),
            ),
            'debug' => array(
                'title'       => __('Debug', 'woocommerce-droppa-shipping'),
                'label'       => ' ',
                'type'        => 'checkbox',
                'default'     => 'yes',
                'description' => __('Set a "debug": "yes" flag in the JSON sent to the service.', 'woocommerce-droppa-shipping'),
            ),
            'clear_wc_shipping_cache' => array(
                'title'       => __('Disable Shipping Cache', 'woocommerce-droppa-shipping'),
                'label'       => ' ',
                'type'        => 'checkbox',
                'default'     => 'no',
                'description' => __("Clear WooCommerce's session-based shipping calculation cache at every load.", 'woocommerce-droppa-shipping'),
            ),
        );
    }
    /**
     * @description     - Method override [public function calculate_shipping( $package = array() ) {}]
     * @param $package  - Array package for the products variants
     * @return $rate    - Returns the quotation amount
     */
    public function calculate_shipping($package = array())
    {
        // prepare a JSON object to be sent to the costing endpoint
        foreach ($package['contents'] as $item_id => $values) {
            $_product = $values['data'];
            $class_slug = $_product->get_shipping_class();
            $package['contents'][$item_id]['shipping_class_slug'] = $class_slug;

            // collect category slugs
            $catids = $_product->get_category_ids();
            $catslugs = array();
            foreach ($catids as $catid) {
                $cat = get_category($catid);
                // array_push($catslugs, $cat->slug);
            }
            // collect product attributes
            $attrs = array();

            foreach ($_product->get_attributes() as $att) {
                if (is_object($att)) { // of class WC_Product_Attribute
                    $terms = $att->get_terms();
                    if ($terms) {
                        // This is a woocommerce predefined product attribute (Menu: WooCommerce -> Attributes)
                        $termvalues = array();
                        foreach ($terms as $term) {
                            array_push($termvalues, $term->name);
                        }
                        $attrs[$att->get_name()] = $termvalues;
                    } else {
                        // This is a woocommerce custom product attribute
                        $attrs[$att->get_name()] = $att->get_options();
                    }
                } else {
                    // for variations, attributes are strings
                    array_push($attrs, $att);
                }
            }

            $package['contents'][$item_id]['categories'] = $catslugs;
            $package['contents'][$item_id]['attributes'] = $attrs;
            // $package['contents'][$item_id]['name'] = $_product->name;
            // $package['contents'][$item_id]['sku'] = $_product->sku;
            $package['contents'][$item_id]['dimensions'] = $_product->get_dimensions(false);
            $package['contents'][$item_id]['purchase_note'] = $_product->get_purchase_note();
            $package['contents'][$item_id]['weight'] = $_product->get_weight();
            $package['contents'][$item_id]['downloadable'] = $_product->get_downloadable();
            $package['contents'][$item_id]['virtual'] = $_product->get_virtual();
        }

        $package['site']['locale'] = get_locale();
        $package['shipping_method']['instance_id'] = $this->instance_id;
        $package['debug'] = $this->get_option('debug');
        # --------------------------------------------------------------------------------------------
        if (!is_array($package['destination'])) return trigger_error('The destination set from the Array Package is not available.', E_USER_ERROR);

        $pickUpPCode        = WC()->countries->get_base_postcode();
        $dropOffPCode       = $package['destination']['postcode'];

        foreach ($package['contents'] as $keys => $values) {
            # mass
            $weight                 = (int) $values['weight'];

            $_fullDimensionContents = [
                'parcel_length'     => (int) $values['dimensions']['length'],
                'parcel_breadth'    => (int) $values['dimensions']['width'],
                'parcel_height'     => (int) $values['dimensions']['height'],
                'parcel_mass'       => (int) $weight,
            ];
        }

        if ('yes' !== $package['debug']) return false;

        $endpoint = $_ENV['PROD_QUOTES_SERVICE'];

        $calculate_distance_for_quotes = $this->quotes_plugin_attributes($pickUpPCode, $dropOffPCode, $weight, $_fullDimensionContents);

        $this->curl_response = $this->curl_endpoint($endpoint, $calculate_distance_for_quotes, 'POST');

        $response_status_code = wp_remote_retrieve_response_code($this->curl_response);

        if (!is_array($this->curl_response) && is_wp_error($this->curl_response)) return $this->curl_response->get_error_message();

        if (200 >= $response_status_code || 308 <= $response_status_code) {

            foreach ((array) $this->curl_response['body'] as $value) :
                $responseVar = json_decode($value, true);
            endforeach;
        }

        $this->total_cost = (float) $responseVar['amount'];

        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $rate = array(
                    'id' => $this->id,
                    'label' => $this->title,
                    'cost' => $this->total_cost,
                    'price_decimals' => wc_get_price_decimals(),
                );

                $this->add_rate($rate);

                break;
        }
        # --------------------------------------------------------------------------------------------
    }
    /**
     * @Description                             Gets the products attributes to generate a quotation
     * @param $_pickUpPCode                     Pick up postal code
     * @param $_dropOffPCode                    Drop off postal code
     * @param $_product_total_mass              Product weight
     * @return $calculate_distance_for_quotes   Array
     */
    public function quotes_plugin_attributes($_pickUpPCode, $_dropOffPCode, $_product_total_mass, $_parcelDimensionsArrayHolder)
    {
        (array) $_get_product_attributes = new Quotes($_pickUpPCode, $_dropOffPCode, $_product_total_mass);

        $calculate_distance_for_quotes = [
            "pickUpCode"                 => $_get_product_attributes->get_pickUpCode(),
            "dropOffCode"                => $_get_product_attributes->get_dropOffCode(),
            "mass"                       => $_get_product_attributes->get_weight(),
            "parcelDimensions"           => [$_parcelDimensionsArrayHolder],
            "storeName"                  => ''
        ];

        if (is_array($calculate_distance_for_quotes)) return $calculate_distance_for_quotes;
    }
    /**
     * @description             - Create a quote through the plugins.quote service
     * @param $endpoint         - HTTP API
     * @param $body             - HTML body being passed from the form
     * @param $method           - Optional HTTP Method
     * @res
     */
    public function curl_endpoint($endpoint, $body, $method = 'GET')
    {
        $curl_options = [
            'method'        => $method,
            'headers'       => [
                "Content-Type"  => "application/json",
                "Connection"    => "Keep-Alive",
                "Accept"        => "application/json",
                "Authorization" => "Bearer {$_ENV['PROD_RETAIL_API_KEY']}:{$_ENV['PROD_RETAIL_SERVICE_ID']}"
            ],
            'body'          => wp_json_encode($body),
            'blocking'      => true,
            'timeout'       => 60,
            'redirection'   => 5,
            'httpversion'   => '1.0',
            'data_format'   => 'body',
            'sslverify'     => true
        ];

        return wp_remote_post($endpoint, $curl_options);
    }
}

class Parcel
{
    public $itemMass;
    public $width;
    public $height;
    public $length;
    public $description;

    function __construct($itemMass, $width, $height, $length)
    {
        $this->itemMass = $itemMass;
        $this->width = $width;
        $this->height = $height;
        $this->length = $length;
    }

    function get_itemMass()
    {
        return $this->itemMass;
    }

    function get_width()
    {
        return $this->width;
    }

    function get_height()
    {
        return $this->height;
    }

    function get_length()
    {
        return $this->length;
    }
}

class Quotes
{
    public $pickUpPCode;
    public $dropOffPCode;
    public $weight;

    public function __construct($pickUpPCode, $dropOffPCode, $weight)
    {
        $this->pickUpPCode      = $pickUpPCode;
        $this->dropOffPCode     = $dropOffPCode;
        $this->weight           = $weight;
    }

    function get_pickUpCode()
    {
        return $this->pickUpPCode;
    }

    function get_dropOffCode()
    {
        return $this->dropOffPCode;
    }

    function get_weight()
    {
        return $this->weight;
    }
}