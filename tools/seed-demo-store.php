<?php
/**
 * WooCommerce for Claude — Demo Store Seeder
 *
 * Generates a realistic WooCommerce store with 2 years of data for testing
 * analytics Skills. Uses WooCommerce APIs (not raw SQL) so all analytics
 * lookup tables get populated correctly.
 *
 * Run via WP-CLI: wp eval-file tools/seed-demo-store.php
 *
 * Uses WooCommerce CRUD APIs so all analytics lookup tables are populated correctly.
 *
 * @package WooCommerce\Claude
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand, WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic seeding is the whole point: mt_srand(42) + mt_rand() produce identical demo data across machines, which wp_rand() can't do.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Script is WP-CLI only; echo writes plain text progress to stdout, never HTML.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- $term / $status / $order are loop-local variables in this seeding script; no WP global is being clobbered.
// phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Empty catch blocks deliberately swallow per-item seeding failures so a single bad row doesn't abort the run. See inline comments near each catch.
// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- Seeding notes and tuning hints live in inline comments; sniff false-positives on fixture-style pseudocode.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Configuration ───────────────────────────────────────────────────────────

$num_orders    = 5000;
$num_customers = 500;
$months_back   = 24;

// Fixed seed for reproducibility — both partners get identical data.
mt_srand( 42 );

// ─── Product Definitions (50 simple + 5 variable) ───────────────────────────

$product_defs = array(
	// Electronics > Audio.
	array(
		'name'       => 'Premium Wireless Headphones',
		'price'      => 149.99,
		'cat'        => 'Audio',
		'parent_cat' => 'Electronics',
		'popular'    => true,
	),
	array(
		'name'       => 'Bluetooth Speaker',
		'price'      => 79.99,
		'cat'        => 'Audio',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'        => 'Wireless Earbuds',
		'price'       => 59.99,
		'cat'         => 'Audio',
		'parent_cat'  => 'Electronics',
		'high_refund' => true,
	),
	array(
		'name'       => 'Noise Cancelling Headphones',
		'price'      => 249.99,
		'cat'        => 'Audio',
		'parent_cat' => 'Electronics',
		'popular'    => true,
	),

	// Electronics > Accessories.
	array(
		'name'       => 'USB-C Charging Cable 3-Pack',
		'price'      => 14.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'       => 'Laptop Stand — Adjustable',
		'price'      => 49.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'       => 'Wireless Charging Pad',
		'price'      => 29.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'       => 'Portable Power Bank 20000mAh',
		'price'      => 39.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'       => 'Screen Protector 3-Pack',
		'price'      => 9.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),
	array(
		'name'       => 'Webcam HD 1080p',
		'price'      => 69.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Electronics',
	),

	// Food & Drink.
	array(
		'name'       => 'Organic Coffee Beans 1kg',
		'price'      => 24.99,
		'cat'        => 'Coffee & Tea',
		'parent_cat' => 'Food & Drink',
		'popular'    => true,
	),
	array(
		'name'       => 'Matcha Powder 200g',
		'price'      => 18.99,
		'cat'        => 'Coffee & Tea',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Artisan Hot Sauce Trio',
		'price'      => 29.99,
		'cat'        => 'Sauces & Spices',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Organic Honey 500g',
		'price'      => 15.99,
		'cat'        => 'Pantry',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Granola Mix 750g',
		'price'      => 12.99,
		'cat'        => 'Pantry',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Loose Leaf Tea Collection',
		'price'      => 34.99,
		'cat'        => 'Coffee & Tea',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Dark Chocolate Gift Box',
		'price'      => 22.99,
		'cat'        => 'Pantry',
		'parent_cat' => 'Food & Drink',
	),
	array(
		'name'       => 'Cold Brew Concentrate 1L',
		'price'      => 16.99,
		'cat'        => 'Coffee & Tea',
		'parent_cat' => 'Food & Drink',
	),

	// Apparel (non-variable basics).
	array(
		'name'       => 'Leather Belt',
		'price'      => 39.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Apparel',
	),
	array(
		'name'       => 'Wool Beanie Hat',
		'price'      => 19.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Apparel',
	),
	array(
		'name'       => 'Cotton Socks 5-Pack',
		'price'      => 14.99,
		'cat'        => 'Basics',
		'parent_cat' => 'Apparel',
	),
	array(
		'name'       => 'Silk Scarf',
		'price'      => 49.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Apparel',
	),
	array(
		'name'       => 'Canvas Tote Bag',
		'price'      => 24.99,
		'cat'        => 'Bags',
		'parent_cat' => 'Apparel',
	),
	array(
		'name'       => 'Leather Backpack',
		'price'      => 129.99,
		'cat'        => 'Bags',
		'parent_cat' => 'Apparel',
		'popular'    => true,
	),

	// Fitness.
	array(
		'name'       => 'Yoga Mat — Extra Thick',
		'price'      => 44.99,
		'cat'        => 'Equipment',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'       => 'Resistance Band Set',
		'price'      => 22.99,
		'cat'        => 'Equipment',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'       => 'Stainless Steel Water Bottle',
		'price'      => 29.99,
		'cat'        => 'Hydration',
		'parent_cat' => 'Fitness',
		'popular'    => true,
	),
	array(
		'name'       => 'Foam Roller',
		'price'      => 34.99,
		'cat'        => 'Equipment',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'       => 'Jump Rope — Speed',
		'price'      => 16.99,
		'cat'        => 'Equipment',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'       => 'Protein Shaker Bottle',
		'price'      => 12.99,
		'cat'        => 'Hydration',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'       => 'Gym Towel Set',
		'price'      => 19.99,
		'cat'        => 'Equipment',
		'parent_cat' => 'Fitness',
	),
	array(
		'name'        => 'Fitness Tracker Band',
		'price'       => 89.99,
		'cat'         => 'Equipment',
		'parent_cat'  => 'Fitness',
		'high_refund' => true,
	),

	// Home & Garden.
	array(
		'name'       => 'Ceramic Plant Pot — Large',
		'price'      => 34.99,
		'cat'        => 'Garden',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Scented Candle Set (3-pack)',
		'price'      => 27.99,
		'cat'        => 'Home Décor',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Bamboo Cutting Board',
		'price'      => 32.99,
		'cat'        => 'Kitchen',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Linen Throw Blanket',
		'price'      => 59.99,
		'cat'        => 'Home Décor',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Cast Iron Skillet',
		'price'      => 44.99,
		'cat'        => 'Kitchen',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Indoor Herb Garden Kit',
		'price'      => 29.99,
		'cat'        => 'Garden',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Ceramic Mug Set (4-pack)',
		'price'      => 34.99,
		'cat'        => 'Kitchen',
		'parent_cat' => 'Home & Garden',
	),
	array(
		'name'       => 'Essential Oil Diffuser',
		'price'      => 39.99,
		'cat'        => 'Home Décor',
		'parent_cat' => 'Home & Garden',
	),

	// Stationery.
	array(
		'name'       => 'Notebook & Pen Set',
		'price'      => 9.99,
		'cat'        => 'Writing',
		'parent_cat' => 'Stationery',
	),
	array(
		'name'       => 'Fountain Pen — Brass',
		'price'      => 79.99,
		'cat'        => 'Writing',
		'parent_cat' => 'Stationery',
	),
	array(
		'name'       => 'Leather Journal A5',
		'price'      => 34.99,
		'cat'        => 'Writing',
		'parent_cat' => 'Stationery',
	),
	array(
		'name'       => 'Desk Organiser — Walnut',
		'price'      => 54.99,
		'cat'        => 'Desk',
		'parent_cat' => 'Stationery',
	),
	array(
		'name'       => 'Washi Tape Collection (10 rolls)',
		'price'      => 12.99,
		'cat'        => 'Craft',
		'parent_cat' => 'Stationery',
	),

	// Seasonal (only sell in certain months).
	array(
		'name'       => 'Winter Jacket — Insulated',
		'price'      => 189.99,
		'cat'        => 'Outerwear',
		'parent_cat' => 'Apparel',
		'seasonal'   => array( 10, 11, 12, 1, 2 ),
	),
	array(
		'name'       => 'Sunglasses — Polarised',
		'price'      => 69.99,
		'cat'        => 'Accessories',
		'parent_cat' => 'Apparel',
		'seasonal'   => array( 4, 5, 6, 7, 8 ),
	),
	array(
		'name'       => 'Beach Towel — Oversized',
		'price'      => 29.99,
		'cat'        => 'Home Décor',
		'parent_cat' => 'Home & Garden',
		'seasonal'   => array( 5, 6, 7, 8 ),
	),
	array(
		'name'       => 'Christmas Candle Gift Set',
		'price'      => 39.99,
		'cat'        => 'Home Décor',
		'parent_cat' => 'Home & Garden',
		'seasonal'   => array( 11, 12 ),
	),
	array(
		'name'       => 'Valentine Chocolate Box',
		'price'      => 24.99,
		'cat'        => 'Pantry',
		'parent_cat' => 'Food & Drink',
		'seasonal'   => array( 1, 2 ),
	),
);

// Variable products (clothing with sizes).
$variable_product_defs = array(
	array(
		'name'       => 'Classic Hoodie',
		'cat'        => 'Basics',
		'parent_cat' => 'Apparel',
		'attribute'  => 'Size',
		'popular'    => true,
		'variations' => array(
			array(
				'option' => 'Small',
				'price'  => 54.99,
				'weight' => 10,
			),
			array(
				'option' => 'Medium',
				'price'  => 54.99,
				'weight' => 35,
			),
			array(
				'option' => 'Large',
				'price'  => 54.99,
				'weight' => 30,
			),
			array(
				'option' => 'XL',
				'price'  => 59.99,
				'weight' => 25,
			),
		),
	),
	array(
		'name'       => 'Cotton T-Shirt',
		'cat'        => 'Basics',
		'parent_cat' => 'Apparel',
		'attribute'  => 'Size',
		'popular'    => true,
		'variations' => array(
			array(
				'option' => 'Small',
				'price'  => 24.99,
				'weight' => 10,
			),
			array(
				'option' => 'Medium',
				'price'  => 24.99,
				'weight' => 35,
			),
			array(
				'option' => 'Large',
				'price'  => 24.99,
				'weight' => 30,
			),
			array(
				'option' => 'XL',
				'price'  => 29.99,
				'weight' => 25,
			),
		),
	),
	array(
		'name'        => 'Running Shoes',
		'cat'         => 'Equipment',
		'parent_cat'  => 'Fitness',
		'attribute'   => 'Size',
		'high_refund' => true,
		'variations'  => array(
			array(
				'option' => 'UK 7',
				'price'  => 119.99,
				'weight' => 15,
			),
			array(
				'option' => 'UK 8',
				'price'  => 119.99,
				'weight' => 25,
			),
			array(
				'option' => 'UK 9',
				'price'  => 119.99,
				'weight' => 30,
			),
			array(
				'option' => 'UK 10',
				'price'  => 119.99,
				'weight' => 20,
			),
			array(
				'option' => 'UK 11',
				'price'  => 129.99,
				'weight' => 10,
			),
		),
	),
	array(
		'name'       => 'Linen Shirt',
		'cat'        => 'Basics',
		'parent_cat' => 'Apparel',
		'attribute'  => 'Size',
		'seasonal'   => array( 4, 5, 6, 7, 8, 9 ),
		'variations' => array(
			array(
				'option' => 'Small',
				'price'  => 64.99,
				'weight' => 10,
			),
			array(
				'option' => 'Medium',
				'price'  => 64.99,
				'weight' => 35,
			),
			array(
				'option' => 'Large',
				'price'  => 64.99,
				'weight' => 30,
			),
			array(
				'option' => 'XL',
				'price'  => 69.99,
				'weight' => 25,
			),
		),
	),
	array(
		'name'       => 'Fleece Pullover',
		'cat'        => 'Outerwear',
		'parent_cat' => 'Apparel',
		'attribute'  => 'Size',
		'seasonal'   => array( 9, 10, 11, 12, 1, 2, 3 ),
		'variations' => array(
			array(
				'option' => 'Small',
				'price'  => 74.99,
				'weight' => 10,
			),
			array(
				'option' => 'Medium',
				'price'  => 74.99,
				'weight' => 35,
			),
			array(
				'option' => 'Large',
				'price'  => 74.99,
				'weight' => 30,
			),
			array(
				'option' => 'XL',
				'price'  => 79.99,
				'weight' => 25,
			),
		),
	),
);

// ─── Coupon Definitions (12) ────────────────────────────────────────────────

$coupon_defs = array(
	// Always available.
	array(
		'code'   => 'SAVE10',
		'type'   => 'percent',
		'amount' => 10,
		'min'    => 0,
		'weight' => 30,
	),
	array(
		'code'   => 'SAVE15',
		'type'   => 'percent',
		'amount' => 15,
		'min'    => 50,
		'weight' => 15,
	),
	array(
		'code'   => 'FLAT5',
		'type'   => 'fixed_cart',
		'amount' => 5,
		'min'    => 25,
		'weight' => 10,
	),
	array(
		'code'   => 'FLAT10',
		'type'   => 'fixed_cart',
		'amount' => 10,
		'min'    => 75,
		'weight' => 8,
	),
	array(
		'code'   => 'FREESHIP',
		'type'   => 'free_shipping',
		'amount' => 0,
		'min'    => 0,
		'weight' => 12,
	),
	// Loyalty (returning customers only).
	array(
		'code'    => 'RETURNING10',
		'type'    => 'percent',
		'amount'  => 10,
		'min'     => 0,
		'weight'  => 10,
		'loyalty' => true,
	),
	array(
		'code'    => 'LOYALVIP20',
		'type'    => 'percent',
		'amount'  => 20,
		'min'     => 100,
		'weight'  => 5,
		'loyalty' => true,
	),
	// Seasonal.
	array(
		'code'     => 'BLACKFRIDAY',
		'type'     => 'percent',
		'amount'   => 30,
		'min'      => 50,
		'weight'   => 5,
		'seasonal' => array( 11 ),
	),
	array(
		'code'     => 'CYBERMONDAY',
		'type'     => 'percent',
		'amount'   => 25,
		'min'      => 30,
		'weight'   => 3,
		'seasonal' => array( 11 ),
	),
	array(
		'code'     => 'SUMMER25',
		'type'     => 'percent',
		'amount'   => 25,
		'min'      => 50,
		'weight'   => 5,
		'seasonal' => array( 6, 7, 8 ),
	),
	array(
		'code'     => 'NEWYEAR15',
		'type'     => 'percent',
		'amount'   => 15,
		'min'      => 0,
		'weight'   => 3,
		'seasonal' => array( 1 ),
	),
	array(
		'code'     => 'VALENTINE',
		'type'     => 'fixed_cart',
		'amount'   => 10,
		'min'      => 30,
		'weight'   => 3,
		'seasonal' => array( 2 ),
	),
);

// ─── Geographic Data ────────────────────────────────────────────────────────

$countries = array(
	// US — 60%.
	array(
		'country' => 'US',
		'state'   => 'CA',
		'city'    => 'Los Angeles',
		'zip'     => '90001',
		'weight'  => 10,
	),
	array(
		'country' => 'US',
		'state'   => 'NY',
		'city'    => 'New York',
		'zip'     => '10001',
		'weight'  => 10,
	),
	array(
		'country' => 'US',
		'state'   => 'TX',
		'city'    => 'Houston',
		'zip'     => '77001',
		'weight'  => 8,
	),
	array(
		'country' => 'US',
		'state'   => 'FL',
		'city'    => 'Miami',
		'zip'     => '33101',
		'weight'  => 7,
	),
	array(
		'country' => 'US',
		'state'   => 'IL',
		'city'    => 'Chicago',
		'zip'     => '60601',
		'weight'  => 5,
	),
	array(
		'country' => 'US',
		'state'   => 'WA',
		'city'    => 'Seattle',
		'zip'     => '98101',
		'weight'  => 4,
	),
	array(
		'country' => 'US',
		'state'   => 'CO',
		'city'    => 'Denver',
		'zip'     => '80201',
		'weight'  => 3,
	),
	array(
		'country' => 'US',
		'state'   => 'GA',
		'city'    => 'Atlanta',
		'zip'     => '30301',
		'weight'  => 3,
	),
	array(
		'country' => 'US',
		'state'   => 'OR',
		'city'    => 'Portland',
		'zip'     => '97201',
		'weight'  => 2,
	),
	array(
		'country' => 'US',
		'state'   => 'MA',
		'city'    => 'Boston',
		'zip'     => '02101',
		'weight'  => 2,
	),
	array(
		'country' => 'US',
		'state'   => 'PA',
		'city'    => 'Philadelphia',
		'zip'     => '19101',
		'weight'  => 2,
	),
	array(
		'country' => 'US',
		'state'   => 'AZ',
		'city'    => 'Phoenix',
		'zip'     => '85001',
		'weight'  => 2,
	),
	array(
		'country' => 'US',
		'state'   => 'NC',
		'city'    => 'Charlotte',
		'zip'     => '28201',
		'weight'  => 1,
	),
	array(
		'country' => 'US',
		'state'   => 'OH',
		'city'    => 'Columbus',
		'zip'     => '43201',
		'weight'  => 1,
	),
	// UK — 15%.
	array(
		'country' => 'GB',
		'state'   => '',
		'city'    => 'London',
		'zip'     => 'SW1A 1AA',
		'weight'  => 6,
	),
	array(
		'country' => 'GB',
		'state'   => '',
		'city'    => 'Manchester',
		'zip'     => 'M1 1AA',
		'weight'  => 4,
	),
	array(
		'country' => 'GB',
		'state'   => '',
		'city'    => 'Edinburgh',
		'zip'     => 'EH1 1YZ',
		'weight'  => 3,
	),
	array(
		'country' => 'GB',
		'state'   => '',
		'city'    => 'Birmingham',
		'zip'     => 'B1 1AA',
		'weight'  => 2,
	),
	// Canada — 10%.
	array(
		'country' => 'CA',
		'state'   => 'ON',
		'city'    => 'Toronto',
		'zip'     => 'M5V 1A1',
		'weight'  => 5,
	),
	array(
		'country' => 'CA',
		'state'   => 'BC',
		'city'    => 'Vancouver',
		'zip'     => 'V6B 1A1',
		'weight'  => 3,
	),
	array(
		'country' => 'CA',
		'state'   => 'QC',
		'city'    => 'Montreal',
		'zip'     => 'H2X 1Y4',
		'weight'  => 2,
	),
	// Germany — 5%.
	array(
		'country' => 'DE',
		'state'   => '',
		'city'    => 'Berlin',
		'zip'     => '10115',
		'weight'  => 3,
	),
	array(
		'country' => 'DE',
		'state'   => '',
		'city'    => 'Munich',
		'zip'     => '80331',
		'weight'  => 2,
	),
	// Australia — 5%.
	array(
		'country' => 'AU',
		'state'   => 'NSW',
		'city'    => 'Sydney',
		'zip'     => '2000',
		'weight'  => 3,
	),
	array(
		'country' => 'AU',
		'state'   => 'VIC',
		'city'    => 'Melbourne',
		'zip'     => '3000',
		'weight'  => 2,
	),
	// Other — 5%.
	array(
		'country' => 'FR',
		'state'   => '',
		'city'    => 'Paris',
		'zip'     => '75001',
		'weight'  => 2,
	),
	array(
		'country' => 'JP',
		'state'   => '',
		'city'    => 'Tokyo',
		'zip'     => '100-0001',
		'weight'  => 1,
	),
	array(
		'country' => 'BR',
		'state'   => 'SP',
		'city'    => 'São Paulo',
		'zip'     => '01000-000',
		'weight'  => 1,
	),
	array(
		'country' => 'NL',
		'state'   => '',
		'city'    => 'Amsterdam',
		'zip'     => '1012 AB',
		'weight'  => 1,
	),
);

// ─── Attribution Pools ──────────────────────────────────────────────────────

$attribution_config = array(
	// [ channel, source, utm_medium, weight, trend ]
	// trend: 'stable', 'growing', 'declining'
	array( 'Organic Search', 'google', 'organic', 28, 'stable' ),
	array( 'Organic Search', 'bing', 'organic', 5, 'stable' ),
	array( 'Organic Search', 'duckduckgo', 'organic', 2, 'stable' ),
	array( 'Direct', 'direct', '(none)', 25, 'stable' ),
	array( 'Social', 'instagram', 'social', 6, 'growing' ),
	array( 'Social', 'facebook', 'social', 5, 'declining' ),
	array( 'Social', 'tiktok', 'social', 2, 'growing' ),
	array( 'Social', 'twitter', 'social', 2, 'declining' ),
	array( 'Email', 'newsletter', 'email', 5, 'stable' ),
	array( 'Email', 'klaviyo', 'email', 4, 'growing' ),
	array( 'Email', 'mailchimp', 'email', 3, 'declining' ),
	array( 'Paid Search', 'google_ads', 'cpc', 5, 'stable' ),
	array( 'Paid Search', 'bing_ads', 'cpc', 3, 'stable' ),
	array( 'Referral', 'blogpost', 'referral', 3, 'stable' ),
	array( 'Referral', 'reviewsite', 'referral', 2, 'stable' ),
);

$campaigns = array( '', '', '', '', '', 'spring_sale', 'new_arrivals', 'email_weekly', 'summer_promo', 'flash_sale', 'black_friday', 'cyber_monday', 'holiday_gift', 'loyalty_reward', 'welcome_series' );

$device_types_weighted = array(
	array(
		'type'   => 'mobile',
		'weight' => 55,
	),
	array(
		'type'   => 'desktop',
		'weight' => 35,
	),
	array(
		'type'   => 'tablet',
		'weight' => 10,
	),
);

// Payment methods.
$payment_methods = array(
	array(
		'id'     => 'stripe',
		'title'  => 'Credit Card (Stripe)',
		'weight' => 60,
	),
	array(
		'id'     => 'paypal',
		'title'  => 'PayPal',
		'weight' => 25,
	),
	array(
		'id'     => 'bacs',
		'title'  => 'Direct Bank Transfer',
		'weight' => 10,
	),
	array(
		'id'     => 'cod',
		'title'  => 'Cash on Delivery',
		'weight' => 5,
	),
);

// Shipping methods.
$shipping_methods = array(
	array(
		'id'     => 'free_shipping',
		'title'  => 'Free Shipping',
		'weight' => 40,
	),
	array(
		'id'     => 'flat_rate',
		'title'  => 'Standard Shipping',
		'weight' => 35,
		'cost'   => 5.99,
	),
	array(
		'id'     => 'express',
		'title'  => 'Express Shipping',
		'weight' => 20,
		'cost'   => 14.99,
	),
	array(
		'id'     => 'local_pickup',
		'title'  => 'Local Pickup',
		'weight' => 5,
		'cost'   => 0,
	),
);

// Status weights: 72% completed, 13% processing, 7% on-hold, 5% refunded, 2% cancelled, 1% failed.
$order_statuses = array_merge(
	array_fill( 0, 72, 'completed' ),
	array_fill( 0, 13, 'processing' ),
	array_fill( 0, 7, 'on-hold' ),
	array_fill( 0, 5, 'refunded' ),
	array_fill( 0, 2, 'cancelled' ),
	array_fill( 0, 1, 'failed' )
);

// ─── Customer Name Pool ─────────────────────────────────────────────────────

$first_names = array(
	'Emma',
	'Liam',
	'Olivia',
	'Noah',
	'Ava',
	'Sophia',
	'Jackson',
	'Mia',
	'Lucas',
	'Isabella',
	'Aiden',
	'Charlotte',
	'Elijah',
	'Amelia',
	'James',
	'Harper',
	'Benjamin',
	'Evelyn',
	'Mason',
	'Aria',
	'Logan',
	'Chloe',
	'Alexander',
	'Ella',
	'Ethan',
	'Luna',
	'Jacob',
	'Lily',
	'Michael',
	'Grace',
	'Daniel',
	'Zoe',
	'Henry',
	'Nora',
	'Sebastian',
	'Riley',
	'Owen',
	'Layla',
	'Jack',
	'Penelope',
	'William',
	'Hannah',
	'Oliver',
	'Scarlett',
	'Leo',
	'Stella',
	'Theodore',
	'Aurora',
	'David',
	'Violet',
);

$last_names = array(
	'Smith',
	'Johnson',
	'Williams',
	'Brown',
	'Jones',
	'Garcia',
	'Miller',
	'Davis',
	'Rodriguez',
	'Martinez',
	'Hernandez',
	'Lopez',
	'Gonzalez',
	'Wilson',
	'Anderson',
	'Thomas',
	'Taylor',
	'Moore',
	'Jackson',
	'Martin',
	'Lee',
	'Perez',
	'Thompson',
	'White',
	'Harris',
	'Sanchez',
	'Clark',
	'Ramirez',
	'Lewis',
	'Robinson',
	'Walker',
	'Young',
	'Allen',
	'King',
	'Wright',
	'Scott',
	'Torres',
	'Hill',
	'Flores',
	'Green',
	'Adams',
	'Nelson',
	'Baker',
	'Hall',
	'Rivera',
	'Campbell',
	'Mitchell',
	'Carter',
	'Roberts',
	'Phillips',
);

// ─── Helper Functions ───────────────────────────────────────────────────────

/**
 * Pick a random element from an array.
 *
 * @param array $arr Array to pick from.
 * @return mixed Random element.
 */
function pick( $arr ) {
	return $arr[ mt_rand( 0, count( $arr ) - 1 ) ];
}

/**
 * Weighted random selection.
 * Each item in $arr must have a 'weight' key.
 *
 * @param array $arr Array of items with 'weight' keys.
 * @return mixed Selected item.
 */
function weighted_pick( $arr ) {
	$total = array_sum( array_column( $arr, 'weight' ) );
	$rand  = mt_rand( 1, $total );
	$sum   = 0;
	foreach ( $arr as $item ) {
		$sum += $item['weight'];
		if ( $rand <= $sum ) {
			return $item;
		}
	}
	return end( $arr );
}

/**
 * Generate a date with realistic patterns:
 * - Growth trend over time
 * - Seasonal spikes (Nov/Dec holiday, Feb Valentine's)
 * - Weekend dips
 * - Time-of-day patterns
 *
 * @param int $months_back How many months back the date window extends.
 * @return int Unix timestamp for the generated order date.
 */
function generate_order_date( $months_back ) {
	$now   = time();
	$start = strtotime( "-{$months_back} months" );

	// Growth bias: more orders in recent months (power curve).
	$r          = mt_rand( 0, 1000000 ) / 1000000;
	$day_offset = (int) ( pow( $r, 0.5 ) * ( ( $now - $start ) / 86400 ) );
	$ts         = $start + ( $day_offset * 86400 );

	// Seasonal multiplier — reject and re-roll based on month.
	$month            = (int) gmdate( 'n', $ts );
	$seasonal_weights = array(
		1  => 0.8,
		2  => 0.9,
		3  => 0.85,
		4  => 0.9,
		5  => 0.95,
		6  => 1.0,
		7  => 0.95,
		8  => 0.9,
		9  => 0.95,
		10 => 1.0,
		11 => 1.4,
		12 => 1.5,
	);
	$weight           = $seasonal_weights[ $month ] ?? 1.0;
	if ( mt_rand( 1, 100 ) > ( $weight * 70 ) ) {
		return generate_order_date( $months_back ); // re-roll.
	}

	// Weekend dip — 30% less likely on Sat/Sun.
	$dow = (int) gmdate( 'N', $ts );
	if ( $dow >= 6 && mt_rand( 1, 100 ) <= 30 ) {
		return generate_order_date( $months_back );
	}

	// Time of day: peaks at 10am-2pm and 7pm-9pm, quiet 1am-6am.
	$hour_weights = array(
		0  => 2,
		1  => 1,
		2  => 1,
		3  => 1,
		4  => 1,
		5  => 1,
		6  => 3,
		7  => 5,
		8  => 7,
		9  => 9,
		10 => 12,
		11 => 14,
		12 => 13,
		13 => 12,
		14 => 10,
		15 => 8,
		16 => 7,
		17 => 6,
		18 => 7,
		19 => 10,
		20 => 12,
		21 => 10,
		22 => 6,
		23 => 3,
	);
	$hour         = weighted_pick(
		array_map(
			function ( $h, $w ) {
				return array(
					'val'    => $h,
					'weight' => $w,
				);
			},
			array_keys( $hour_weights ),
			array_values( $hour_weights )
		)
	)['val'];

	$minute = mt_rand( 0, 59 );
	$second = mt_rand( 0, 59 );

	return mktime( $hour, $minute, $second, gmdate( 'n', $ts ), gmdate( 'j', $ts ), gmdate( 'Y', $ts ) );
}

/**
 * Get attribution with trend awareness (social growing, some channels declining).
 *
 * @param int   $order_ts           Unix timestamp of the order.
 * @param array $attribution_config Attribution channel definitions with weights and trends.
 * @return array Selected attribution row from $attribution_config.
 */
function get_attribution( $order_ts, $attribution_config ) {
	$months_ago = ( time() - $order_ts ) / ( 30 * 86400 );

	// Adjust weights based on trend and how old the order is.
	$adjusted = array();
	foreach ( $attribution_config as $attr ) {
		$w     = $attr[3]; // base weight.
		$trend = $attr[4];
		if ( 'growing' === $trend ) {
			$w = max( 1, $w - (int) ( $months_ago * 0.3 ) ); // less weight in the past.
		} elseif ( 'declining' === $trend ) {
			$w = max( 1, $w + (int) ( $months_ago * 0.2 ) ); // more weight in the past.
		}
		$adjusted[] = array(
			'data'   => $attr,
			'weight' => $w,
		);
	}

	$picked = weighted_pick( $adjusted );
	return $picked['data'];
}

/**
 * Select products for an order with Pareto distribution.
 *
 * @param array $product_ids Available product rows (each with id/price/weight/seasonal).
 * @param int   $order_ts    Unix timestamp of the order, used for seasonal filtering.
 * @return array Selected product rows for the order.
 */
function select_products( $product_ids, $order_ts ) {
	$month = (int) gmdate( 'n', $order_ts );

	// Filter out seasonal products that aren't in season.
	$available = array();
	foreach ( $product_ids as $p ) {
		if ( ! empty( $p['seasonal'] ) ) {
			if ( ! in_array( $month, $p['seasonal'], true ) ) {
				continue;
			}
		}
		$available[] = $p;
	}
	if ( empty( $available ) ) {
		$available = $product_ids;
	}

	// Items per order: 1 (40%), 2 (30%), 3 (20%), 4+ (10%).
	$r = mt_rand( 1, 100 );
	if ( $r <= 40 ) {
		$num_items = 1;
	} elseif ( $r <= 70 ) {
		$num_items = 2;
	} elseif ( $r <= 90 ) {
		$num_items = 3;
	} else {
		$num_items = mt_rand( 4, 6 );
	}
	$num_items = min( $num_items, count( $available ) );

	// Weighted selection — popular products get picked more.
	$selected  = array();
	$used_keys = array();
	for ( $i = 0; $i < $num_items; $i++ ) {
		$attempts = 0;
		do {
			$item = weighted_pick( $available );
			$key  = $item['id'];
			++$attempts;
		} while ( isset( $used_keys[ $key ] ) && $attempts < 20 );
		$used_keys[ $key ] = true;
		$selected[]        = $item;
	}

	return $selected;
}

// ─── Step 1: Create Categories (hierarchical) ──────────────────────────────

echo "Creating product categories...\n";

// Collect all parent > child pairs.
$parent_cats = array();
foreach ( array_merge( $product_defs, $variable_product_defs ) as $def ) {
	$parent = $def['parent_cat'];
	$child  = $def['cat'];
	if ( ! isset( $parent_cats[ $parent ] ) ) {
		$parent_cats[ $parent ] = array();
	}
	$parent_cats[ $parent ][ $child ] = true;
}

$cat_ids = array();

// Create parent categories first.
foreach ( $parent_cats as $parent_name => $children ) {
	$term = term_exists( $parent_name, 'product_cat' );
	if ( $term ) {
		$cat_ids[ $parent_name ] = (int) $term['term_id'];
	} else {
		$result                  = wp_insert_term( $parent_name, 'product_cat' );
		$cat_ids[ $parent_name ] = (int) $result['term_id'];
	}

	// Create child categories.
	foreach ( array_keys( $children ) as $child_name ) {
		$term = term_exists( $child_name, 'product_cat' );
		if ( $term ) {
			$cat_ids[ $child_name ] = (int) $term['term_id'];
		} else {
			$result                 = wp_insert_term( $child_name, 'product_cat', array( 'parent' => $cat_ids[ $parent_name ] ) );
			$cat_ids[ $child_name ] = (int) $result['term_id'];
		}
	}
}

$total_cats = count( $cat_ids );
echo "  Created {$total_cats} categories (with hierarchy).\n";

// ─── Step 2: Create Simple Products ─────────────────────────────────────────

echo "Creating products...\n";

$product_ids  = array();
$simple_count = 0;

foreach ( $product_defs as $def ) {
	$product = new WC_Product_Simple();
	$product->set_name( $def['name'] );
	$product->set_regular_price( (string) $def['price'] );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 500 );

	// Set both parent and child category.
	$cats = array( $cat_ids[ $def['cat'] ] );
	if ( ! empty( $def['parent_cat'] ) && isset( $cat_ids[ $def['parent_cat'] ] ) ) {
		$cats[] = $cat_ids[ $def['parent_cat'] ];
	}
	$product->set_category_ids( $cats );
	$product->save();

	$weight = ( ! empty( $def['popular'] ) ) ? 15 : 5;

	$product_ids[] = array(
		'id'          => $product->get_id(),
		'price'       => $def['price'],
		'weight'      => $weight,
		'high_refund' => ! empty( $def['high_refund'] ),
		'seasonal'    => $def['seasonal'] ?? null,
	);
	++$simple_count;
}

// ─── Step 3: Create Variable Products ───────────────────────────────────────

$variation_count = 0;

foreach ( $variable_product_defs as $vdef ) {
	$variable = new WC_Product_Variable();
	$variable->set_name( $vdef['name'] );
	$variable->set_status( 'publish' );

	$cats = array( $cat_ids[ $vdef['cat'] ] );
	if ( ! empty( $vdef['parent_cat'] ) && isset( $cat_ids[ $vdef['parent_cat'] ] ) ) {
		$cats[] = $cat_ids[ $vdef['parent_cat'] ];
	}
	$variable->set_category_ids( $cats );

	$attribute = new WC_Product_Attribute();
	$attribute->set_name( $vdef['attribute'] );
	$attribute->set_options( array_column( $vdef['variations'], 'option' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) );
	$variable->save();

	foreach ( $vdef['variations'] as $var ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_regular_price( (string) $var['price'] );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 200 );
		$variation->set_attributes( array( strtolower( $vdef['attribute'] ) => $var['option'] ) );
		$variation->save();

		$base_weight = ( ! empty( $vdef['popular'] ) ) ? 15 : 5;
		$size_weight = (int) ( $base_weight * ( $var['weight'] / 35 ) );

		$product_ids[] = array(
			'id'          => $variation->get_id(),
			'price'       => $var['price'],
			'weight'      => max( 1, $size_weight ),
			'high_refund' => ! empty( $vdef['high_refund'] ),
			'seasonal'    => $vdef['seasonal'] ?? null,
		);
		++$variation_count;
	}
}

echo "  Created {$simple_count} simple + {$variation_count} variations = " . ( $simple_count + $variation_count ) . " products.\n";

// ─── Step 4: Create Coupons ─────────────────────────────────────────────────

echo "Creating coupons...\n";

$coupon_codes = array();
foreach ( $coupon_defs as $def ) {
	$existing = wc_get_coupon_id_by_code( $def['code'] );
	if ( $existing ) {
		$coupon_codes[] = $def;
		continue;
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( $def['code'] );
	if ( 'free_shipping' === $def['type'] ) {
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 0 );
		$coupon->set_free_shipping( true );
	} else {
		$coupon->set_discount_type( $def['type'] );
		$coupon->set_amount( $def['amount'] );
	}
	if ( $def['min'] > 0 ) {
		$coupon->set_minimum_amount( (string) $def['min'] );
	}
	$coupon->save();
	$coupon_codes[] = $def;
}

echo '  Created ' . count( $coupon_codes ) . " coupons.\n";

// ─── Step 5: Generate Customer Pool ─────────────────────────────────────────

echo "Generating customer pool ({$num_customers} customers)...\n";

$customers = array();
for ( $i = 0; $i < $num_customers; $i++ ) {
	$fn   = $first_names[ $i % count( $first_names ) ];
	$ln   = $last_names[ $i % count( $last_names ) ];
	$addr = weighted_pick( $countries );

	// Power law: first 20% of customers are "heavy buyers".
	$is_power_buyer = ( $i < $num_customers * 0.2 );

	$customers[] = array(
		'email'       => strtolower( $fn ) . '.' . strtolower( $ln ) . '.' . ( $i + 1 ) . '@example.com',
		'first_name'  => $fn,
		'last_name'   => $ln,
		'address_1'   => ( 100 + $i ) . ' Main St',
		'city'        => $addr['city'],
		'state'       => $addr['state'],
		'postcode'    => $addr['zip'],
		'country'     => $addr['country'],
		'phone'       => '555-' . str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
		'power_buyer' => $is_power_buyer,
		'weight'      => $is_power_buyer ? 8 : 2, // Power buyers get picked 4x more.
	);
}

// ─── Step 6: Create Orders ──────────────────────────────────────────────────

echo "Creating {$num_orders} orders...\n";

// Disable emails.
add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );

$customer_order_count = array();
$total_revenue        = 0;
$refund_count         = 0;

for ( $i = 0; $i < $num_orders; $i++ ) {
	// Pick customer (weighted — power buyers picked more often).
	$customer = weighted_pick( $customers );
	$email    = $customer['email'];
	$status   = pick( $order_statuses );
	$order_ts = generate_order_date( $months_back );

	// Guest checkout for ~25% of orders.
	$is_guest = ( mt_rand( 1, 100 ) <= 25 );

	// Track order counts per customer.
	if ( ! isset( $customer_order_count[ $email ] ) ) {
		$customer_order_count[ $email ] = 0;
	}
	++$customer_order_count[ $email ];
	$is_returning = ( $customer_order_count[ $email ] > 1 );

	// Select products (weighted, seasonal-aware).
	$selected_products = select_products( $product_ids, $order_ts );

	// Create order.
	$order = wc_create_order( array( 'status' => 'pending' ) );

	// Billing details.
	$order->set_billing_first_name( $customer['first_name'] );
	$order->set_billing_last_name( $customer['last_name'] );
	$order->set_billing_email( $is_guest ? 'guest.' . mt_rand( 10000, 99999 ) . '@example.com' : $email );
	$order->set_billing_phone( $customer['phone'] );
	$order->set_billing_address_1( $customer['address_1'] );
	$order->set_billing_city( $customer['city'] );
	$order->set_billing_state( $customer['state'] );
	$order->set_billing_postcode( $customer['postcode'] );
	$order->set_billing_country( $customer['country'] );

	// Shipping = billing.
	$order->set_shipping_first_name( $customer['first_name'] );
	$order->set_shipping_last_name( $customer['last_name'] );
	$order->set_shipping_address_1( $customer['address_1'] );
	$order->set_shipping_city( $customer['city'] );
	$order->set_shipping_state( $customer['state'] );
	$order->set_shipping_postcode( $customer['postcode'] );
	$order->set_shipping_country( $customer['country'] );

	// Add products.
	foreach ( $selected_products as $prod ) {
		$product_obj = wc_get_product( $prod['id'] );
		if ( $product_obj ) {
			$qty = mt_rand( 1, 3 );
			$order->add_product( $product_obj, $qty );
		}
	}

	// Maybe apply coupon (~30% of orders).
	$month = (int) gmdate( 'n', $order_ts );
	if ( mt_rand( 1, 100 ) <= 30 ) {
		// Filter eligible coupons.
		$eligible = array_filter(
			$coupon_codes,
			function ( $c ) use ( $month, $is_returning ) {
				// Seasonal check.
				if ( ! empty( $c['seasonal'] ) && ! in_array( $month, $c['seasonal'], true ) ) {
					return false;
				}
				// Loyalty check.
				if ( ! empty( $c['loyalty'] ) && ! $is_returning ) {
					return false;
				}
				return true;
			}
		);
		if ( ! empty( $eligible ) ) {
			$coupon = weighted_pick( array_values( $eligible ) );
			try {
				$order->apply_coupon( $coupon['code'] );
			} catch ( Exception $e ) {
				// Skip if can't apply.
			}
		}
	}

	// Payment method.
	$pm = weighted_pick( $payment_methods );
	$order->set_payment_method( $pm['id'] );
	$order->set_payment_method_title( $pm['title'] );

	// Shipping.
	$sm = weighted_pick( $shipping_methods );
	if ( ! empty( $sm['cost'] ) && $sm['cost'] > 0 ) {
		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( $sm['title'] );
		$shipping_item->set_method_id( $sm['id'] );
		$shipping_item->set_total( (string) $sm['cost'] );
		$order->add_item( $shipping_item );
	}

	// Calculate totals.
	$order->calculate_totals();

	// Set dates.
	$date_str = gmdate( 'Y-m-d H:i:s', $order_ts );
	$order->set_date_created( $date_str );

	// Attribution data.
	$attr     = get_attribution( $order_ts, $attribution_config );
	$device   = weighted_pick( $device_types_weighted );
	$campaign = pick( $campaigns );

	$order->update_meta_data( '_wc_order_attribution_origin', $attr[0] );
	$order->update_meta_data( '_wc_order_attribution_source_type', $attr[0] );
	$order->update_meta_data( '_wc_order_attribution_utm_source', $attr[1] );
	$order->update_meta_data( '_wc_order_attribution_utm_medium', $attr[2] );
	$order->update_meta_data( '_wc_order_attribution_device_type', $device['type'] );
	if ( ! empty( $campaign ) ) {
		$order->update_meta_data( '_wc_order_attribution_utm_campaign', $campaign );
	}

	$order->save();

	// Status transitions (trigger WC hooks for lookup tables).
	$order_total = (float) $order->get_total();

	if ( 'completed' === $status ) {
		$order->set_status( 'processing' );
		$order->set_date_paid( $date_str );
		$order->save();
		$completed_ts = $order_ts + mt_rand( 3600, 259200 );
		$order->set_status( 'completed' );
		$order->set_date_completed( gmdate( 'Y-m-d H:i:s', $completed_ts ) );
		$order->save();
		$total_revenue += $order_total;
	} elseif ( 'processing' === $status ) {
		$order->set_status( 'processing' );
		$order->set_date_paid( $date_str );
		$order->save();
		$total_revenue += $order_total;
	} elseif ( 'on-hold' === $status ) {
		$order->set_status( 'on-hold' );
		$order->save();
	} elseif ( 'refunded' === $status ) {
		$order->set_status( 'processing' );
		$order->set_date_paid( $date_str );
		$order->save();

		// Complete the order first so it counts as revenue.
		$completed_ts = $order_ts + mt_rand( 3600, 259200 );
		$order->set_status( 'completed' );
		$order->set_date_completed( gmdate( 'Y-m-d H:i:s', $completed_ts ) );
		$order->save();
		$total_revenue += $order_total;

		// Create actual refund (partial ~30%, full ~70%)
		// wc_create_refund handles the negative entry in wc_order_stats.
		// For full refunds it also sets the order status to 'refunded' automatically.
		$is_partial    = ( mt_rand( 1, 100 ) <= 30 );
		$refund_amount = $is_partial ? round( $order_total * ( mt_rand( 20, 80 ) / 100 ), 2 ) : $order_total;
		$refund_delay  = mt_rand( 86400, 30 * 86400 ); // 1-30 days after order
		$refund_ts     = $completed_ts + $refund_delay;
		$refund_date   = gmdate( 'Y-m-d H:i:s', $refund_ts );

		$refund = wc_create_refund(
			array(
				'amount'   => $refund_amount,
				'reason'   => pick( array( 'Changed mind', 'Defective product', 'Wrong size', 'Not as described', 'Arrived damaged', 'Late delivery' ) ),
				'order_id' => $order->get_id(),
			)
		);

		// Fix refund date to be relative to order, not today.
		if ( $refund && ! is_wp_error( $refund ) ) {
			$refund->set_date_created( $refund_date );
			$refund->save();

			// Also update the refund's entry in wc_order_stats to the correct date.
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'wc_order_stats',
				array( 'date_created' => $refund_date ),
				array( 'order_id' => $refund->get_id() )
			);
		}

		++$refund_count;
	} elseif ( 'cancelled' === $status ) {
		$order->set_status( 'cancelled' );
		$order->save();
	} elseif ( 'failed' === $status ) {
		$order->set_status( 'failed' );
		$order->save();
	}

	// Restore original date (status transitions can reset it).
	$order->set_date_created( $date_str );
	$order->save();

	// Progress.
	if ( ( $i + 1 ) % 500 === 0 ) {
		echo '  ' . ( $i + 1 ) . "/{$num_orders} orders created...\n";
	}
}

echo "  {$num_orders} orders created.\n";

// ─── Step 7: Force Analytics Table Sync ─────────────────────────────────────

echo "Syncing WooCommerce Analytics lookup tables...\n";

if ( class_exists( 'ActionScheduler' ) ) {
	echo "  Processing Action Scheduler queue...\n";
	$runner = ActionScheduler::runner();
	for ( $batch = 0; $batch < 20; $batch++ ) {
		$runner->run();
	}
}

try {
	$orders_query  = new WC_Order_Query(
		array(
			'limit'  => -1,
			'return' => 'ids',
		)
	);
	$all_order_ids = $orders_query->get_orders();

	if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore' ) ) {
		$ds     = new \Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore();
		$synced = 0;
		foreach ( $all_order_ids as $oid ) {
			try {
				$ds->sync_order( $oid );
				++$synced;
			} catch ( Exception $e ) {
				// Skip this order; continue syncing the rest.
			}
		}
		echo "  Synced {$synced} orders to wc_order_stats.\n";
	}

	$synced_products = 0;
	$synced_coupons  = 0;
	$synced_taxes    = 0;
	foreach ( $all_order_ids as $oid ) {
		try {
			\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $oid );
			++$synced_products;
		} catch ( Exception $e ) {
			// Skip this order; continue syncing the rest.
		}
		try {
			\Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore::sync_order_coupons( $oid );
			++$synced_coupons;
		} catch ( Exception $e ) {
			// Skip this order; continue syncing the rest.
		}
		try {
			\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore::sync_order_taxes( $oid );
			++$synced_taxes;
		} catch ( Exception $e ) {
			// Skip this order; continue syncing the rest.
		}
	}
	echo "  Synced {$synced_products} products, {$synced_coupons} coupons, {$synced_taxes} taxes.\n";
} catch ( Exception $e ) {
	echo '  Note: Could not force-sync: ' . $e->getMessage() . "\n";
}

// ─── Summary ────────────────────────────────────────────────────────────────

$repeat_buyers = count(
	array_filter(
		$customer_order_count,
		function ( $c ) {
			return $c > 1;
		}
	)
);
$refund_rate   = round( ( $refund_count / $num_orders ) * 100, 1 );

$date_start = gmdate( 'Y-m-d', strtotime( "-{$months_back} months" ) );
$date_end   = gmdate( 'Y-m-d' );

echo "\n";
echo "========================================\n";
echo "  Demo store seeded!\n";
echo "========================================\n";
echo '  Products:       ' . ( $simple_count + $variation_count ) . " ({$simple_count} simple + {$variation_count} variations)\n";
echo "  Categories:     {$total_cats} (with hierarchy)\n";
echo '  Coupons:        ' . count( $coupon_codes ) . "\n";
echo "  Customers:      {$num_customers}\n";
echo "  Orders:         {$num_orders}\n";
echo "  Date range:     {$date_start} to {$date_end}\n";
echo '  Revenue:        $' . number_format( $total_revenue, 2 ) . "\n";
echo "  Refund rate:    {$refund_rate}%\n";
echo "  Repeat buyers:  {$repeat_buyers}\n";
echo "  Random seed:    42 (deterministic)\n";
echo "========================================\n";
echo "\n";
echo "Verify with: wp wca-data-consistency run --format=ai\n";
