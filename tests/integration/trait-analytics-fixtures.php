<?php
/**
 * Fixture helpers for analytics integration tests.
 *
 * Creates WC customers + paid orders and forces them into the
 * `wc_order_stats` / `wc_customer_lookup` tables synchronously via
 * `DataStore::sync_order()`. WC's live-install pipeline queues that
 * sync through Action Scheduler, which doesn't run during PHPUnit —
 * this trait bridges the gap so tests can assert against freshly
 * seeded data without waiting.
 *
 * Used by every analytics ability test. Keeps per-test set_up() short
 * and makes the seed shape visible at the call site.
 *
 * @package HeyWoo\Tests
 */

namespace HeyWoo\Tests\Integration;

use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStatsStore;
use Automattic\WooCommerce\Admin\API\Reports\Products\DataStore as ProductsStatsStore;
use WC_Order;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Product_Attribute;

/**
 * Seeding helpers for analytics tests — customers, paid orders,
 * guest orders, and a multi-customer convenience seeder.
 */
trait AnalyticsFixtures {

	/**
	 * Create a registered WP user to act as a WooCommerce customer.
	 *
	 * Returns the WP user ID — NOT the `wc_customer_lookup.customer_id`
	 * assigned by WC on first order sync. Pass the WP ID straight into
	 * `create_paid_order()`; WC resolves the lookup ID internally.
	 *
	 * @param string|null $email Optional email address. Randomised when omitted.
	 * @return int WP user ID.
	 */
	protected function seed_customer( $email = null ) {
		$email = $email ? $email : ( 'customer_' . wp_rand() . '@example.test' );

		$user_id = wp_create_user(
			'user_' . wp_rand(),
			wp_generate_password( 12, false ),
			$email
		);

		if ( is_wp_error( $user_id ) ) {
			$this->fail( 'seed_customer: wp_create_user failed — ' . $user_id->get_error_message() );
		}

		return (int) $user_id;
	}

	/**
	 * Short-key → WC attribution meta_key mapping.
	 *
	 * Mirrors AnalyticsController::$attribution_meta_keys. Kept in sync
	 * with the controller by value — if the controller's list ever
	 * changes, these fixtures stop producing groupable rows and the
	 * attribution tests fail loudly.
	 *
	 * @var array<string,string>
	 */
	protected static $attribution_meta_key_map = array(
		'channel'  => '_wc_order_attribution_origin',
		'source'   => '_wc_order_attribution_utm_source',
		'medium'   => '_wc_order_attribution_utm_medium',
		'campaign' => '_wc_order_attribution_utm_campaign',
		'term'     => '_wc_order_attribution_utm_term',
		'content'  => '_wc_order_attribution_utm_content',
		'device'   => '_wc_order_attribution_device_type',
	);

	/**
	 * Create a paid order and sync it into the analytics lookup tables.
	 *
	 * Minimal shape — `total` + `date` + `customer_id` are enough to
	 * exercise the lifetime-spend SQL. Tests that need `num_items_sold`
	 * > 0 should pass a line-item product via the `items` arg. Tests that
	 * need attribution rows (get_attribution) pass short-key values via
	 * the `attribution` arg — writes route through the order's data store
	 * so the meta lands in wp_postmeta on classic storage or
	 * wp_wc_orders_meta on HPOS without the seeder having to branch.
	 *
	 * @param array $args Seed shape. Required keys: customer_id (WP user ID
	 *                   from seed_customer()), total (float). Optional keys:
	 *                   status (WC slug without 'wc-' prefix, default 'completed'),
	 *                   date ('Y-m-d H:i:s', default now), items (array of
	 *                   `[ 'product_id' => int, 'qty' => int ]` for line items),
	 *                   attribution (array keyed by short name: channel / source /
	 *                   medium / campaign / term / content / device — values are
	 *                   strings; missing keys leave no meta row for that dimension,
	 *                   which is how `include_unassigned` gets exercised).
	 * @return WC_Order The saved and synced order.
	 */
	protected function seed_paid_order( array $args ) {
		if ( empty( $args['customer_id'] ) ) {
			$this->fail( 'seed_paid_order: customer_id is required.' );
		}
		if ( ! isset( $args['total'] ) ) {
			$this->fail( 'seed_paid_order: total is required.' );
		}

		$status = isset( $args['status'] ) ? $args['status'] : 'completed';
		$date   = isset( $args['date'] ) ? $args['date'] : null;

		$order = wc_create_order( array( 'customer_id' => (int) $args['customer_id'] ) );

		if ( ! empty( $args['items'] ) ) {
			foreach ( $args['items'] as $item ) {
				$product = wc_get_product( (int) $item['product_id'] );
				$order->add_product( $product, (int) ( $item['qty'] ?? 1 ) );
			}
			$order->calculate_totals();
		} else {
			$order->set_total( (float) $args['total'] );
		}

		$order->set_status( $status );

		if ( null !== $date ) {
			$order->set_date_created( $date );
		}

		if ( ! empty( $args['attribution'] ) && is_array( $args['attribution'] ) ) {
			foreach ( $args['attribution'] as $short_key => $value ) {
				if ( ! isset( self::$attribution_meta_key_map[ $short_key ] ) ) {
					$this->fail( 'seed_paid_order: unknown attribution key "' . $short_key . '".' );
				}
				if ( '' === (string) $value ) {
					continue;
				}
				$order->update_meta_data( self::$attribution_meta_key_map[ $short_key ], (string) $value );
			}
		}

		if ( isset( $args['payment_method'] ) ) {
			$order->set_payment_method( (string) $args['payment_method'] );
		}

		if ( isset( $args['payment_method_title'] ) ) {
			$order->set_payment_method_title( (string) $args['payment_method_title'] );
		}

		$order->save();

		// OrdersStatsStore writes the wc_order_stats row. WC's live pipeline
		// queues it via Action Scheduler, which doesn't run in tests.
		OrdersStatsStore::sync_order( $order->get_id() );

		// ProductsStatsStore writes per-line rows into wc_order_product_lookup.
		// Only sync when the order carries line items — calling it on empty
		// orders perturbs WC's internal hook chain and side-effects the
		// customer lookup in ways that break customer-level tests.
		if ( ! empty( $args['items'] ) ) {
			ProductsStatsStore::sync_order_products( $order->get_id() );
		}

		// Tax fields. Synthesise the wc_order_stats.tax_total field + a
		// wc_order_tax_lookup row by hand. WC normally derives tax from cart
		// items + the active tax-rate config at checkout, neither of which
		// the test fixtures exercise — so the seeder writes the tax in
		// directly. tax_rate_id may be 0 (legacy / unmatched-rate scenario)
		// or the id returned by seed_tax_rate().
		if ( isset( $args['tax_total'] ) || isset( $args['shipping_tax'] ) ) {
			$order_tax    = isset( $args['tax_total'] ) ? (float) $args['tax_total'] : 0.0;
			$shipping_tax = isset( $args['shipping_tax'] ) ? (float) $args['shipping_tax'] : 0.0;
			$tax_rate_id  = isset( $args['tax_rate_id'] ) ? (int) $args['tax_rate_id'] : 0;
			$this->seed_order_tax_row( $order->get_id(), $order_tax, $shipping_tax, $tax_rate_id );
		}

		return $order;
	}

	/**
	 * Insert a row into wp_woocommerce_tax_rates so the tax-summary
	 * skill can join the rate metadata. Returns the new tax_rate_id.
	 *
	 * Tests use this when they need a configured tax rate. Pass the
	 * returned id straight into seed_paid_order(['tax_rate_id' => ...])
	 * so the tax_lookup row points at it.
	 *
	 * @param array $args { country (ISO-2), name, rate (string e.g. '20.0000'),
	 *                    state? (default ''), priority? (default 1), order? (default 0),
	 *                    class? (default ''), shipping? (1|0, default 1) }.
	 * @return int Auto-incremented tax_rate_id.
	 */
	protected function seed_tax_rate( array $args ) {
		global $wpdb;

		if ( empty( $args['country'] ) || empty( $args['name'] ) || ! isset( $args['rate'] ) ) {
			$this->fail( 'seed_tax_rate: country, name, rate are required.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_tax_rates',
			array(
				'tax_rate_country'  => (string) $args['country'],
				'tax_rate_state'    => isset( $args['state'] ) ? (string) $args['state'] : '',
				'tax_rate'          => (string) $args['rate'],
				'tax_rate_name'     => (string) $args['name'],
				'tax_rate_priority' => isset( $args['priority'] ) ? (int) $args['priority'] : 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => isset( $args['shipping'] ) ? (int) $args['shipping'] : 1,
				'tax_rate_order'    => isset( $args['order'] ) ? (int) $args['order'] : 0,
				'tax_rate_class'    => isset( $args['class'] ) ? (string) $args['class'] : '',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Write a wc_order_tax_lookup row + bump the wc_order_stats.tax_total
	 * column for an existing order (paid or refund). WC's live pipeline
	 * derives both from the cart at checkout; the test fixtures don't
	 * route through that path, so we set the values directly.
	 *
	 * @param int   $order_id     Order ID (parent or refund).
	 * @param float $order_tax    Line-item tax to record.
	 * @param float $shipping_tax Shipping tax to record.
	 * @param int   $tax_rate_id  Configured tax_rate_id, or 0 for unmatched.
	 * @return void
	 */
	protected function seed_order_tax_row( $order_id, $order_tax, $shipping_tax, $tax_rate_id = 0 ) {
		global $wpdb;

		$total_tax = (float) $order_tax + (float) $shipping_tax;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_tax_lookup',
			array(
				'order_id'     => (int) $order_id,
				'tax_rate_id'  => (int) $tax_rate_id,
				'date_created' => current_time( 'mysql' ),
				'shipping_tax' => (float) $shipping_tax,
				'order_tax'    => (float) $order_tax,
				'total_tax'    => (float) $total_tax,
			)
		);

		// Mirror the tax onto wc_order_stats so revenue_summary's `taxes`
		// field stays accurate too. Sync writes the row with tax_total = 0
		// because the seeded order has no cart-level tax.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->update(
			$wpdb->prefix . 'wc_order_stats',
			array( 'tax_total' => $total_tax ),
			array( 'order_id' => (int) $order_id )
		);
	}

	/**
	 * Insert a stats row with `customer_id = 0` directly, bypassing
	 * WC's sync pipeline.
	 *
	 * WC Analytics never produces a `customer_id = 0` row in normal
	 * operation — `CustomersDataStore::get_or_create_customer_from_order`
	 * always creates or reuses a lookup row, even for guests with no
	 * billing email. So to exercise the `customer_id > 0` defensive gate
	 * in the analytics SQL (which protects against orphaned rows from
	 * legacy data or broken imports), we have to write the row by hand.
	 *
	 * @param float  $total Order total (stored as net_total + total_sales).
	 * @param string $date  'Y-m-d H:i:s' timestamp.
	 * @return int The synthetic order ID.
	 */
	protected function seed_orphan_order_stats_row( $total, $date ) {
		global $wpdb;

		$order_id = wp_rand( 900000, 999999 );

		$wpdb->insert(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
			$wpdb->prefix . 'wc_order_stats',
			array(
				'order_id'         => $order_id,
				'parent_id'        => 0,
				'date_created'     => $date,
				'date_created_gmt' => $date,
				'num_items_sold'   => 1,
				'total_sales'      => (float) $total,
				'tax_total'        => 0,
				'shipping_total'   => 0,
				'net_total'        => (float) $total,
				'status'           => 'wc-completed',
				'customer_id'      => 0,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%s', '%d' )
		);

		return $order_id;
	}

	/**
	 * Create a simple product.
	 *
	 * @param array $args { name, price, sku?, category_ids?, stock_status? }.
	 * @return int Product ID.
	 */
	protected function seed_simple_product( array $args ) {
		if ( empty( $args['name'] ) || ! isset( $args['price'] ) ) {
			$this->fail( 'seed_simple_product: name and price are required.' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( $args['name'] );
		$product->set_regular_price( (string) $args['price'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );

		if ( ! empty( $args['sku'] ) ) {
			$product->set_sku( $args['sku'] );
		}
		if ( isset( $args['stock_status'] ) ) {
			$product->set_stock_status( $args['stock_status'] );
		}
		if ( ! empty( $args['category_ids'] ) ) {
			$product->set_category_ids( array_map( 'intval', $args['category_ids'] ) );
		}

		$product->save();

		return (int) $product->get_id();
	}

	/**
	 * Create a variable parent product (no variations yet — add via seed_variation()).
	 *
	 * @param array $args { name, attribute_name (default 'Size'), attribute_options
	 *                    (array of option labels), category_ids? }.
	 * @return int Variable product ID.
	 */
	protected function seed_variable_product( array $args ) {
		if ( empty( $args['name'] ) ) {
			$this->fail( 'seed_variable_product: name is required.' );
		}
		if ( empty( $args['attribute_options'] ) || ! is_array( $args['attribute_options'] ) ) {
			$this->fail( 'seed_variable_product: attribute_options must be a non-empty array.' );
		}

		$variable = new WC_Product_Variable();
		$variable->set_name( $args['name'] );
		$variable->set_status( 'publish' );
		$variable->set_catalog_visibility( 'visible' );

		if ( ! empty( $args['category_ids'] ) ) {
			$variable->set_category_ids( array_map( 'intval', $args['category_ids'] ) );
		}

		$attribute_name = isset( $args['attribute_name'] ) ? $args['attribute_name'] : 'Size';

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $attribute_name );
		$attribute->set_options( $args['attribute_options'] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$variable->set_attributes( array( $attribute ) );
		$variable->save();

		return (int) $variable->get_id();
	}

	/**
	 * Create a variation under a variable parent.
	 *
	 * Caller must ensure the `option` matches one of the parent's
	 * attribute_options so the variation is discoverable.
	 *
	 * @param int   $parent_id Variable product ID from seed_variable_product().
	 * @param array $args { option, price, sku?, attribute_name? }.
	 * @return int Variation ID.
	 */
	protected function seed_variation( $parent_id, array $args ) {
		if ( empty( $args['option'] ) || ! isset( $args['price'] ) ) {
			$this->fail( 'seed_variation: option and price are required.' );
		}

		$attribute_name = isset( $args['attribute_name'] ) ? $args['attribute_name'] : 'Size';

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( (int) $parent_id );
		$variation->set_regular_price( (string) $args['price'] );
		$variation->set_status( 'publish' );

		if ( ! empty( $args['sku'] ) ) {
			$variation->set_sku( $args['sku'] );
		}

		$variation->set_attributes( array( strtolower( $attribute_name ) => (string) $args['option'] ) );
		$variation->save();

		return (int) $variation->get_id();
	}

	/**
	 * Create a product_cat taxonomy term (idempotent on name).
	 *
	 * @param string $name Human-readable category name.
	 * @return int Term ID.
	 */
	protected function seed_product_category( $name ) {
		$existing = get_term_by( 'name', $name, 'product_cat' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, 'product_cat' );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'seed_product_category: ' . $result->get_error_message() );
		}

		return (int) $result['term_id'];
	}

	/**
	 * Create a refund (with per-line-item breakdown) against an existing order.
	 *
	 * Writes a negative-revenue row in `wc_order_product_lookup` for each
	 * refunded line — that's what `query_top_products` sums via
	 * `product_net_revenue < 0` for the per-product `refunds` column.
	 * A full-order refund (all lines, full quantities) additionally flips
	 * the parent order status to `wc-refunded` automatically.
	 *
	 * `$refunds_by_target_id` maps variation_id (for variations) or
	 * product_id (for simple products) → quantity to refund. The seeder
	 * resolves each target ID to the corresponding order item. Matches
	 * how tests already reference products when calling seed_paid_order().
	 *
	 * @param WC_Order $order                The parent order to refund against.
	 * @param array    $refunds_by_target_id Map of product_id|variation_id → qty to refund.
	 * @param array    $args                 { reason?, date? ('Y-m-d H:i:s') }.
	 * @return \WC_Order_Refund The refund sub-order.
	 */
	protected function seed_refund( $order, array $refunds_by_target_id, array $args = array() ) {
		if ( empty( $refunds_by_target_id ) ) {
			$this->fail( 'seed_refund: refunds_by_target_id must not be empty.' );
		}

		$line_items    = array();
		$refund_amount = 0.0;
		foreach ( $refunds_by_target_id as $target_id => $qty ) {
			$target_id = (int) $target_id;
			$found     = null;
			foreach ( $order->get_items() as $item_id => $item ) {
				$candidate = (int) $item->get_variation_id();
				if ( 0 === $candidate ) {
					$candidate = (int) $item->get_product_id();
				}
				if ( $candidate === $target_id ) {
					$found = array(
						'id'   => (int) $item_id,
						'item' => $item,
					);
					break;
				}
			}
			if ( null === $found ) {
				$this->fail( 'seed_refund: target product/variation ' . $target_id . ' not found on order ' . $order->get_id() );
			}
			$item     = $found['item'];
			$full_qty = (int) $item->get_quantity();
			$full_sub = (float) $item->get_subtotal();
			if ( $full_qty <= 0 ) {
				$this->fail( 'seed_refund: item ' . $found['id'] . ' has zero quantity.' );
			}
			$refund_line_total          = round( ( $full_sub / $full_qty ) * (int) $qty, 2 );
			$line_items[ $found['id'] ] = array(
				'qty'          => (int) $qty,
				'refund_total' => $refund_line_total,
				'refund_tax'   => array(),
			);
			$refund_amount             += $refund_line_total;
		}

		$refund = wc_create_refund(
			array(
				'amount'         => round( $refund_amount, 2 ),
				'reason'         => isset( $args['reason'] ) ? $args['reason'] : 'test refund',
				'order_id'       => $order->get_id(),
				'line_items'     => $line_items,
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);

		if ( is_wp_error( $refund ) ) {
			$this->fail( 'seed_refund: wc_create_refund failed — ' . $refund->get_error_message() );
		}

		if ( ! empty( $args['date'] ) ) {
			$refund->set_date_created( $args['date'] );
			$refund->save();
		}

		// Sync the refund into both analytics lookup tables. wc_create_refund
		// does not call these synchronously — without the explicit sync the
		// wc_order_stats row for the refund is missing (the controller
		// INNER JOINs stats with product_lookup, so a product_lookup row
		// with no matching stats row gets filtered out) and
		// wc_order_product_lookup has no negative-revenue row at all.
		OrdersStatsStore::sync_order( $refund->get_id() );
		ProductsStatsStore::sync_order_products( $refund->get_id() );

		if ( ! empty( $args['date'] ) ) {
			// WC's stats sync writes the row's date_created as "now" rather
			// than the refund object's date. Realign so the row falls in
			// the requested period.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
			$wpdb->update(
				$wpdb->prefix . 'wc_order_stats',
				array(
					'date_created'     => $args['date'],
					'date_created_gmt' => $args['date'],
				),
				array( 'order_id' => $refund->get_id() )
			);
		}

		return $refund;
	}
}
