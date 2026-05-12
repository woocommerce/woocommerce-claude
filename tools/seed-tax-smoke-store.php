<?php
/**
 * WooCommerce for Claude — Tax Smoke Store Seeder
 *
 * Seeds a tiny, repeatable local fixture for tax collection and
 * reconciliation smoke tests. This is intentionally much smaller than
 * tools/seed-demo-store.php and follows WooCommerce's normal tax setup:
 * taxes enabled, prices entered excluding tax, tax based on shipping
 * address, shipping tax inherited from cart items, and standard-rate
 * rows created through WC_Tax. Orders then let WooCommerce calculate
 * order tax lines and totals.
 *
 * Run via WP-CLI inside wp-env:
 * pnpm exec wp-env run cli -- wp eval-file wp-content/plugins/woocommerce-claude/tools/seed-tax-smoke-store.php
 *
 * @package WooCommerce\Claude
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Script is WP-CLI only; output is plain terminal text.

use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStatsStore;
use Automattic\WooCommerce\Admin\API\Reports\Products\DataStore as ProductsStatsStore;
use Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore as TaxesDataStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce must be active before seeding the tax smoke fixture.' );
}

global $wpdb;

$fixture_key = '2026-05';
$now         = time();

// Match the documented WooCommerce tax settings shape.
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_shipping_tax_class', 'inherit' );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_tax_total_display', 'itemized' );
update_option( 'woocommerce_default_customer_address', 'base' );
update_option( 'woocommerce_currency', 'GBP' );
update_option( 'woocommerce_store_address', '123 Main Street' );
update_option( 'woocommerce_store_city', 'London' );
update_option( 'woocommerce_store_postcode', 'EC1A 1BB' );
update_option( 'woocommerce_default_country', 'GB' );

/**
 * Delete prior smoke fixture records so the script is repeat-safe.
 */
$existing_orders = wc_get_orders(
	array(
		'limit'      => -1,
		'type'       => array( 'shop_order', 'shop_order_refund' ),
		'meta_key'   => '_codex_tax_smoke_fixture',
		'meta_value' => $fixture_key,
		'return'     => 'objects',
		'status'     => array_keys( wc_get_order_statuses() ),
	)
);

foreach ( $existing_orders as $fixture_order ) {
	if ( 'shop_order' === $fixture_order->get_type() ) {
		foreach ( $fixture_order->get_refunds() as $refund ) {
			$refund->delete( true );
		}
	}
	$fixture_order->delete( true );
}

$existing_products = wc_get_products(
	array(
		'limit'      => -1,
		'meta_key'   => '_codex_tax_smoke_fixture',
		'meta_value' => $fixture_key,
		'return'     => 'objects',
		'status'     => array( 'publish', 'draft', 'private', 'trash' ),
	)
);

foreach ( $existing_products as $product ) {
	$product->delete( true );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local fixture cleanup.
$existing_rate_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name IN (%s, %s, %s)",
		'Codex UK VAT',
		'Codex DE VAT',
		'Codex Zero Rate'
	)
);

foreach ( $existing_rate_ids as $rate_id ) {
	WC_Tax::_delete_tax_rate( (int) $rate_id );
}

/*
 * Clean analytics rows left behind by earlier local fixture experiments.
 * HPOS deletes the order records, but manually-written report lookup rows
 * from failed local runs can linger and distort the tax summary.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local fixture cleanup.
$orphan_analytics_order_ids = $wpdb->get_col(
	"SELECT DISTINCT tax.order_id
	FROM {$wpdb->prefix}wc_order_tax_lookup tax
	LEFT JOIN {$wpdb->prefix}wc_orders hpos_orders ON tax.order_id = hpos_orders.id
	LEFT JOIN {$wpdb->posts} posts ON tax.order_id = posts.ID
	WHERE hpos_orders.id IS NULL AND posts.ID IS NULL"
);

if ( ! empty( $orphan_analytics_order_ids ) ) {
	$ids_sql = implode( ',', array_map( 'absint', $orphan_analytics_order_ids ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint-normalised above; local fixture cleanup.
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_tax_lookup WHERE order_id IN ({$ids_sql})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_stats WHERE order_id IN ({$ids_sql})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_product_lookup WHERE order_id IN ({$ids_sql})" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_coupon_lookup WHERE order_id IN ({$ids_sql})" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$make_tax_rate = static function ( $country, $rate, $name ) {
	return WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => $country,
			'tax_rate_state'    => '',
			'tax_rate'          => $rate,
			'tax_rate_name'     => $name,
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
};

$uk_vat_id = $make_tax_rate( 'GB', '20.0000', 'Codex UK VAT' );
$de_vat_id = $make_tax_rate( 'DE', '19.0000', 'Codex DE VAT' );

$make_product = static function ( $name, $price, $tax_status = 'taxable' ) use ( $fixture_key ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_price( (string) $price );
	$product->set_status( 'publish' );
	$product->set_tax_status( $tax_status );
	$product->set_tax_class( '' );
	$product->set_manage_stock( false );
	$product->update_meta_data( '_codex_tax_smoke_fixture', $fixture_key );
	$product->save();

	return $product;
};

$jumper   = $make_product( 'Codex Tax Smoke Wool Jumper', '100.00', 'taxable' );
$scarf    = $make_product( 'Codex Tax Smoke Merino Scarf', '80.00', 'taxable' );
$notebook = $make_product( 'Codex Tax Smoke Gift Note', '25.00', 'none' );

$make_customer = static function ( $suffix, $country, $city, $postcode ) {
	$email   = 'codex-tax-smoke-' . $suffix . '@example.test';
	$user_id = email_exists( $email );

	if ( ! $user_id ) {
		$user_id = wp_create_user( 'codex_tax_smoke_' . $suffix, wp_generate_password( 12, false ), $email );
	}

	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Could not create fixture customer: ' . esc_html( $user_id->get_error_message() ) );
	}

	foreach ( array( 'billing', 'shipping' ) as $type ) {
		update_user_meta( $user_id, "{$type}_first_name", 'Codex' );
		update_user_meta( $user_id, "{$type}_last_name", 'Fixture ' . strtoupper( $suffix ) );
		update_user_meta( $user_id, "{$type}_email", $email );
		update_user_meta( $user_id, "{$type}_address_1", '1 Demo Street' );
		update_user_meta( $user_id, "{$type}_city", $city );
		update_user_meta( $user_id, "{$type}_postcode", $postcode );
		update_user_meta( $user_id, "{$type}_country", $country );
	}

	return (int) $user_id;
};

$sync_order = static function ( $order_id ) {
	OrdersStatsStore::sync_order( $order_id );
	ProductsStatsStore::sync_order_products( $order_id );
	TaxesDataStore::sync_order_taxes( $order_id );
};

$make_order = static function ( $label, $args ) use ( $fixture_key, $now, $sync_order ) {
	$date = gmdate( 'Y-m-d H:i:s', $now - ( DAY_IN_SECONDS * (int) $args['days_ago'] ) );

	$order = wc_create_order( array( 'customer_id' => (int) $args['customer_id'] ) );
	$order->set_created_via( 'codex-tax-smoke-fixture' );
	$order->set_currency( 'GBP' );
	$order->set_payment_method( $args['payment_method'] );
	$order->set_payment_method_title( $args['payment_method_title'] );
	$order->set_date_created( $date );
	$order->update_meta_data( '_codex_tax_smoke_fixture', $fixture_key );
	$order->update_meta_data( '_codex_tax_smoke_label', $label );

	$address = array(
		'first_name' => 'Codex',
		'last_name'  => 'Fixture',
		'address_1'  => '1 Demo Street',
		'city'       => $args['city'],
		'postcode'   => $args['postcode'],
		'country'    => $args['country'],
		'email'      => 'codex-tax-smoke-' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $label ) ) . '@example.test',
	);
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );

	foreach ( $args['items'] as $item ) {
		$order->add_product( $item['product'], (int) $item['qty'] );
	}

	if ( ! empty( $args['shipping_total'] ) ) {
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Codex Smoke Flat Rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( (float) $args['shipping_total'] );
		$order->add_item( $shipping );
	}

	$order->calculate_taxes();
	$order->calculate_totals();
	$order->set_status( $args['status'] );
	$order->save();
	$sync_order( $order->get_id() );

	return $order;
};

$customers = array(
	'a' => $make_customer( 'a', 'GB', 'London', 'EC1A 1BB' ),
	'b' => $make_customer( 'b', 'GB', 'Bristol', 'BS1 4ST' ),
	'c' => $make_customer( 'c', 'DE', 'Berlin', '10115' ),
	'd' => $make_customer( 'd', 'GB', 'Manchester', 'M1 1AE' ),
	'e' => $make_customer( 'e', 'GB', 'Leeds', 'LS1 1UR' ),
);

$orders = array();

$orders['uk_paid_small'] = $make_order(
	'UK paid taxable order',
	array(
		'customer_id'          => $customers['a'],
		'days_ago'             => 5,
		'status'               => 'completed',
		'country'              => 'GB',
		'city'                 => 'London',
		'postcode'             => 'EC1A 1BB',
		'items'                => array(
			array(
				'product' => $jumper,
				'qty'     => 1,
			),
		),
		'shipping_total'       => 0.00,
		'payment_method'       => 'stripe',
		'payment_method_title' => 'Credit Card (Stripe)',
	)
);

$orders['uk_paid_shipping'] = $make_order(
	'UK paid taxable order with shipping tax',
	array(
		'customer_id'          => $customers['b'],
		'days_ago'             => 4,
		'status'               => 'processing',
		'country'              => 'GB',
		'city'                 => 'Bristol',
		'postcode'             => 'BS1 4ST',
		'items'                => array(
			array(
				'product' => $scarf,
				'qty'     => 2,
			),
		),
		'shipping_total'       => 10.00,
		'payment_method'       => 'paypal',
		'payment_method_title' => 'PayPal',
	)
);

$orders['de_paid_refunded'] = $make_order(
	'DE paid taxable order with tax refund',
	array(
		'customer_id'          => $customers['c'],
		'days_ago'             => 3,
		'status'               => 'completed',
		'country'              => 'DE',
		'city'                 => 'Berlin',
		'postcode'             => '10115',
		'items'                => array(
			array(
				'product' => $jumper,
				'qty'     => 1,
			),
		),
		'shipping_total'       => 0.00,
		'payment_method'       => 'stripe',
		'payment_method_title' => 'Credit Card (Stripe)',
	)
);

$orders['uk_on_hold'] = $make_order(
	'UK on-hold taxable order',
	array(
		'customer_id'          => $customers['d'],
		'days_ago'             => 2,
		'status'               => 'on-hold',
		'country'              => 'GB',
		'city'                 => 'Manchester',
		'postcode'             => 'M1 1AE',
		'items'                => array(
			array(
				'product' => $scarf,
				'qty'     => 1,
			),
		),
		'shipping_total'       => 0.00,
		'payment_method'       => 'bacs',
		'payment_method_title' => 'Direct bank transfer',
	)
);

$orders['non_taxed'] = $make_order(
	'Paid non-taxed order',
	array(
		'customer_id'          => $customers['e'],
		'days_ago'             => 1,
		'status'               => 'completed',
		'country'              => 'GB',
		'city'                 => 'Leeds',
		'postcode'             => 'LS1 1UR',
		'items'                => array(
			array(
				'product' => $notebook,
				'qty'     => 1,
			),
		),
		'shipping_total'       => 0.00,
		'payment_method'       => 'stripe',
		'payment_method_title' => 'Credit Card (Stripe)',
	)
);

$refunded_item_id = 0;
foreach ( $orders['de_paid_refunded']->get_items( 'line_item' ) as $item_id => $item ) {
	$refunded_item_id = (int) $item_id;
	break;
}

$refund_date = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
$refund      = wc_create_refund(
	array(
		'order_id'     => $orders['de_paid_refunded']->get_id(),
		'amount'       => 59.50,
		'reason'       => 'Codex tax smoke partial refund',
		'date_created' => $refund_date,
		'line_items'   => array(
			$refunded_item_id => array(
				'qty'          => 0,
				'refund_total' => 50.00,
				'refund_tax'   => array(
					$de_vat_id => 9.50,
				),
			),
		),
	)
);

if ( is_wp_error( $refund ) ) {
	throw new RuntimeException( 'Could not create refund: ' . esc_html( $refund->get_error_message() ) );
}

$refund->update_meta_data( '_codex_tax_smoke_fixture', $fixture_key );
$refund->save();
OrdersStatsStore::sync_order( $refund->get_id() );
TaxesDataStore::sync_order_taxes( $refund->get_id() );

wc_delete_shop_order_transients();
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Local fixture cache cleanup.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woocommerce_claude_%' OR option_name LIKE '_transient_timeout_woocommerce_claude_%'" );

WP_CLI::success(
	sprintf(
		'Seeded Woo-configured tax smoke fixture: %d products, %d standard tax rates, %d orders, and 1 tax-bearing refund. Order IDs: %s. Refund ID: %d. Rates: UK #%d, DE #%d.',
		3,
		2,
		count( $orders ),
		implode(
			', ',
			array_map(
				static function ( $order ) {
					return '#' . $order->get_id();
				},
				$orders
			)
		),
		$refund->get_id(),
		$uk_vat_id,
		$de_vat_id
	)
);
