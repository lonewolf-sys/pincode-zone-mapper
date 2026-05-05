<?php

if (!defined('ABSPATH')) exit;


/* Code for shipping rates and Cash on delivery method */

add_filter('woocommerce_package_rates', 'custom_shipping_rates', 20, 2);

function custom_shipping_rates($rates, $package)
{
    $free_shipping_min_amounts = get_free_shipping_min_amount();
    $total = WC()->cart->get_cart_contents_total();  // Gives total with cart amount and discount

    $pincode = $package['destination']['postcode'];

    if (!$pincode)
        return [];  //Return an error if pincode field is empty

    $pincode_rate = pzm_get_rate_by_pincode($pincode); // Get pincode rate stored in table by using pincode

    if (!$pincode_rate) {
        return [];  //Return an error if pincode is not serviceable.
    }

    if ($total >= $free_shipping_min_amounts[0]) {  //Check if free shipping is enabled.
        $rate = new WC_Shipping_Rate(
            'free_shipping',  // unique ID
            'Free shipping',  // label
            0,  // cost
            array(),  // taxes
            'free_shipping'  // method ID
        );
    } else {
        // Create new shipping rate
        $rate = new WC_Shipping_Rate(
            'custom_pincode_rate',  // unique ID
            'Delivery Charge',  // label
            $pincode_rate,  // cost
            array(),  // taxes
            'custom_shipping'  // method ID
        );
    }
    $rates = [];

    // Add your custom rate
    $rates['custom_pincode_rate'] = $rate;

    return $rates; //return the rates.
}

add_action('woocommerce_cart_calculate_fees', 'add_cod_extra_charge');

/***********************ADD COD CHARGE ON CHECKOUT IF APPLICABLE**********************/
function add_cod_extra_charge($cart)
{
    if (is_admin() && !defined('DOING_AJAX'))
        return;

    // Get chosen payment method
    $chosen_payment_method = WC()->session->get('chosen_payment_method');

    if ($chosen_payment_method === 'cod') {
        $cod_fee = 50;  // change this amount

        $cart->add_fee('Cash on Delivery Charge', $cod_fee, false);
    }
}
/*********************END *****************************/