<?php
/**
 * Security guardrails — runtime permission sweeps + static source sniffs
 * across every registered ability and REST controller.
 *
 * Codifies the pre-merchant-pilot invariants confirmed by the 2026-04-22
 * full-surface security review. The review was clean across abilities,
 * REST controllers, knowledge providers, scoring, and bootstrap; these
 * tests pin that clean state so a regression fails `./bin/check` rather
 * than landing on main.
 *
 * Six assertions across two layers:
 *
 *   RUNTIME (via WP_Ability::check_permissions())
 *     1. Every scoped ability denies an anonymous caller.
 *     2. Every scoped ability denies a subscriber-level caller — catches
 *        weakenings like `is_user_logged_in()` that permit every authed
 *        user, not just store staff.
 *     3. Every scoped ability permits an administrator (who holds
 *        `manage_woocommerce` via WooCommerce's role bootstrap). Catches a
 *        callback over-tightened to `manage_options`-only or buggy enough
 *        to always return false / WP_Error.
 *
 *   STATIC (via source regex sweeps)
 *     4. No `permission_callback => '__return_true'` (or `__return_null`)
 *        in ability source. WP_Ability::prepare_properties() enforces that
 *        the callback is_callable at registration, but `__return_true` is
 *        a valid callable that bypasses authorisation — catch the string
 *        form by name.
 *     5. No dangerous PHP sinks (eval / shell_exec / system / passthru /
 *        popen / proc_open / unserialize) anywhere in plugin source. None
 *        are needed by the current surface; absence is the invariant.
 *     6. Every REST controller has at least one `permission_callback` key
 *        per `register_rest_route` call — catches a route handler that
 *        ships without a permission gate.
 *
 * SQL injection safety is already enforced by the WordPress-Extra PHPCS
 * sniffs (`WordPress.DB.PreparedSQL.*`) that run as step 2 of `bin/check`
 * via `composer run phpcs`. No need to duplicate that here; the
 * pre-existing `phpcs:disable` comments on the cohort / lifetime
 * IN-clause SQL in `class-get-customer-value-ability.php` and
 * `class-analytics-controller.php` are the live evidence that those
 * rules are active and biting.
 *
 * See CLAUDE.md "Guardrail shape: bad/good phrasing pairs beat abstract
 * rules" → "Corollary: static sweeps as behavioural-test proxies" for
 * the wider pattern this follows. A regex sweep of source text is
 * sub-sentence-level behavioural coverage — it won't catch a novel
 * permission bypass constructed out of correctly-callable pieces, but it
 * pins the known regression shapes.
 *
 * @package HeyWoo\Tests
 */

/**
 * Static-sweep + runtime tests for security invariants across the
 * plugin's ability and REST surface.
 */
class Test_Ability_Security_Guardrails extends WP_UnitTestCase {

	/**
	 * Ability ID prefixes whose permission gates this sweep covers.
	 *
	 * Mirrors `Test_Ability_Description_Guardrails::SCOPED_PREFIXES` but
	 * includes `wc-knowledge/*` — those resource-shaped abilities also
	 * register a `permission_callback` and are reachable over both MCP
	 * (as resources) and the `wp-abilities/v1` REST surface, so their
	 * gates matter as much as tools' gates.
	 *
	 * @var array<int, string>
	 */
	const SCOPED_PREFIXES = array(
		'wc-analytics/',
		'hey-woo/',
		'wc-knowledge/',
		'wc-prompts/',
	);

	/**
	 * Iterate every scoped ability, yielding (id, WP_Ability) pairs.
	 *
	 * @return array<int, array{string, WP_Ability}>
	 */
	private function scoped_abilities() {
		$this->assertTrue(
			function_exists( 'wp_get_abilities' ),
			'wp_get_abilities() is missing — Abilities API not loaded. Requires WordPress 6.9+.'
		);

		$pairs = array();
		foreach ( wp_get_abilities() as $ability ) {
			$id = $ability->get_name();
			foreach ( self::SCOPED_PREFIXES as $prefix ) {
				if ( 0 === strpos( $id, $prefix ) ) {
					$pairs[] = array( $id, $ability );
					break;
				}
			}
		}
		$this->assertNotEmpty(
			$pairs,
			'No abilities matched the scoped prefixes — check AbilitiesBootstrap registration or update SCOPED_PREFIXES.'
		);
		return $pairs;
	}

	/**
	 * Enumerate every ability source file.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function ability_source_files() {
		$files = glob( HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-*.php' );
		$this->assertNotEmpty(
			$files,
			'No ability source files found under ' . HEY_WOO_PLUGIN_DIR . 'includes/abilities/.'
		);
		return $files;
	}

	/**
	 * Enumerate every REST controller source file.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function rest_controller_source_files() {
		$files = glob( HEY_WOO_PLUGIN_DIR . 'includes/api/class-*-controller.php' );
		$this->assertNotEmpty(
			$files,
			'No REST controller source files found under ' . HEY_WOO_PLUGIN_DIR . 'includes/api/.'
		);
		return $files;
	}

	/**
	 * Enumerate every plugin PHP source file under includes/ (recursive).
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function plugin_source_files() {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( HEY_WOO_PLUGIN_DIR . 'includes/' )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$this->assertNotEmpty( $files, 'No plugin PHP source files found under includes/.' );
		return $files;
	}

	/**
	 * Read a file's source with comments and docblocks stripped.
	 *
	 * Security sweeps apply to executable code, not prose — an `eval` in a
	 * comment ("// could eval this, but that would be unsafe") is
	 * documentation, not a vulnerability.
	 *
	 * @param string $file Absolute path.
	 * @return string Source with comments removed.
	 */
	private function source_without_comments( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file by absolute path; wp_remote_get() is for HTTP.
		$source   = file_get_contents( $file );
		$stripped = preg_replace( '#/\*.*?\*/#s', '', $source );
		$stripped = preg_replace( '#//[^\n]*#', '', $stripped );
		$stripped = preg_replace( '/^\s*#[^\n]*/m', '', $stripped );
		return (string) $stripped;
	}

	/**
	 * Assertion 1 (runtime) — anonymous callers are denied.
	 *
	 * Every ability's permission gate must reject a caller with no WP user
	 * context. `check_permissions()` returns `true | false | WP_Error` — any
	 * non-`true` result means denied, which is the safe outcome here.
	 */
	public function test_abilities_deny_anonymous_user() {
		wp_set_current_user( 0 );

		foreach ( $this->scoped_abilities() as list( $id, $ability ) ) {
			$result = $ability->check_permissions();
			$this->assertNotSame(
				true,
				$result,
				"Ability {$id} granted access to an anonymous caller. "
					. 'Every ability must require an authenticated merchant-level user '
					. '(`current_user_can(\'manage_woocommerce\')`). A `__return_true` '
					. 'or missing capability check would present as this failure.'
			);
		}
	}

	/**
	 * Assertion 2 (runtime) — subscriber-level users are denied.
	 *
	 * Catches weakenings like `is_user_logged_in()` or `current_user_can('read')`
	 * that permit any authenticated user. Subscribers are typical shoppers,
	 * not store staff — they must not see store analytics.
	 */
	public function test_abilities_deny_subscriber_user() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse(
			current_user_can( 'manage_woocommerce' ),
			'Test precondition: subscriber must not have manage_woocommerce. '
				. 'Check WooCommerce role bootstrap and subscriber role caps.'
		);

		foreach ( $this->scoped_abilities() as list( $id, $ability ) ) {
			$result = $ability->check_permissions();
			$this->assertNotSame(
				true,
				$result,
				"Ability {$id} granted access to a subscriber. "
					. 'The permission callback appears to require logged-in status only, '
					. 'not shop-staff capability. Gate with `current_user_can(\'manage_woocommerce\')`.'
			);
		}
	}

	/**
	 * Assertion 3 (runtime) — administrators are permitted.
	 *
	 * Not a security assertion in the "keep attackers out" sense, but the
	 * cheapest possible time to catch a callback that's been over-tightened
	 * (e.g. changed to `manage_options` only, or always returns WP_Error).
	 * Would present as a merchant-facing "this tool doesn\'t work on my
	 * store" bug for the bulk of shop admins.
	 *
	 * Administrators hold `manage_woocommerce` via WooCommerce's role bootstrap,
	 * which the test env runs explicitly in bootstrap.php.
	 */
	public function test_abilities_permit_administrator_user() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertTrue(
			current_user_can( 'manage_woocommerce' ),
			'Test precondition: administrator must hold manage_woocommerce. '
				. 'WooCommerce role bootstrap did not run in the test env — check bootstrap.php.'
		);

		foreach ( $this->scoped_abilities() as list( $id, $ability ) ) {
			$result = $ability->check_permissions();
			$this->assertTrue(
				$result,
				"Ability {$id} denied an administrator who holds manage_woocommerce. "
					. 'Permission callback is likely over-tightened (e.g. to `manage_options`) '
					. 'or returning WP_Error unintentionally. Expected exact `true`.'
			);
		}
	}

	/**
	 * Assertion 4 (static) — no `__return_true` / `__return_null` permission
	 * callbacks in ability source.
	 *
	 * WP_Ability::prepare_properties() enforces `is_callable($permission_callback)`
	 * at registration, so a totally-missing callback fails fast. But
	 * `__return_true` IS callable and ALWAYS returns true — a semantic
	 * bypass dressed as a valid callback. Catch the specific bad string
	 * form before it reaches runtime.
	 *
	 * The runtime sweeps above catch semantic bypasses (a custom function
	 * that always returns true) too; this static check is the belt to
	 * those braces.
	 */
	public function test_no_return_true_permission_callbacks() {
		$bad_patterns = array(
			'/[\'"]permission_callback[\'"]\s*=>\s*[\'"]__return_true[\'"]/i'   => '__return_true',
			'/[\'"]permission_callback[\'"]\s*=>\s*[\'"]__return_null[\'"]/i'   => '__return_null',
			'/[\'"]permission_callback[\'"]\s*=>\s*[\'"]is_user_logged_in[\'"]/i' => 'is_user_logged_in',
		);

		$failures = array();
		foreach ( $this->ability_source_files() as $file ) {
			$source = $this->source_without_comments( $file );
			foreach ( $bad_patterns as $pattern => $label ) {
				if ( preg_match( $pattern, $source ) ) {
					$failures[] = basename( $file ) . ' uses permission_callback => ' . $label;
				}
			}
		}

		$this->assertEmpty(
			$failures,
			"Ability source registers a permission_callback that bypasses or under-gates authorisation:\n  "
				. implode( "\n  ", $failures )
				. "\n\nReplace with a callback that checks `current_user_can('manage_woocommerce')` "
				. 'for the ability\'s operation. See class-get-revenue-summary-ability.php for the canonical shape.'
		);
	}

	/**
	 * Assertion 5 (static) — no dangerous PHP sinks in plugin source.
	 *
	 * None of these are needed by the current analytics / knowledge /
	 * scoring surface. A future change that introduces one must be a
	 * deliberate, reviewed call — not a drive-by addition that slips past
	 * visual PR review.
	 *
	 * If a genuine need appears (extremely unlikely for an analytics
	 * plugin), the author updates this test with an explicit exception and
	 * an inline justification.
	 *
	 * The regex anchors with `\b` on the function name so namespace-
	 * separator calls (`\eval(`) and method calls to like-named methods
	 * on our own classes (`$ability->execute(`) don\'t cross-contaminate:
	 * `execute` ≠ `exec` under word-boundary matching.
	 */
	public function test_no_dangerous_php_sinks() {
		$dangerous_patterns = array(
			'eval()'        => '/\beval\s*\(/',
			'shell_exec()'  => '/\bshell_exec\s*\(/',
			'exec()'        => '/\bexec\s*\(/',
			'system()'      => '/\bsystem\s*\(/',
			'passthru()'    => '/\bpassthru\s*\(/',
			'popen()'       => '/\bpopen\s*\(/',
			'proc_open()'   => '/\bproc_open\s*\(/',
			'pcntl_exec()'  => '/\bpcntl_exec\s*\(/',
			'unserialize()' => '/\bunserialize\s*\(/',
		);

		$failures = array();
		foreach ( $this->plugin_source_files() as $file ) {
			$source   = $this->source_without_comments( $file );
			$relative = ltrim( str_replace( HEY_WOO_PLUGIN_DIR, '', $file ), '/' );

			foreach ( $dangerous_patterns as $name => $pattern ) {
				if ( preg_match( $pattern, $source ) ) {
					$failures[] = $relative . ' contains ' . $name;
				}
			}
		}

		$this->assertEmpty(
			$failures,
			"Plugin source contains dangerous PHP sinks:\n  "
				. implode( "\n  ", $failures )
				. "\n\nNone of these are needed by the current analytics / knowledge surface. "
				. 'If a genuine need exists, justify it inline and add an explicit exception to this test.'
		);
	}

	/**
	 * Assertion 6 (static) — every REST controller defines at least one
	 * `permission_callback` per `register_rest_route` call.
	 *
	 * A route registration missing the key defaults to public access in
	 * some WP versions and is never the intent here. Count-based check
	 * rather than AST-based: if a controller has N register_rest_route
	 * calls, it must have ≥ N permission_callback keys (one per handler).
	 *
	 * False-positive tolerance: legitimate multi-method handlers (GET+POST
	 * on one route) have one permission_callback each, so the count stays
	 * balanced. Extra permission_callback keys beyond the route count are
	 * harmless.
	 */
	public function test_rest_controllers_gate_every_route() {
		$failures = array();
		foreach ( $this->rest_controller_source_files() as $file ) {
			$source      = $this->source_without_comments( $file );
			$route_count = preg_match_all( '/\bregister_rest_route\s*\(/', $source );
			$perm_count  = preg_match_all( '/[\'"]permission_callback[\'"]\s*=>/', $source );

			if ( $route_count > $perm_count ) {
				$failures[] = sprintf(
					'%s: %d register_rest_route call(s) vs %d permission_callback key(s)',
					basename( $file ),
					$route_count,
					$perm_count
				);
			}
		}

		$this->assertEmpty(
			$failures,
			"REST controllers are missing permission_callback on one or more route handlers:\n  "
				. implode( "\n  ", $failures )
				. "\n\nEvery register_rest_route() handler array must set 'permission_callback'. "
				. 'Use `array( __CLASS__, \'check_permission\' )` with a method returning '
				. '`current_user_can(\'manage_woocommerce\')` — see class-store-controller.php for the shape.'
		);
	}
}
