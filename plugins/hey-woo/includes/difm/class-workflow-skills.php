<?php
/**
 * Workflow skill loader for Hey Woo.
 *
 * The companion agent plugin reads the same per-workflow SKILL.md files directly.
 * Hey Woo runs inside WordPress, so it needs a lightweight PHP loader that
 * can select one workflow and inject only that workflow into the Anthropic
 * system prompt for the current turn.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and routes the static workflow skill markdown files.
 */
class WorkflowSkills {

	/**
	 * Cached parsed workflow definitions.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static $workflows = null;

	/**
	 * Return all readable workflow definitions, keyed by slug.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all() {
		if ( null !== self::$workflows ) {
			return self::$workflows;
		}

		self::$workflows = array();
		$files           = glob( HEY_WOO_PLUGIN_DIR . 'skills/*/SKILL.md' );

		if ( ! is_array( $files ) ) {
			return self::$workflows;
		}

		sort( $files );
		foreach ( $files as $file ) {
			$workflow = self::parse_file( $file );
			if ( empty( $workflow['slug'] ) ) {
				continue;
			}

			self::$workflows[ $workflow['slug'] ] = $workflow;
		}

		return self::$workflows;
	}

	/**
	 * Return one workflow definition by slug.
	 *
	 * @param string $slug Workflow slug.
	 * @return array<string,string>|null
	 */
	public static function get( $slug ) {
		$slug      = sanitize_key( (string) $slug );
		$workflows = self::all();

		return isset( $workflows[ $slug ] ) ? $workflows[ $slug ] : null;
	}

	/**
	 * Select the best workflow for a merchant message.
	 *
	 * Explicit slash-style commands win. Natural-language routing is deliberately
	 * deterministic so the first model call can receive the selected workflow
	 * without spending an extra classifier call.
	 *
	 * @param string $message Merchant message.
	 * @return array<string,string>|null
	 */
	public static function select_for_message( $message ) {
		$message  = trim( (string) $message );
		$workflow = self::select_command_workflow( $message );

		if ( is_array( $workflow ) ) {
			return $workflow;
		}

		$normalised = self::normalise_message( $message );
		if ( '' === $normalised ) {
			return null;
		}

		foreach ( self::intent_patterns() as $slug => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $normalised ) ) {
					$workflow = self::get( $slug );
					if ( is_array( $workflow ) ) {
						$workflow['match'] = 'intent';
						return $workflow;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Return the Anthropic-ready workflow instructions for Hey Woo.
	 *
	 * @param array<string,string> $workflow Parsed workflow.
	 * @return string
	 */
	public static function prompt_for_difm( array $workflow ) {
		$name        = isset( $workflow['name'] ) ? (string) $workflow['name'] : '';
		$description = isset( $workflow['description'] ) ? (string) $workflow['description'] : '';
		$body        = isset( $workflow['body'] ) ? (string) $workflow['body'] : '';
		$description = self::translate_tool_references( $description );
		$body        = self::translate_tool_references( $body );

		return trim(
			"## Selected workflow: {$name}\n\n"
			. "The merchant's latest message matches this WooCommerce workflow. Follow these instructions for this turn. Use the available Hey Woo tools quietly, and do not mention workflow names, tool names, parameter names, or implementation details in the merchant-facing answer.\n\n"
			. "Workflow description: {$description}\n\n"
			. $body
		);
	}

	/**
	 * Parse a SKILL.md file.
	 *
	 * @param string $file Absolute file path.
	 * @return array<string,string>
	 */
	private static function parse_file( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads plugin-local markdown, not a remote URL.
		$contents = is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( ! is_string( $contents ) || '' === $contents ) {
			return array();
		}

		if ( ! preg_match( '/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $contents, $matches ) ) {
			return array();
		}

		$frontmatter = self::parse_frontmatter( $matches[1] );
		$name        = isset( $frontmatter['name'] ) ? sanitize_key( $frontmatter['name'] ) : '';
		if ( '' === $name ) {
			return array();
		}

		return array(
			'slug'        => $name,
			'name'        => $name,
			'description' => isset( $frontmatter['description'] ) ? trim( (string) $frontmatter['description'] ) : '',
			'body'        => trim( (string) $matches[2] ),
			'file'        => (string) $file,
		);
	}

	/**
	 * Parse the simple key/value frontmatter used by workflow skill files.
	 *
	 * @param string $frontmatter Frontmatter block.
	 * @return array<string,string>
	 */
	private static function parse_frontmatter( $frontmatter ) {
		$parsed = array();
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $frontmatter );

		if ( ! is_array( $lines ) ) {
			return $parsed;
		}

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $matches ) ) {
				continue;
			}

			$key            = strtolower( $matches[1] );
			$value          = trim( $matches[2] );
			$value          = trim( $value, "\"'" );
			$parsed[ $key ] = $value;
		}

		return $parsed;
	}

	/**
	 * Select an explicitly requested slash-command workflow.
	 *
	 * @param string $message Merchant message.
	 * @return array<string,string>|null
	 */
	private static function select_command_workflow( $message ) {
		if ( 1 !== preg_match( '/^\s*\/(?:hey-woo:)?([a-z0-9-]+)\b/i', (string) $message, $matches ) ) {
			return null;
		}

		$workflow = self::get( $matches[1] );
		if ( ! is_array( $workflow ) ) {
			return null;
		}

		$workflow['match'] = 'command';
		return $workflow;
	}

	/**
	 * Normalise merchant text for deterministic intent routing.
	 *
	 * @param string $message Merchant message.
	 * @return string
	 */
	private static function normalise_message( $message ) {
		$message = strtolower( wp_strip_all_tags( (string) $message ) );
		$message = preg_replace( '/[^a-z0-9\/\s-]+/', ' ', $message );
		$message = preg_replace( '/\s+/', ' ', (string) $message );

		return trim( (string) $message );
	}

	/**
	 * Intent patterns keyed by workflow slug.
	 *
	 * @return array<string,string[]>
	 */
	private static function intent_patterns() {
		return array(
			'weekly-store-review'            => array(
				'/\bweekly store review\b/',
				'/\bweekly review\b/',
				'/\bhow did my store do this week\b/',
				'/\bstore did this week\b/',
				'/\blast[- ]?7[- ]?days?\b.*\b(performance|review|summary)\b/',
				'/\bweekly performance summary\b/',
			),
			'revenue-drop-triage'            => array(
				'/\b(revenue|sales)\b.*\b(down|dropped|drop|decline|declined|dip|soft)\b/',
				'/\bwhy\b.*\b(revenue|sales)\b.*\b(down|dropped|drop|decline|declined|dip)\b/',
				'/\brevenue[- ]?drop triage\b/',
				'/\bmonth[- ]?over[- ]?month\b.*\b(revenue|sales|decline|drop)\b/',
				'/\bweek[- ]?over[- ]?week\b.*\b(revenue|sales|decline|drop)\b/',
			),
			'failed-order-triage'            => array(
				'/\bfailed orders?\b/',
				'/\bon[- ]?hold orders?\b/',
				'/\bstuck payments?\b/',
				'/\bunpaid orders?\b/',
				'/\bpayment pipeline\b/',
				'/\bcheckout failures?\b/',
				'/\borders?\b.*\b(chase|chasing)\b/',
			),
			'refund-triage'                  => array(
				'/\brefund triage\b/',
				'/\btriage\b.*\b(refunds?|returns?)\b/',
				'/\breview\b.*\b(refunds?|returns?)\b/',
				'/\b(refunds?|returns?)\b.*\b(triage|driving|spike|spikes|worse|high|rate|leakage)\b/',
				'/\bwhat is driving refunds?\b/',
				'/\brefunded (products?|countries)\b/',
			),
			'coupon-performance-triage'      => array(
				'/\bcoupons?\b.*\b(working|performing|performance|attention)\b/',
				'/\bcoupon performance\b/',
				'/\bdiscount codes?\b/',
				'/\bdiscounting\b.*\b(margin|cost|working|performance)\b/',
				'/\bpromotion cost\b/',
			),
			'tax-reconciliation'             => array(
				'/\btax reconciliation\b/',
				'/\btax collected\b/',
				'/\b(vat|sales tax|tax returns?)\b/',
				'/\bshipping tax\b/',
				'/\brefunded tax\b/',
				'/\bpending tax\b/',
				'/\btax\b.*\b(rate|jurisdiction|figures|admin|reconcile)\b/',
			),
			'customer-value-review'          => array(
				'/\bcustomer lifetime value\b/',
				'/\bcustomer value\b/',
				'/\bltv\b/',
				'/\bbest customers?\b/',
				'/\brepeat buyers?\b/',
				'/\bone[- ]?time\b.*\brepeat\b/',
				'/\bcohort retention\b/',
				'/\breorder cadence\b/',
				'/\bloyalty opportunit/',
			),
			'customer-acquisition-review'    => array(
				'/\bcustomer acquisition\b/',
				'/\bnew customers?\b.*\b(growing|growth|acquired|acquisition|channels?)\b/',
				'/\bfirst[- ]?time customers?\b/',
				'/\bnew\b.*\breturning customers?\b/',
				'/\bchannels?\b.*\bbrought\b.*\b(first[- ]?time|new) customers?\b/',
			),
			'product-performance-review'     => array(
				'/\bproduct performance\b/',
				'/\btop products?\b/',
				'/\bbest[- ]?selling products?\b/',
				'/\bproduct mix\b/',
				'/\bproducts?\b.*\b(performing|performed|dropped out|not move|moving|carrying sales)\b/',
			),
			'catalogue-merchandising-review' => array(
				'/\bcatalogue merchandising\b/',
				'/\bcatalog merchandising\b/',
				'/\bmerchandis(e|ing)\b/',
				'/\bslow movers?\b/',
				'/\bproducts?\b.*\b(feature|de[- ]?emphasise|refresh)\b/',
				'/\bproduct pages?\b.*\b(improve|attention|work)\b/',
			),
			'inventory-risk-review'          => array(
				'/\binventory risk\b/',
				'/\bstock risk\b/',
				'/\bout[- ]?of[- ]?stock\b/',
				'/\blow[- ]?stock\b/',
				'/\brestock\b/',
				'/\bsale[- ]?priced\b.*\bstock\b/',
			),
			'channel-performance-review'     => array(
				'/\bchannel performance\b/',
				'/\bwhich channels?\b/',
				'/\b(channels?|sources?|media|campaigns?|devices?)\b.*\b(driving|performing|revenue|customers?|pipeline)\b/',
				'/\battribution\b/',
				'/\butm\b/',
			),
			'geography-performance-review'   => array(
				'/\bgeograph(y|ic)\b/',
				'/\bbilling countr(y|ies)\b/',
				'/\bcountr(y|ies)\b.*\b(revenue|orders?|customers?|refunds?|mix|driving)\b/',
				'/\bregional\b.*\b(revenue|orders?|customers?|mix)\b/',
			),
			'shipping-method-review'         => array(
				'/\bshipping methods?\b/',
				'/\bshipping mix\b/',
				'/\bshipping charged\b/',
				'/\bshipping\b.*\b(revenue|orders?|pipeline|refunds?|settings)\b/',
			),
			'payment-method-review'          => array(
				'/\bpayment methods?\b/',
				'/\bpayment mix\b/',
				'/\bgateways?\b.*\b(revenue|performing|pipeline|checking|problems?)\b/',
				'/\bpayment labels?\b/',
				'/\bpayments?\b.*\b(settings|gateway|method)\b/',
			),
			'catalog-audit'                  => array(
				'/\bcatalog(ue)? audit\b/',
				'/\bai readiness audit\b/',
				'/\breadiness audit\b/',
				'/\baudit\b.*\b(product catalog|product catalogue|catalog|catalogue)\b/',
			),
			'store-health-monitor'           => array(
				'/\bstore health\b/',
				'/\bhealth monitor\b/',
				'/\bcommon issues\b/',
				'/\bmissing images\b/',
				'/\bpricing gaps\b/',
			),
			'product-content-generator'      => array(
				'/\bproduct content\b/',
				'/\bgenerate\b.*\b(description|faq|faqs|seo|alt text)\b/',
				'/\bimprove\b.*\b(product description|product content|seo metadata|alt text)\b/',
				'/\bwrite\b.*\b(product description|faqs?|seo metadata)\b/',
			),
		);
	}

	/**
	 * Translate MCP/client-facing references to Hey Woo tool names.
	 *
	 * The static skills are shared with the companion agent plugin. In AI
	 * Insights, Anthropic sees a different tool namespace, so references must
	 * be adapted before injection or the model will request unknown tools.
	 *
	 * @param string $body Raw skill body.
	 * @return string
	 */
	private static function translate_tool_references( $body ) {
		$replacements = array(
			'Read the `store://profile` MCP resource'    => 'Call the `get_store_profile` tool',
			'Read `store://profile`'                     => 'Call `get_store_profile`',
			'read `store://profile`'                     => 'call `get_store_profile`',
			'`store://profile` MCP resource'             => '`get_store_profile` tool',
			'`store://profile`'                          => '`get_store_profile`',
			'`wc-analytics-totals`'                      => '`analytics_totals`',
			'`wc-analytics-breakdown`'                   => '`analytics_breakdown`',
			'`wc-analytics-series`'                      => '`analytics_series`',
			'`wc-analytics-rows`'                        => '`analytics_rows`',
			'`woocommerce-claude-get-store-profile`'     => '`get_store_profile`',
			'`woocommerce-claude-search-products`'       => '`search_products`',
			'`woocommerce-claude-get-product-details`'   => '`get_product_details`',
			'`woocommerce-claude-get-readiness-score`'   => '`get_readiness_score`',
			'`woocommerce-claude-get-recommendations`'   => '`get_recommendations`',
			'`woocommerce-claude-suggest-improvements`'  => '`suggest_improvements`',
			'`hey-woo-get-store-profile`'                => '`get_store_profile`',
			'`hey-woo-search-products`'                  => '`search_products`',
			'`hey-woo-get-product-details`'              => '`get_product_details`',
			'`hey-woo-get-readiness-score`'              => '`get_readiness_score`',
			'`hey-woo-get-recommendations`'              => '`get_recommendations`',
			'`hey-woo-suggest-improvements`'             => '`suggest_improvements`',
			'Hey Woo analytics tools'                    => 'the available Hey Woo analytics tools',
			'MCP server\'s extended-range approval flow' => 'server-led extended-range approval flow',
			'MCP server'                                 => 'Hey Woo tool bridge',
			'MCP resource'                               => 'tool',
			'MCP'                                        => 'Hey Woo',
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), (string) $body );
	}
}
