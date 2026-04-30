<?php
/**
 * Integration test — customer-level PII gate on wc-analytics/get-customer-value.
 *
 * Pins the just-shipped privacy invariant: `hey_woo_allow_customer_pii`.
 *
 * OFF by default (option missing or falsy):
 *   `top_customers[i]` carries a pseudonymised `Customer #N` id only, with
 *   no `name` / `email` keys present. `privacy_mode` === 'pseudonymised'.
 *
 * ON (option set to a truthy value):
 *   `top_customers[i]` still carries the pseudonymised id (narration contract
 *   doesn't change), AND also exposes `name` / `email` so the list can be
 *   handed off to a CRM / email MCP (Klaviyo, Mailchimp). `privacy_mode` === 'full'.
 *
 * A regression here is a privacy incident, not a functional bug — a wiring
 * change that flipped the default or silently dropped the gate would leak
 * real customer names / emails to anyone with Abilities API access.
 *
 * The option lives at `AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII`. Using
 * the constant (rather than the raw string) makes a rename loudly break this
 * test instead of silently passing against the old name.
 *
 * @package HeyWoo\Tests
 */

use HeyWoo\Abilities\AbilitiesBootstrap;

/**
 * Integration tests for the `hey_woo_allow_customer_pii` option.
 */
class Test_Pii_Toggle extends WP_UnitTestCase {

	use \HeyWoo\Tests\Integration\AnalyticsFixtures;

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
	 * Seeded WP user ID — used to check name / email surface on the
	 * PII-on branch.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Seed one customer with real name + email + one paid in-period order.
	 * Option starts absent so each test exercises a known default.
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

		delete_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII );
	}

	/**
	 * Leave the option clean for the next test (WP_UnitTestCase's transaction
	 * rollback covers options, but explicit delete makes the contract clear).
	 */
	public function tear_down() {
		delete_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII );
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
	 * Default state — option absent — must pseudonymise.
	 *
	 * Pins three things at once:
	 *   - `privacy_mode` is 'pseudonymised' (the string the MCP tool description
	 *     checks to decide whether it's safe to pipe the list to a CRM).
	 *   - `top_customers[0]` has no `name` or `email` keys at all. Presence of
	 *     either key — even with a null value — would be a leak.
	 *   - The `id` still follows `Customer #N` so the narration contract is
	 *     intact regardless of the toggle.
	 */
	public function test_pii_off_by_default_pseudonymises_top_customers() {
		$result = $this->run_ability();

		$this->assertSame( 'pseudonymised', $result['privacy_mode'], 'Default privacy_mode must be pseudonymised when option is absent.' );
		$this->assertNotEmpty( $result['top_customers'], 'Seeded order should produce a top_customers row.' );

		$top = $result['top_customers'][0];
		$this->assertMatchesRegularExpression( '/^Customer #\d+$/', $top['id'], 'Pseudonymised id must still follow the Customer #N pattern.' );
		$this->assertArrayNotHasKey( 'name', $top, 'name key must not appear on top_customers when PII is off.' );
		$this->assertArrayNotHasKey( 'email', $top, 'email key must not appear on top_customers when PII is off.' );
	}

	/**
	 * Option ON — name and email must surface so the list can be handed off
	 * to a CRM/email MCP.
	 */
	public function test_pii_on_surfaces_name_and_email() {
		update_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII, '1' );

		$result = $this->run_ability();

		$this->assertSame( 'full', $result['privacy_mode'], 'privacy_mode must flip to full when the option is truthy.' );
		$this->assertNotEmpty( $result['top_customers'] );

		$top = $result['top_customers'][0];
		// Narration contract stays — merchants still see the pseudonymised id.
		$this->assertMatchesRegularExpression( '/^Customer #\d+$/', $top['id'] );

		$this->assertArrayHasKey( 'name', $top, 'name key must be present on top_customers when PII is on.' );
		$this->assertArrayHasKey( 'email', $top, 'email key must be present on top_customers when PII is on.' );
		$this->assertSame( 'Alice Anderson', $top['name'] );
		$this->assertSame( 'alice@example.test', $top['email'] );
	}

	/**
	 * Deleting the option must return the ability to pseudonymised output.
	 * Rules out an edge case where a stale cache, static state, or sticky
	 * sanitizer would keep the PII branch active after the toggle was off.
	 */
	public function test_pii_returns_to_pseudonymised_after_option_delete() {
		update_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII, '1' );
		delete_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII );

		$result = $this->run_ability();

		$this->assertSame( 'pseudonymised', $result['privacy_mode'] );
		$this->assertNotEmpty( $result['top_customers'] );
		$top = $result['top_customers'][0];
		$this->assertArrayNotHasKey( 'name', $top );
		$this->assertArrayNotHasKey( 'email', $top );
	}

	/**
	 * Falsy option values ('0', '', false) must also pseudonymise. The gate is
	 * a `(bool) get_option(...)` so anything falsy should land on the
	 * pseudonymised branch — if this regresses (e.g. someone flips it to a
	 * `!== false` check), the falsy-but-present case would leak PII.
	 */
	public function test_falsy_option_values_pseudonymise() {
		// Keyed by human-readable label so assertion failure messages identify
		// which falsy value tripped the regression without reaching for
		// var_export() (flagged by WordPress-Extra as debug code).
		$falsy_values = array(
			'string zero'  => '0',
			'empty string' => '',
			'bool false'   => false,
			// WC Settings checkbox persists 'no' when unchecked — plain
			// (bool) casts this to true (any non-empty string is truthy).
			// wc_string_to_bool() correctly maps 'no' → false. Pinning this
			// here so a future refactor back to (bool) loudly breaks.
			'string no'    => 'no',
		);

		foreach ( $falsy_values as $label => $falsy ) {
			update_option( AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII, $falsy );

			$result = $this->run_ability();

			$this->assertSame(
				'pseudonymised',
				$result['privacy_mode'],
				"Falsy option value ({$label}) must pseudonymise."
			);
			$top = $result['top_customers'][0];
			$this->assertArrayNotHasKey( 'name', $top, "Falsy option value ({$label}) should not surface name." );
			$this->assertArrayNotHasKey( 'email', $top, "Falsy option value ({$label}) should not surface email." );
		}
	}
}
