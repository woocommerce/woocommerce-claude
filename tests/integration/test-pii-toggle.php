<?php
/**
 * Integration test — customer-level PII is never surfaced by
 * `wc-analytics/get-customer-value`.
 *
 * Pins the privacy invariant after the `woocommerce_claude_allow_customer_pii` toggle
 * was removed: top_customers ALWAYS pseudonymises and NEVER returns name
 * or email, regardless of any leftover option value a previous version of
 * the plugin (or a stray `update_option` call from another extension) may
 * have left behind.
 *
 * A regression here is a privacy incident, not a functional bug — a wiring
 * change that revived the old gate would silently leak real customer
 * names / emails to anyone with Abilities API access.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the (no longer toggleable) PII surface on
 * get-customer-value.
 */
class Test_Pii_Toggle extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Fixture period start. Fixed historical window so assertions are
	 * stable regardless of the clock.
	 *
	 * @var string
	 */
	private $period_start = '2025-10-01';

	/**
	 * Fixture period end.
	 *
	 * @var string
	 */
	private $period_end = '2025-10-31';

	/**
	 * Seeded WP user ID.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Seed one customer with real name + email + one paid in-period order.
	 * If any of these surface in a response, the assertion will catch it.
	 */
	public function set_up() {
		parent::set_up();

		$this->user_id = $this->seed_customer( 'alice@example.test' );
		update_user_meta( $this->user_id, 'first_name', 'Alice' );
		update_user_meta( $this->user_id, 'last_name', 'Anderson' );

		$order = $this->seed_paid_order(
			array(
				'customer_id' => $this->user_id,
				'total'       => 100.00,
				'date'        => '2025-10-10 10:00:00',
			)
		);

		// Belt-and-braces: WC's customer_lookup sync picks up first_name /
		// last_name / email from either the registered user or the order's
		// billing address, and which wins varies between WC versions.
		// Writing both and re-syncing once makes the lookup row deterministic.
		$order->set_billing_first_name( 'Alice' );
		$order->set_billing_last_name( 'Anderson' );
		$order->set_billing_email( 'alice@example.test' );
		$order->save();
		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order->get_id() );

		delete_option( 'woocommerce_claude_allow_customer_pii' );
	}

	/**
	 * Leave the option clean for the next test (WP_UnitTestCase's transaction
	 * rollback covers options, but explicit delete makes the contract clear).
	 */
	public function tear_down() {
		delete_option( 'woocommerce_claude_allow_customer_pii' );
		parent::tear_down();
	}

	/**
	 * Execute the ability over the fixture period as an administrator.
	 * Cohorts and comparison are off — not exercised by these tests.
	 *
	 * @return array Ability result.
	 */
	private function run_ability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability = wp_get_ability( 'wc-analytics/get-customer-value' );
		$this->assertNotNull( $ability, 'get-customer-value ability was not registered.' );

		$result = $ability->execute(
			array(
				'period'          => 'last_30_days',
				'date_start'      => $this->period_start,
				'date_end'        => $this->period_end,
				'compare'         => false,
				'limit'           => 10,
				'include_cohorts' => false,
			)
		);

		$this->assertFalse(
			is_wp_error( $result ),
			'Ability returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Default state — no leftover option — must pseudonymise.
	 *
	 * Pins three things at once:
	 *   - `privacy_mode` is 'pseudonymised' (the string the MCP tool description
	 *     checks to decide whether it's safe to pipe the list to a CRM).
	 *   - `top_customers[0]` has no `name` or `email` keys at all. Presence of
	 *     either key — even with a null value — would be a leak.
	 *   - The `id` still follows `Customer #N` so the narration contract is
	 *     intact.
	 */
	public function test_pseudonymises_top_customers_by_default() {
		$result = $this->run_ability();

		$this->assertSame( 'pseudonymised', $result['privacy_mode'], 'privacy_mode must always be pseudonymised.' );
		$this->assertNotEmpty( $result['top_customers'], 'Seeded order should produce a top_customers row.' );

		$top = $result['top_customers'][0];
		$this->assertMatchesRegularExpression( '/^Customer #\d+$/', $top['id'], 'Pseudonymised id must follow the Customer #N pattern.' );
		$this->assertArrayNotHasKey( 'name', $top, 'name key must never appear on top_customers.' );
		$this->assertArrayNotHasKey( 'email', $top, 'email key must never appear on top_customers.' );
	}

	/**
	 * Privacy invariant — even if a leftover `woocommerce_claude_allow_customer_pii`
	 * option exists from a previous version of the plugin, it must not
	 * revive PII surfacing. Defends against a future refactor that
	 * accidentally restores the gate by reading the stale option.
	 */
	public function test_legacy_truthy_option_does_not_leak_pii() {
		$truthy_values = array(
			'string yes'  => 'yes',
			'string one'  => '1',
			'string true' => 'true',
			'bool true'   => true,
			'int one'     => 1,
		);

		foreach ( $truthy_values as $label => $truthy ) {
			update_option( 'woocommerce_claude_allow_customer_pii', $truthy );

			$result = $this->run_ability();

			$this->assertSame(
				'pseudonymised',
				$result['privacy_mode'],
				"Leftover option value ({$label}) must not flip privacy_mode."
			);
			$top = $result['top_customers'][0];
			$this->assertArrayNotHasKey( 'name', $top, "Leftover option value ({$label}) must not surface name." );
			$this->assertArrayNotHasKey( 'email', $top, "Leftover option value ({$label}) must not surface email." );
		}
	}

	/**
	 * The seeded fixture's name and email must not appear ANYWHERE in the
	 * encoded response. Belt-and-braces against a future refactor that
	 * adds a new key (e.g. `customer_label`, `display_name`) and
	 * inadvertently includes the real name there.
	 */
	public function test_response_payload_contains_no_real_name_or_email() {
		$result  = $this->run_ability();
		$encoded = wp_json_encode( $result );

		$this->assertStringNotContainsString( 'Alice', $encoded, 'Real first name must not appear anywhere in the response.' );
		$this->assertStringNotContainsString( 'Anderson', $encoded, 'Real last name must not appear anywhere in the response.' );
		$this->assertStringNotContainsString( 'alice@example.test', $encoded, 'Real email must not appear anywhere in the response.' );
	}
}
