<?php
/**
 * Policy Knowledge Provider.
 *
 * Exposes store policies (shipping, returns, privacy) as structured
 * knowledge for AI consumption.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

use WooCommerce\Claude\Knowledge\KnowledgeProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge provider for store policy pages — privacy, terms, refunds, shipping.
 */
class PolicyProvider implements KnowledgeProvider {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'policies';
	}

	/**
	 * Human-readable label for the provider.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Store Policies';
	}

	/**
	 * Whether this provider's data source is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Assemble the four policy page payloads.
	 *
	 * @param array $args Optional arguments (unused).
	 * @return array
	 */
	public function get_data( $args = array() ) {
		return array(
			'privacy'          => $this->get_policy_page( 'wp_page_for_privacy_policy' ),
			'terms_conditions' => $this->get_policy_page( 'woocommerce_terms_page_id' ),
			'refund_returns'   => $this->find_policy_page( array( 'refund_returns', 'refund-returns', 'returns-policy', 'return-policy', 'refund-policy' ) ),
			'shipping'         => $this->find_policy_page( array( 'shipping-policy', 'shipping-information', 'delivery-policy', 'shipping' ) ),
		);
	}

	/**
	 * Get a policy page by option name.
	 *
	 * @param string $option_name WordPress option that holds the page ID.
	 * @return array
	 */
	private function get_policy_page( $option_name ) {
		$page_id = get_option( $option_name, 0 );

		if ( ! $page_id ) {
			return array(
				'exists'  => false,
				'page_id' => null,
				'title'   => null,
				'content' => null,
				'url'     => null,
			);
		}

		return $this->extract_page_data( $page_id );
	}

	/**
	 * Find a policy page by slug.
	 *
	 * @param array $slugs Candidate page slugs to try in order.
	 * @return array
	 */
	private function find_policy_page( $slugs ) {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return $this->extract_page_data( $page->ID );
			}
		}

		return array(
			'exists'  => false,
			'page_id' => null,
			'title'   => null,
			'content' => null,
			'url'     => null,
		);
	}

	/**
	 * Extract structured data from a page.
	 *
	 * @param int $page_id Page ID to extract.
	 * @return array
	 */
	private function extract_page_data( $page_id ) {
		$page = get_post( $page_id );

		if ( ! $page || 'publish' !== $page->post_status ) {
			return array(
				'exists'  => false,
				'page_id' => $page_id,
				'title'   => null,
				'content' => null,
				'url'     => null,
			);
		}

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle -- 'the_content' is a WP core filter we invoke, not one we register.
		$content = wp_strip_all_tags( apply_filters( 'the_content', $page->post_content ) );

		return array(
			'exists'     => true,
			'page_id'    => $page_id,
			'title'      => $page->post_title,
			'content'    => $content,
			'word_count' => str_word_count( $content ),
			'url'        => get_permalink( $page_id ),
			'updated'    => $page->post_modified,
		);
	}
}
