<?php
/**
 * Inventory-risk detector.
 *
 * Fires when products that have sold previously are now out of stock, or when
 * stock-managed products are at very low cover. The intent is to surface stock
 * that's actively losing sales (out-of-stock SKUs that have moved) before the
 * merchant notices via WP's noisy low-stock emails.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Detect inventory situations worth surfacing.
 */
class InventoryRiskDetector implements SignalDetectorInterface {

	/**
	 * Maximum number of at-risk products to surface in the signal evidence.
	 */
	const MAX_PRODUCTS = 5;

	/**
	 * Stock quantity at or below which a managed-stock product is treated as low.
	 */
	const LOW_STOCK_THRESHOLD = 3;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'inventory-risk';
	}

	/**
	 * {@inheritDoc}
	 */
	public function workflow_slug() {
		return 'inventory-risk-review';
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return null;
		}

		$out_of_stock = $this->find_out_of_stock_with_sales();
		$low_stock    = $this->find_low_stock_with_sales();

		if ( empty( $out_of_stock ) && empty( $low_stock ) ) {
			return null;
		}

		$has_oos    = ! empty( $out_of_stock );
		$severity   = $has_oos ? 'high' : 'medium';
		$oos_count  = count( $out_of_stock );
		$low_count  = count( $low_stock );

		if ( $has_oos ) {
			$title = sprintf(
				/* translators: %d: number of out-of-stock products that have previously sold. */
				_n(
					'%d previously-selling product is out of stock',
					'%d previously-selling products are out of stock',
					$oos_count,
					'hey-woo'
				),
				$oos_count
			);
		} else {
			$title = sprintf(
				/* translators: %d: number of low-stock products. */
				_n(
					'%d product is low on stock',
					'%d products are low on stock',
					$low_count,
					'hey-woo'
				),
				$low_count
			);
		}

		return array(
			'slug'          => $this->slug(),
			'severity'      => $severity,
			'workflow_slug' => $this->workflow_slug(),
			'title'         => $title,
			'raw_evidence'  => array(
				'currency'             => get_woocommerce_currency(),
				'out_of_stock_count'   => $oos_count,
				'low_stock_count'      => $low_count,
				'out_of_stock'         => $out_of_stock,
				'low_stock'            => $low_stock,
			),
		);
	}

	/**
	 * Out-of-stock products that have previously moved.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function find_out_of_stock_with_sales() {
		$products = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'outofstock',
				'limit'        => 50,
				'orderby'      => 'meta_value_num',
				'meta_key'     => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'        => 'DESC',
			)
		);

		return $this->summarise_products( $products, true );
	}

	/**
	 * Stock-managed products at or below the low-stock threshold.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function find_low_stock_with_sales() {
		$products = wc_get_products(
			array(
				'status'        => 'publish',
				'stock_status'  => 'instock',
				'manage_stock'  => true,
				'limit'         => 50,
				'orderby'       => 'meta_value_num',
				'meta_key'      => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'         => 'DESC',
			)
		);

		$matches = array();
		foreach ( $products as $product ) {
			$qty = $product->get_stock_quantity();
			if ( null === $qty || $qty > self::LOW_STOCK_THRESHOLD || $qty < 0 ) {
				continue;
			}

			$matches[] = $product;

			if ( count( $matches ) >= self::MAX_PRODUCTS ) {
				break;
			}
		}

		return $this->summarise_products( $matches, false );
	}

	/**
	 * Reduce a list of products to the evidence fields the runner needs.
	 *
	 * @param array<int,\WC_Product> $products       Product objects.
	 * @param bool                   $require_sales  Whether to require non-zero lifetime sales.
	 * @return array<int,array<string,mixed>>
	 */
	private function summarise_products( $products, $require_sales ) {
		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) ) {
				continue;
			}

			$total_sales = (int) $product->get_total_sales();
			if ( $require_sales && $total_sales <= 0 ) {
				continue;
			}

			$out[] = array(
				'id'             => (int) $product->get_id(),
				'name'           => (string) $product->get_name(),
				'sku'            => (string) $product->get_sku(),
				'total_sales'    => $total_sales,
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status'   => (string) $product->get_stock_status(),
				'edit_url'       => function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $product->get_id(), '' ) : '',
			);

			if ( count( $out ) >= self::MAX_PRODUCTS ) {
				break;
			}
		}

		return $out;
	}
}
