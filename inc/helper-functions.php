<?php

if (!defined('ABSPATH')) exit;

// Example function
function pzm_get_zone_by_pincode($pincode) {
    global $wpdb;

    $table = $wpdb->prefix . 'pincode_zones';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE delivery_pincode = %s LIMIT 1",
            $pincode
        )
    );
}

function pzm_get_rate_by_pincode($pincode) {

    global $wpdb;

    $rates_table = $wpdb->prefix . 'pincode_zone_rates';

    // Get zone row
    $zone_row = pzm_get_zone_by_pincode($pincode);

    if (!$zone_row) {
        return null; // or 0
    }

    // Get rate safely
    $rate = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT rate FROM $rates_table WHERE zone = %s",
            $zone_row->zone
        )
    );

    return $rate;
}