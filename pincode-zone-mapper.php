<?php

/*
 * Plugin Name: Pincode Zone Mapper
 * Description: Upload Excel/CSV and store pincode zone mapping
 * Version: 1.0
 * Author: You
 */

if (!defined('ABSPATH'))
    exit;

require_once plugin_dir_path(__FILE__) . 'inc/helper-functions.php';
require_once plugin_dir_path(__FILE__) . 'inc/shipping-rates.php';

// Create table on activation
function pzm_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pincode_zones';
    $rates_table = $wpdb->prefix . 'pincode_zone_rates';

    $charset_collate = $wpdb->get_charset_collate();

    require_once (ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE $table_name (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    delivery_city VARCHAR(50),
    delivery_pincode VARCHAR(7) NOT NULL,
    courier VARCHAR(40),
    zone VARCHAR(3),
    INDEX idx_pincode (delivery_pincode),
    INDEX idx_zone (zone)
) $charset_collate;";

    $sql2 = "CREATE TABLE $rates_table (
    zone VARCHAR(3) UNIQUE,
    rate DECIMAL(10,2) NOT NULL
) $charset_collate;";

   
     dbDelta($sql);
     dbDelta($sql2);    

    $wpdb->query(
    $wpdb->prepare(
        "INSERT INTO $rates_table (zone, rate) VALUES (%s, %f)
         ON DUPLICATE KEY UPDATE rate = VALUES(rate)",
        'COD',
        50
    )
);
}

register_activation_hook(__FILE__, 'pzm_create_table');

// Add menu
function pzm_menu()
{
    add_menu_page(
        'Pincode Upload',
        'Pincode Zones',
        'manage_options',
        'pincode-zones',
        'pzm_upload_page',
        'dashicons-location',
        20
    );

    add_submenu_page(
        'pincode-zones',
        'Upload Pincode',
        'Upload Pincode',
        'manage_options',
        'pincode-zones',  // same slug → avoids duplicate page
        'pzm_upload_page'
    );

    // Submenu: Search
    add_submenu_page(
        'pincode-zones',
        'Search Pincode',
        'Search',
        'manage_options',
        'pincode-search',
        'pzm_search_page'
    );

    add_submenu_page(
        'pincode-zones',
        'Zone Shipping Rates',
        'Zone Rates',
        'manage_options',
        'pincode-zone-rates',
        'pzm_zone_rates_page'
    );
}

add_action('admin_menu', 'pzm_menu');

// Upload page
function pzm_upload_page()
{
    ?>
    <div class="wrap">
        <h1><b>Upload Pincode<b></h1><hr><br>
        <p>Upload file in CSV format, have respective fields in order: <br>delivery_city, delivery_pincode, courier, zone (z_a) -- only 3 character allowed for zones.</p>
        <br>
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

function pzm_search_page()
{
    ?>
    <div class="wrap">
        <h2><b>Search Pincode</b></h2><hr><br>
        <p>Enter 6-digit pincode to check for delivery partners and zones</p>
        <form method="post">
            <input type="text" name="search_pincode" placeholder="Enter pincode" required>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <input type="submit" name="search" class="button button-primary" value="Search">
        </form>
    </div>
    <?php

    if (isset($_POST['search'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pincode_zones';

        $pincode = sanitize_text_field($_POST['search_pincode']);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE delivery_pincode = %s",
                $pincode
            )
        );

        if ($results) {
            echo '<br><br><h3>Results:</h3>';
            echo "<table class='widefat'><tr>
                    <th>City</th>
                    <th>Pincode</th>
                    <th>Courier</th>
                    <th>Zone</th>
                  </tr>";

            foreach ($results as $row) {
                echo "<tr>
                        <td>{$row->delivery_city}</td>
                        <td>{$row->delivery_pincode}</td>
                        <td>{$row->courier}</td>
                        <td>{$row->zone}</td>
                      </tr>";
            }

            echo '</table>';
        } else {
            echo '<p>No results found</p>';
        }
    }
}

// Handle file upload
function pzm_handle_upload()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'pincode_zones';

    $wpdb->query("TRUNCATE TABLE $table_name");

    if ($_FILES['csv_file']['error'] == 0) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');

        // Skip header
        fgetcsv($file);

        $values = [];
        $batch_size = 1000;

        $row_number = 1;
        $inserted = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($file)) !== FALSE) {
            $row_number++;

            //  Basic validation Check if all columns are present
            if (count($row) < 4) {
                $errors[] = [
                    'row' => $row_number,
                    'reason' => 'Missing columns',
                    'data' => implode(',', $row)
                ];
                $skipped++;
                continue;
            }

            $city = trim($row[0]);
            $pincode = trim($row[1]);
            $courier = trim($row[2]);
            $zone = substr(trim($row[3]), 0, 3);

            if (empty($pincode)) {
                $errors[] = [
                    'row' => $row_number,
                    'reason' => 'Empty pincode',
                    'data' => implode(',', $row)
                ];
                $skipped++;
                continue;
            }

            if (!preg_match('/^[0-9]{6}$/', $pincode)) {
                $errors[] = [
                    'row' => $row_number,
                    'reason' => "Invalid pincode ($pincode)",
                    'data' => implode(',', $row)
                ];
                $skipped++;
                continue;
            }

            $values[] = $wpdb->prepare('(%s,%s,%s,%s)', $row[0], $row[1], $row[2], substr($row[3], 0, 3));

            if (count($values) >= $batch_size) {
                $query = "INSERT INTO $table_name 
                (delivery_city, delivery_pincode, courier, zone)
                VALUES " . implode(',', $values);

                $result = $wpdb->query($query);

                if ($result === false) {
                    $errors[] = [
                        'row' => $row_number,
                        'reason' => 'DB Error: ' . $wpdb->last_error,
                        'data' => 'Batch failed'
                    ];
                } else {
                    $inserted += $result;
                }

                $values = [];
            }
        }

        // Insert remaining
        if (!empty($values)) {
            $query = "INSERT INTO $table_name 
            (delivery_city, delivery_pincode, courier, zone)
            VALUES " . implode(',', $values);

            $result = $wpdb->query($query);

            if ($result === false) {
                $errors[] = [
                    'row' => 'Final',
                    'reason' => 'DB Error: ' . $wpdb->last_error,
                    'data' => 'Final batch failed'
                ];
            } else {
                $inserted += $result;
            }
        }

        fclose($file);

        // ✅ SUMMARY
        echo "<div class='updated'>
                <p><strong>Upload Completed</strong></p>
                <p>Inserted: $inserted | Skipped: $skipped</p>
              </div>";

        // ❗ ERROR TABLE (only if errors exist)
        if (!empty($errors)) {
            echo '<h3>⚠️ Skipped / Failed Rows</h3>';
            echo "<div style='max-height:300px; overflow:auto; border:1px solid #ccc;'>";
            echo "<table class='widefat fixed striped'>";
            echo '<thead><tr>
                    <th>Row</th>
                    <th>Reason</th>
                    <th>Data</th>
                  </tr></thead><tbody>';

            foreach ($errors as $err) {
                echo "<tr>
                        <td>{$err['row']}</td>
                        <td>{$err['reason']}</td>
                        <td>{$err['data']}</td>
                      </tr>";
            }

            echo '</tbody></table></div>';
        }
    }
}

function pzm_zone_rates_page() {
    global $wpdb;

    $zones_table = $wpdb->prefix . 'pincode_zones';
    $rates_table = $wpdb->prefix . 'pincode_zone_rates';

    // Save rates
    if (isset($_POST['save_rates'])) {
        foreach ($_POST['rates'] as $zone => $rate) {
            $zone = sanitize_text_field($zone);
            $rate = floatval($rate);

            $wpdb->replace(
                $rates_table,
                [
                    'zone' => $zone,
                    'rate' => $rate
                ],
                ['%s', '%f']
            );
        }

        echo "<div class='updated'><p>Rates saved successfully</p></div>";
    }

    // Get unique zones
    $zones = $wpdb->get_col("SELECT DISTINCT zone FROM $zones_table WHERE zone != ''");

    // Get existing rates
    $existing_rates = $wpdb->get_results("SELECT * FROM $rates_table", OBJECT_K);

    $zone_detail =[
        "z_a" => 'Within City',
        "z_b" => 'Within State',
        "z_c" => 'Metro to Metro',
        "z_d" => 'Rest of India',
        "z_e" => 'NE, J&K and Special States'
    ];

    ?>
    <div class="wrap">
        <h2>Zone Shipping Rates</h2>

        

        <form method="post">
            <table class="widefat">
                <thead>
                    <tr>
                        <th width="400px"><h2>Zone</h2></th>
                        <th><h2>Rate (₹) </h2></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zones as $zone): 
                        $rate = isset($existing_rates[$zone]) ? $existing_rates[$zone]->rate : '';
                    ?>
                        <tr>
                            <td><span><h3><?php echo esc_html($zone); ?></h3></span>&nbsp; <span><b><?php echo esc_html($zone_detail[$zone]); ?></b></span></td>
                            <td>
                                <input type="number" step="0.01" name="rates[<?php echo esc_attr($zone); ?>]" value="<?php echo esc_attr($rate); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <br>
            <input type="submit" name="save_rates" class="button button-primary" value="Save Rates">
        </form>
    </div>
    <?php
}


