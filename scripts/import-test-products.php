<?php
/**
 * Import Test Products
 *
 * Creates test products and categories in WooCommerce for development/testing.
 * Can be run via WP-CLI: wp eval-file import-test-products.php
 * Or loaded via browser if placed in a reachable location.
 *
 * @package WooOneClick
 * @since 1.1.0
 */

// If running via WP-CLI, WordPress is already loaded
if (!defined('ABSPATH')) {
    // Try to load WordPress (adjust path if needed)
    $wp_load = dirname(__FILE__, 5) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        die("Cannot find wp-load.php. Run this via WP-CLI: wp eval-file scripts/import-test-products.php\n");
    }
}

if (!function_exists('wc_get_product')) {
    die("WooCommerce is not active.\n");
}

/**
 * Test product data organized by category
 */
$test_data = [
    'Electronics' => [
        ['name' => 'Wireless Bluetooth Headphones', 'price' => 49.99, 'desc' => 'Premium wireless headphones with noise cancellation and 30-hour battery life.'],
        ['name' => 'USB-C Charging Hub', 'price' => 29.99, 'desc' => 'Compact 6-port USB-C hub with fast charging support.'],
        ['name' => 'Smart Watch Pro', 'price' => 199.99, 'desc' => 'Advanced smartwatch with heart rate monitoring, GPS, and water resistance.'],
        ['name' => 'Portable Power Bank 20000mAh', 'price' => 39.99, 'desc' => 'High-capacity power bank with dual USB output and LED indicator.'],
        ['name' => 'Wireless Mouse Ergonomic', 'price' => 24.99, 'desc' => 'Ergonomic wireless mouse with adjustable DPI and silent clicks.'],
    ],
    'Pet Supplies' => [
        ['name' => 'Premium Dog Food 5kg', 'price' => 34.99, 'desc' => 'Grain-free premium dog food with real chicken and vegetables.'],
        ['name' => 'Cat Scratching Post Tower', 'price' => 59.99, 'desc' => 'Multi-level cat tower with sisal scratching posts and cozy hideaway.'],
        ['name' => 'Automatic Pet Feeder', 'price' => 89.99, 'desc' => 'Programmable automatic feeder with portion control and timer.'],
        ['name' => 'Dog Grooming Kit', 'price' => 19.99, 'desc' => 'Complete grooming set: brush, nail clipper, comb, and scissors.'],
        ['name' => 'Interactive Cat Toy', 'price' => 14.99, 'desc' => 'Motion-activated laser toy that keeps cats entertained for hours.'],
    ],
    'Clothing' => [
        ['name' => 'Classic Cotton T-Shirt', 'price' => 19.99, 'desc' => '100% organic cotton t-shirt, available in multiple colors.'],
        ['name' => 'Denim Jacket', 'price' => 79.99, 'desc' => 'Vintage-style denim jacket with brass buttons and chest pockets.'],
        ['name' => 'Running Shoes Ultralight', 'price' => 89.99, 'desc' => 'Lightweight running shoes with responsive cushioning and breathable mesh.'],
        ['name' => 'Wool Beanie Hat', 'price' => 15.99, 'desc' => 'Warm merino wool beanie, perfect for cold weather.'],
        ['name' => 'Linen Summer Shirt', 'price' => 44.99, 'desc' => 'Breathable linen shirt for casual summer wear.'],
    ],
    'Home & Garden' => [
        ['name' => 'LED Desk Lamp', 'price' => 35.99, 'desc' => 'Dimmable LED desk lamp with USB charging port and 5 brightness levels.'],
        ['name' => 'Bamboo Cutting Board Set', 'price' => 24.99, 'desc' => 'Set of 3 bamboo cutting boards in different sizes.'],
        ['name' => 'Indoor Herb Garden Kit', 'price' => 29.99, 'desc' => 'Complete kit for growing basil, mint, and cilantro indoors.'],
        ['name' => 'Scented Candle Set', 'price' => 22.99, 'desc' => 'Set of 4 soy wax candles: lavender, vanilla, cinnamon, and jasmine.'],
        ['name' => 'Wall-Mounted Shelf', 'price' => 39.99, 'desc' => 'Floating wooden shelf with hidden brackets, 80cm width.'],
    ],
    'Sports' => [
        ['name' => 'Yoga Mat Premium', 'price' => 39.99, 'desc' => 'Non-slip TPE yoga mat, 6mm thick with carrying strap.'],
        ['name' => 'Resistance Bands Set', 'price' => 19.99, 'desc' => 'Set of 5 resistance bands with different strength levels.'],
        ['name' => 'Stainless Steel Water Bottle', 'price' => 24.99, 'desc' => 'Double-wall insulated water bottle, keeps drinks cold for 24h.'],
        ['name' => 'Jump Rope Speed', 'price' => 12.99, 'desc' => 'Adjustable speed jump rope with ball bearings and foam handles.'],
    ],
    'Books' => [
        ['name' => 'Programming in Python', 'price' => 34.99, 'desc' => 'Comprehensive guide to Python programming for beginners and intermediates.'],
        ['name' => 'Mindfulness Journal', 'price' => 16.99, 'desc' => 'Guided journal with daily prompts for mindfulness and gratitude.'],
        ['name' => 'Cookbook: Mediterranean Diet', 'price' => 27.99, 'desc' => '120 authentic Mediterranean recipes for healthy living.'],
    ],
];

$created = 0;
$skipped = 0;

echo "Starting test products import...\n\n";

foreach ($test_data as $category_name => $products) {
    // Create or get category
    $term = term_exists($category_name, 'product_cat');
    if (!$term) {
        $term = wp_insert_term($category_name, 'product_cat');
        if (is_wp_error($term)) {
            echo "ERROR creating category '$category_name': " . $term->get_error_message() . "\n";
            continue;
        }
        echo "Created category: $category_name\n";
    } else {
        echo "Category exists: $category_name\n";
    }

    $category_id = is_array($term) ? $term['term_id'] : $term;

    foreach ($products as $product_data) {
        // Check if product already exists
        $existing = wc_get_products([
            'name'   => $product_data['name'],
            'limit'  => 1,
            'return' => 'ids',
        ]);

        if (!empty($existing)) {
            echo "  SKIP: {$product_data['name']} (already exists)\n";
            $skipped++;
            continue;
        }

        // Create product
        $product = new WC_Product_Simple();
        $product->set_name($product_data['name']);
        $product->set_regular_price($product_data['price']);
        $product->set_short_description($product_data['desc']);
        $product->set_description($product_data['desc']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_category_ids([$category_id]);
        $product->set_stock_status('instock');
        $product->set_manage_stock(false);
        $product->set_virtual(false);

        $product_id = $product->save();

        if ($product_id) {
            echo "  CREATED: {$product_data['name']} (ID: $product_id, \${$product_data['price']})\n";
            $created++;
        } else {
            echo "  ERROR: Failed to create {$product_data['name']}\n";
        }
    }

    echo "\n";
}

echo "Import complete: $created created, $skipped skipped\n";
echo "Total products in store: " . wp_count_posts('product')->publish . "\n";
