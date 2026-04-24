<?php
/*
Plugin Name: Pincode Zone Mapper
Description: Upload Excel/CSV and store pincode zone mapping
Version: 1.0
Author: You
*/

if (!defined('ABSPATH')) exit;

// Create table on activation
function pzm_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pincode_zones';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    delivery_city VARCHAR(50),
    delivery_pincode VARCHAR(7) NOT NULL,
    courier VARCHAR(40),
    zone VARCHAR(3),
    UNIQUE KEY unique_pincode (delivery_pincode),
    INDEX idx_pincode (delivery_pincode),
    INDEX idx_zone (zone)
) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'pzm_create_table');

// Add menu
function pzm_menu() {
    add_menu_page(
        'Pincode Upload',
        'Pincode Zones',
        'manage_options',
        'pincode-zones',
        'pzm_upload_page'
    );
}
add_action('admin_menu', 'pzm_menu');

// Upload page
function pzm_upload_page() {
    ?>
    <div class="wrap">
        <h2>Upload Pincode CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" required />
            <input type="submit" name="upload" class="button button-primary" value="Upload">
        </form>
    </div>
    <?php

    if (isset($_POST['upload'])) {
        pzm_handle_upload();
    }
}

// Handle file upload
function pzm_handle_upload() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pincode_zones';

    if ($_FILES['csv_file']['error'] == 0) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');

        // Skip header
        fgetcsv($file);

        $values = [];
$batch_size = 1000;

while (($row = fgetcsv($file)) !== FALSE) {
    $values[] = $wpdb->prepare("(%s,%s,%s,%s)",
        $row[0], $row[1], $row[2], substr($row[3], 0, 3)
    );

    if (count($values) >= $batch_size) {
        $wpdb->query("INSERT INTO $table_name 
        (delivery_city, delivery_pincode, courier, zone) 
        VALUES " . implode(',', $values));
        
        $values = [];
    }
}

// Insert remaining
if (!empty($values)) {
    $wpdb->query("INSERT INTO $table_name 
    (delivery_city, delivery_pincode, courier, zone) 
    VALUES " . implode(',', $values));
}

        fclose($file);

        echo "<div class='updated'><p>Upload successful!</p></div>";
    }
}