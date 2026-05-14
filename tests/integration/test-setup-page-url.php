<?php
/**
 * Integration tests for SetupPage URL generation.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Setup\SetupPage;

/**
 * Tests for SetupPage::url().
 */
class Test_Setup_Page_Url extends WP_UnitTestCase {

	/**
	 * The setup helper should target the DIY section now that AI Insights
	 * owns the default section.
	 */
	public function test_setup_url_targets_setup_section_by_default() {
		$query = $this->query_args_from_url( SetupPage::url() );

		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( SetupPage::SETTINGS_TAB, $query['tab'] );
		$this->assertSame( 'setup', $query['section'] );
	}

	/**
	 * Redirect notices should be preserved while staying on the DIY section.
	 */
	public function test_setup_url_preserves_extra_args_on_setup_section() {
		$query = $this->query_args_from_url(
			SetupPage::url(
				array(
					'notice' => 'key_generated',
				)
			)
		);

		$this->assertSame( 'setup', $query['section'] );
		$this->assertSame( 'key_generated', $query['notice'] );
	}

	/**
	 * Parse query arguments from a URL.
	 *
	 * @param string $url URL to inspect.
	 * @return array<string,string>
	 */
	private function query_args_from_url( $url ) {
		$query_string = wp_parse_url( $url, PHP_URL_QUERY );
		$query        = array();
		parse_str( is_string( $query_string ) ? $query_string : '', $query );

		return $query;
	}
}
