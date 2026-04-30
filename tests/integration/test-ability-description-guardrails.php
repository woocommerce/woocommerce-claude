<?php
/**
 * Static-sweep guardrails on ability descriptions.
 *
 * Every MCP tool description in `plugin/includes/abilities/class-*-ability.php`
 * is merchant-facing prompt text. Three regressions have leaked into these
 * descriptions in past demos:
 *
 *   1. Developer-mode phrasing — "flag this as an enhancement", proposing
 *      REST endpoints or Skills directly to the merchant.
 *   2. Unshipped-tool naming — "when <skill_name> ships", pointing the
 *      merchant at a capability that doesn't exist yet.
 *   3. Stale drill-down references — `→ list_recent_orders` after we
 *      removed that tool, or `→ get_store_profile` when it was a resource-
 *      only surface. Claude follows the arrow and fails the tool call.
 *
 * These used to run as a Node script (`check-guardrails.mjs`) against
 * `mcp-server/src/server.ts` pre-TS-server removal. Re-homed here as a
 * PHPUnit integration test so it surfaces on every `./bin/check` run
 * alongside the rest of the PHP suite — no new bin/check step needed.
 *
 * See CLAUDE.md "Guardrail shape: bad/good phrasing pairs beat abstract
 * rules" → "Corollary: static sweeps as behavioural-test proxies".
 *
 * @package HeyWoo\Tests
 */

/**
 * Static-sweep tests for merchant-facing description text on all our
 * tool and prompt abilities.
 */
class Test_Ability_Description_Guardrails extends WP_UnitTestCase {

	/**
	 * Ability ID prefixes whose descriptions are scoped to this sweep.
	 *
	 * Resources (`wc-knowledge/*`) are excluded — their descriptions are
	 * static metadata and don't carry the follow-up / three-view content
	 * the three guardrails target.
	 *
	 * @var array<int, string>
	 */
	const SCOPED_PREFIXES = array( 'wc-analytics/', 'hey-woo/', 'wc-prompts/' );

	/**
	 * Subset of SCOPED_PREFIXES restricted to tool-shaped abilities —
	 * i.e. things a merchant invokes by asking a question. Excludes
	 * `wc-prompts/*` because prompt BODIES are Claude-facing instructions
	 * where "Call get_store_profile for context" is the legitimate
	 * imperative form; applying the no-invocation-verb sweep there would
	 * false-positive on correct prompt wiring.
	 *
	 * @var array<int, string>
	 */
	const TOOL_DESCRIPTION_PREFIXES = array( 'wc-analytics/', 'hey-woo/' );

	/**
	 * Tool names that may legitimately appear after a `→` follow-up arrow
	 * in a description. Two dialects coexist here on purpose:
	 *
	 * - Snake-case TS-style names (`get_revenue_summary`) survive from the
	 *   original descriptions that were ported byte-for-byte; clients map
	 *   them onto the registered MCP tool names at call time.
	 * - Kebab-case names (`woocommerce-orders-list`) cover references to
	 *   WC core MCP's built-in tools, which we don't register but are
	 *   always available on the same endpoint.
	 *
	 * When a new ability ships, add its snake-case name here so any
	 * description that recommends it as a drill-down passes the sweep.
	 *
	 * @var array<int, string>
	 */
	const REGISTERED_TOOL_REFERENCES = array(
		// wc-analytics/* — snake-case as referenced in follow-up blocks.
		'get_data',
		'confirm_large_range',
		'get_revenue_summary',
		'get_orders_summary',
		'get_product_performance',
		'get_customer_overview',
		'get_customer_value',
		'get_attribution',
		'get_revenue_breakdown',
		'get_coupon_performance',
		'get_refund_analysis',
		'get_tax_summary',
		'query_analytics',
		// hey-woo/*.
		'get_store_profile',
		'search_products',
		'get_product_details',
		'get_readiness_score',
		'get_recommendations',
		'suggest_improvements',
		// WC core MCP built-ins most likely to be suggested as drill-downs.
		'woocommerce-orders-list',
		'woocommerce-orders-get',
		'woocommerce-products-list',
		'woocommerce-products-get',
	);

	/**
	 * Iterate every in-scope ability, yielding (id, description) pairs.
	 *
	 * @return array<int, array{string, string}>
	 */
	private function scoped_descriptions() {
		$this->assertTrue(
			function_exists( 'wp_get_abilities' ),
			'wp_get_abilities() is missing — Abilities API not loaded. Requires WordPress 6.9+.'
		);

		$pairs = array();
		foreach ( wp_get_abilities() as $ability ) {
			$id = $ability->get_name();
			foreach ( self::SCOPED_PREFIXES as $prefix ) {
				if ( 0 === strpos( $id, $prefix ) ) {
					$pairs[] = array( $id, (string) $ability->get_description() );
					break;
				}
			}
		}
		$this->assertNotEmpty(
			$pairs,
			'No abilities matched the scoped prefixes — check SCOPED_PREFIXES or AbilitiesBootstrap registration.'
		);
		return $pairs;
	}

	/**
	 * Iterate every TOOL ability (excludes prompts), yielding (id, description)
	 * pairs. Use this when a guardrail only makes sense for merchant-facing
	 * tool descriptions — prompt bodies are Claude-facing instructions and
	 * have a different legitimate shape.
	 *
	 * @return array<int, array{string, string}>
	 */
	private function tool_descriptions() {
		$pairs = array();
		foreach ( $this->scoped_descriptions() as list( $id, $description ) ) {
			foreach ( self::TOOL_DESCRIPTION_PREFIXES as $prefix ) {
				if ( 0 === strpos( $id, $prefix ) ) {
					$pairs[] = array( $id, $description );
					break;
				}
			}
		}
		$this->assertNotEmpty(
			$pairs,
			'No tool abilities matched TOOL_DESCRIPTION_PREFIXES — check registration.'
		);
		return $pairs;
	}

	/**
	 * Assertion 1 — "flag this as an enhancement" pattern.
	 *
	 * Developer-mode phrasing that's leaked once before. The reader of a
	 * tool description is a merchant who can't action a "flag as an
	 * enhancement" suggestion; the tool must never propose it.
	 */
	public function test_no_flag_as_enhancement_phrasing() {
		foreach ( $this->scoped_descriptions() as list( $id, $description ) ) {
			$this->assertDoesNotMatchRegularExpression(
				'/flag\s+this\s+as\s+an\s+enhancement/i',
				$description,
				"Ability {$id}'s description contains developer-mode phrasing ('flag this as an enhancement'). "
					. 'The reader is a merchant, not the plugin developer — rewrite to point at a setting, connector, '
					. 'or honest "this isn\'t something we can answer" instead.'
			);
		}
	}

	/**
	 * Assertion 2 — "when <X> ships" pattern.
	 *
	 * Forward-reference leaks that promise a capability in an unshipped
	 * skill. Every demo where Claude said "when get_X ships" was caught
	 * by this phrasing — the skill name is almost always what follows.
	 */
	public function test_no_when_x_ships_phrasing() {
		// `\w{3,}` excludes the literal-X placeholder used by the negative-
		// example guidance inside the description itself ("when X ships").
		// A real leak would name an actual tool (3+ chars) between the two
		// anchors.
		foreach ( $this->scoped_descriptions() as list( $id, $description ) ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\bwhen\s+\w{3,}\s+ships\b/i',
				$description,
				"Ability {$id}'s description references an unshipped capability ('when … ships'). "
					. 'Rewrite to describe the shape of the missing capability in plain language and '
					. 'point at a today-action (WP Admin, a connector, a manual workflow).'
			);
		}
	}

	/**
	 * Assertion 3 — every `→ <name>` follow-up references a real tool.
	 *
	 * The follow-up-suggestions block teaches Claude what to offer next.
	 * If the referenced tool isn't registered, Claude tries to invoke a
	 * tool that doesn't exist. Scoped to the `→ <name>` pattern so
	 * "Bad:" examples (which intentionally quote hypothetical names) don't
	 * false-positive.
	 */
	public function test_follow_up_arrows_reference_registered_tools() {
		// Restrict to arrows followed by a tool-name-shaped prefix. This
		// deliberately ignores UI-navigation arrows ("Settings → Auto-tagging"),
		// parameter hints ("→ compare=true", "→ group_by=channel_source"), and
		// verb-led continuations ("→ re-run with custom dates") — none of
		// which are tool invocations.
		$tool_pattern = '/→\s+((?:get_|search_|suggest_|woocommerce-)[a-zA-Z0-9_\-]+)/u';

		$failures = array();
		foreach ( $this->scoped_descriptions() as list( $id, $description ) ) {
			if ( ! preg_match_all( $tool_pattern, $description, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $referenced ) {
				if ( ! in_array( $referenced, self::REGISTERED_TOOL_REFERENCES, true ) ) {
					$failures[] = "{$id}: → {$referenced}";
				}
			}
		}
		$this->assertEmpty(
			$failures,
			"Ability descriptions reference tools not in the registered list:\n  "
				. implode( "\n  ", $failures )
				. "\n\nEither the tool was renamed/dropped (fix the description) or "
				. 'it needs to be added to REGISTERED_TOOL_REFERENCES in this test class.'
		);
	}

	/**
	 * Assertion 4 — tool descriptions don't phrase drill-downs as tool
	 * invocations.
	 *
	 * A merchant can't run `get_attribution`, make a "next call", or pass
	 * `date_start` — they invoke tools by asking questions in English. When
	 * a tool description primes Claude with invocation-verb phrasing
	 * ("the next call is get_X", "call get_X", "run get_X"), the model
	 * mirrors it in merchant-facing responses. Fired 2026-04-21 when shot 3
	 * of the first E9/E10/E11 demo produced: *"the next call is
	 * get_attribution with Jan vs Feb date ranges — compare the traffic
	 * mix"*. No way for the merchant to action that.
	 *
	 * Scoped to `TOOL_DESCRIPTION_PREFIXES` so prompt bodies — where
	 * "Call get_store_profile for context" is the legitimate Claude-facing
	 * instruction form — aren't false-positively caught.
	 */
	public function test_no_tool_invocation_verb_phrasing() {
		$bad_patterns = array(
			// "the next call is get_X" / "next call is get_X" — the exact
			// shot-3 phrasing.
			'/\bnext\s+call\s+is\b/i',
			// "call get_X" / "run get_X" / "invoke get_X" — tool name as
			// the object of an invocation verb. `woocommerce-` covers WC
			// core MCP built-ins which can also leak as invocation targets.
			'/\b(?:call|run|invoke)\s+`?(?:get_|search_|suggest_|woocommerce-)[a-zA-Z0-9_\-]+/i',
		);

		foreach ( $this->tool_descriptions() as list( $id, $description ) ) {
			foreach ( $bad_patterns as $pattern ) {
				// Strip lines that are explicitly labelled bad/good examples
				// — those exist to prime Claude with the contrast and must
				// be allowed to quote the phrasing they're warning against.
				$prose = preg_replace( '/^\s*(?:Bad|Good)[^\n]*\n?/im', '', $description );

				$this->assertDoesNotMatchRegularExpression(
					$pattern,
					$prose,
					"Ability {$id}'s description contains tool-invocation-verb phrasing "
						. "(matched {$pattern}). Merchants invoke tools by asking questions; "
						. 'phrase drill-downs as questions ("Want me to compare the channel mix?") '
						. 'not as invocations ("The next call is get_attribution"). See the '
						. 'HOW TO OFFER FOLLOW-UPS block in class-get-customer-value-ability.php '
						. 'for the bad/good pattern.'
				);
			}
		}
	}

	/**
	 * Assertion 5 — tool descriptions don't leak the provenance of any
	 * threshold in merchant-facing narration.
	 *
	 * Thresholds (5% attention / 10% red-flag on refund rate, etc.) are
	 * guidance for Claude about when to flag a number — they aren't
	 * artefacts the merchant has access to. The first coupon+refund demo
	 * on 2026-04-22 produced "squarely in the 'red flag' zone per our
	 * own guidance", which correctly used the threshold but exposed its
	 * provenance as if the merchant shared our glossary. Same class of
	 * leak as "the next call is get_X" — developer-mode framing in
	 * merchant-facing prose, on a different axis.
	 *
	 * Strips Bad:/Good: lines first (the phrasing pairs intentionally
	 * quote the language we're guarding against).
	 */
	public function test_no_threshold_provenance_leak() {
		$bad_pattern = '/\bper\s+(?:our|the)\s+(?:own\s+)?(?:guidance|thresholds?|rules?|benchmarks?)\b/i';

		foreach ( $this->tool_descriptions() as list( $id, $description ) ) {
			// Strip lines that are explicitly labelled bad/good examples.
			$prose = preg_replace( '/^\s*(?:Bad|Good)[^\n]*\n?/im', '', $description );

			$this->assertDoesNotMatchRegularExpression(
				$bad_pattern,
				$prose,
				"Ability {$id}'s description uses 'per our guidance' / 'per our thresholds' framing. "
					. 'The merchant doesn\'t have access to our guidance and doesn\'t need to know it exists. '
					. 'State the insight as a characterisation of their numbers, not as a reference to the '
					. 'guidance that produced it. See the THRESHOLDS ARE FOR YOU block in '
					. 'class-get-refund-analysis-ability.php for the bad/good pattern.'
			);
		}
	}

	/**
	 * Assertion 6 — no parameter-name-in-parens leak.
	 *
	 * Targets the canonical `(`token`)` / `(`token`/` shape — a backticked
	 * snake_case identifier inside parentheses, the developer-shape "alias
	 * in parens" framing that leaked in E14 (PR #43 demo, shot 4: *"I can
	 * break Paid Search by keyword (`term`) or source (`google` / `bing`)"*)
	 * and again in the first `get_tax_summary` demo (shot 6: *"the `top_rates`
	 * breakdown was empty"* — backticks round a snake_case field name in
	 * narration).
	 *
	 * E14's follow-up note parked this sweep until a second demo confirmed
	 * positive priming alone wasn't enough. The trigger fired on the first
	 * `get_tax_summary` demo — different skill from the original fix
	 * (`get_revenue_breakdown`), matching the "one demo on a different
	 * skill" trigger condition. Same-day fix-forward per the
	 * "defer-and-hope-it-recurs is almost always the trap" rule in CLAUDE.md.
	 *
	 * Strips Bad:/Good: lines first — every existing description with the
	 * shape sits inside an explicit phrasing-pair example, which is the
	 * legitimate use of the pattern.
	 */
	public function test_no_parameter_in_parens_leak() {
		// `(`token`)` or `(`token` / "any continuation" — covers both
		// the original E14 shape (term / google / bing) and the simpler
		// single-token shape ((`top_rates`)).
		$bad_pattern = '/\(\s*`[a-zA-Z_][a-zA-Z0-9_]*`\s*[\/)]/';

		foreach ( $this->tool_descriptions() as list( $id, $description ) ) {
			// Strip Bad:/Good: example lines — the canonical phrasing pairs
			// quote the shape they're warning against and must be allowed.
			$prose = preg_replace( '/^\s*(?:Bad|Good)[^\n]*\n?/im', '', $description );

			$this->assertDoesNotMatchRegularExpression(
				$bad_pattern,
				$prose,
				"Ability {$id}'s description has a `(`parameter`)` developer-shape leak — "
					. 'a backticked snake_case identifier inside parentheses, which Claude has '
					. 'mirrored into merchant-facing responses in two demos (E14 / first '
					. 'get_tax_summary). Phrase parameter aliases as platform/jurisdiction '
					. 'names ("Google vs Bing") or omit the parens entirely. See the HOW TO '
					. 'OFFER FOLLOW-UPS block in class-get-revenue-breakdown-ability.php for '
					. 'the bad/good pattern.'
			);
		}
	}

	/**
	 * Assertion 7 — no standalone backticked snake_case identifier in narrative prose.
	 *
	 * Sibling to Assertion 6 (`(`token`)` shape), on a different axis.
	 * Assertion 6 catches the parenthesised developer-shape alias; this one
	 * catches the bare in-prose token — e.g. *"no rows in `top_rates`"*,
	 * *"each row has a `coverage_percent` value"*, *"the `admin_equivalent_revenue`
	 * field"*, or *"`admin_equivalent` = collected + pending"*. Same leak class
	 * (field-name mirrored into merchant-facing output) on a different surface.
	 *
	 * E15 parked this sweep after the first `get_tax_summary` demo's B5
	 * retest leaked `` `top_rates` `` in prose ("no rows in `top_rates`").
	 * Parked because (a) the exemption logic needed to separate narrative
	 * prose from legitimate description-orientation (parenthesised identifier
	 * lists, NEVER-NAME blocks, API-field-path orientation notes), and
	 * (b) PR #44 was already three commits deep. Trigger: recurrence on
	 * any skill in a future demo.
	 *
	 * FIRED 2026-04-23 — the `get_revenue_breakdown` port-verify demo
	 * produced four instances in one session (`coverage_percent`,
	 * `admin_equivalent_revenue`, `admin_equivalent` in an equation,
	 * `this_year` × 2 as parameter values), on top of the original
	 * `top_rates` leak. 5+ instances × 2 skills — trigger is definitively
	 * fired and the sweep ships.
	 *
	 * Exemption logic:
	 * - Bad:/Good: example lines — the canonical phrasing-pair shape must
	 *   be allowed to quote the leak it's warning against.
	 * - List-head definition lines (`- \`token\`: description`) — the
	 *   token is being defined, not embedded in narrative prose.
	 * - Lines carrying orientation-cue phrases (`Internal identifiers`,
	 *   `Field paths`, `API field path`, `for your reference`, `for YOUR
	 *   orientation`, `NEVER NAME`, `NEVER QUOTE`, `Rule:`, `developer
	 *   vocabulary`, `merchant-facing output`) — these phrases flag the
	 *   backticks as structural orientation, not prose to be mirrored.
	 *
	 * The regex requires lowercase start + 3+ chars + snake_case / dotted-
	 * path contents. Content with `=`, quotes, commas, spaces, or parens
	 * inside the backticks won't match (excludes `key='value'` code
	 * expressions and comma-separated lists handled by Assertion 6).
	 */
	public function test_no_standalone_backticked_identifier_in_prose() {
		$bad_pattern = '/`([a-z][a-z0-9_]{2,}(?:\.[a-z0-9_]+)*)`/';

		// Same-line orientation cues that mark a backtick as legitimate
		// structure rather than prose-to-be-mirrored. Case-sensitive on the
		// ALL-CAPS absolute rules (NEVER NAME / NEVER QUOTE) so we don't
		// false-positive on lowercase variants; case-insensitive on the rest.
		$orientation_cues = '/\b(?:Internal identifiers|Field paths|API field path|for your reference|for YOUR orientation|Rule:|developer vocabulary|merchant-facing output|NEVER NAME|NEVER QUOTE)\b/';

		foreach ( $this->tool_descriptions() as list( $id, $description ) ) {
			$lines            = preg_split( '/\R/', $description );
			$leaks            = array();
			$in_example_block = false;

			foreach ( $lines as $idx => $line ) {
				// Track multi-line Bad:/Good: examples — the quoted example
				// text can wrap across several lines. Exemption holds from
				// the `Bad:` / `Good:` header until the next blank line.
				if ( preg_match( '/^\s*(?:Bad|Good)\s*(?:\(|:)/', $line ) ) {
					$in_example_block = true;
					continue;
				}
				if ( $in_example_block ) {
					if ( preg_match( '/^\s*$/', $line ) ) {
						$in_example_block = false;
					}
					continue;
				}
				// List-head definition: `- \`token\`: ...` — the token is
				// the head of a definition, not prose content.
				if ( preg_match( '/^\s*-\s*`[a-z_][a-zA-Z0-9_.\[\]]+`/', $line ) ) {
					continue;
				}
				// Orientation-cue phrases flag the line as structural.
				if ( preg_match( $orientation_cues, $line ) ) {
					continue;
				}

				if ( preg_match_all( $bad_pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $token ) {
						$leaks[] = sprintf( 'line %d: `%s` in "%s"', $idx + 1, $token, trim( $line ) );
					}
				}
			}

			$this->assertEmpty(
				$leaks,
				sprintf(
					"Ability %s has standalone backticked snake_case identifier(s) in narrative prose:\n  %s\n\n"
						. 'A backticked field name in prose gets mirrored into merchant-facing responses '
						. '(E15 retest leaked `top_rates`; the first get_revenue_breakdown port demo leaked '
						. '`coverage_percent`, `admin_equivalent_revenue`, and more). Refactor to plain '
						. 'English (drop the backticks — "the refund rate" instead of "`refund_rate_percent`"), '
						. 'or move the token into a Bad/Good example, a list-head definition, or an '
						. 'orientation block ("Internal identifiers (...) are developer vocabulary"). '
						. 'See CLAUDE.md "Narrative-layer drift is a pre-compute trigger too" for the pattern.',
					$id,
					implode( "\n  ", $leaks )
				)
			);
		}
	}
}
