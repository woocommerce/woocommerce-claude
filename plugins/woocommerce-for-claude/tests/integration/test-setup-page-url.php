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
	 * The setup helper should target the consolidated setup overview by default.
	 */
	public function test_setup_url_targets_default_section_by_default() {
		$query = $this->query_args_from_url( SetupPage::url() );

		$this->assertSame( 'wc-settings', $query['page'] );
		$this->assertSame( SetupPage::SETTINGS_TAB, $query['tab'] );
		$this->assertArrayNotHasKey( 'section', $query );
	}

	/**
	 * Redirect notices should be preserved while staying on the consolidated setup overview.
	 */
	public function test_setup_url_preserves_extra_args_on_default_section() {
		$query = $this->query_args_from_url(
			SetupPage::url(
				array(
					'notice' => 'key_generated',
				)
			)
		);

		$this->assertArrayNotHasKey( 'section', $query );
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
