<?php
/**
 * First-run briefing content for the Hey Woo Today surface.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and stores the first Today briefing using the configured AI provider.
 */
class DifmBriefingsController {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'hey-woo/v1';

	/**
	 * Stored first-run briefing option.
	 */
	const OPTION_KEY = 'hey_woo_first_run_briefing';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/difm/briefing/first-run',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_briefing' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_briefing' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Check REST permissions.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return the stored or fallback briefing.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_briefing() {
		return rest_ensure_response(
			array(
				'status'   => 'ok',
				'briefing' => self::get_bootstrap_briefing(),
			)
		);
	}

	/**
	 * Generate the first-run briefing with the configured AI provider.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate_briefing( \WP_REST_Request $request ) {
		$force  = (bool) $request->get_param( 'force' );
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! $force && is_array( $stored ) && isset( $stored['source'] ) && 'ai' === $stored['source'] ) {
			return rest_ensure_response(
				array(
					'status'   => 'ok',
					'briefing' => self::normalise_briefing( $stored, 'ai' ),
				)
			);
		}

		$resolver = new DifmProviderResolver();
		$client   = $resolver->resolve_client();
		if ( is_wp_error( $client ) ) {
			return $this->fallback_response( $client->get_error_message() );
		}

		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_generation_prompt(),
				),
			),
			$this->build_system_prompt(),
			array(),
			1600,
			array(
				'surface' => 'difm_first_run_briefing',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->fallback_response( $result->get_error_message() );
		}

		$text    = self::extract_text_reply( isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array() );
		$decoded = self::decode_json_object( $text );

		if ( ! is_array( $decoded ) ) {
			return $this->fallback_response( __( 'The AI provider returned briefing content that could not be read.', 'hey-woo' ) );
		}

		$briefing                = self::normalise_briefing( $decoded, 'ai' );
		$briefing['generatedAt'] = time() * 1000;

		update_option( self::OPTION_KEY, $briefing, false );
		update_option( 'hey_woo_first_run_briefing_status', 'generated', false );

		return rest_ensure_response(
			array(
				'status'   => 'ok',
				'briefing' => $briefing,
			)
		);
	}

	/**
	 * Return the briefing used during initial page render.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_bootstrap_briefing() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return self::normalise_briefing( $stored, isset( $stored['source'] ) ? (string) $stored['source'] : 'ai' );
		}

		return self::fallback_briefing();
	}

	/**
	 * Return a deterministic fallback briefing.
	 *
	 * @return array<string,mixed>
	 */
	public static function fallback_briefing() {
		return array(
			'source'      => 'fallback',
			'generatedAt' => 0,
			'headline'    => __( 'Good morning.', 'hey-woo' ),
			'summary'     => __( 'Start with a weekly review, then turn the strongest findings into actions or monitors.', 'hey-woo' ),
			'metrics'     => array(
				array(
					'label' => __( 'Revenue', 'hey-woo' ),
					'value' => __( 'Not run yet', 'hey-woo' ),
					'trend' => __( 'Run weekly review', 'hey-woo' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Orders', 'hey-woo' ),
					'value' => __( 'Not run yet', 'hey-woo' ),
					'trend' => __( 'Check failed orders', 'hey-woo' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'AOV', 'hey-woo' ),
					'value' => __( 'Not run yet', 'hey-woo' ),
					'trend' => __( 'Review basket value', 'hey-woo' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Customers', 'hey-woo' ),
					'value' => __( 'Not run yet', 'hey-woo' ),
					'trend' => __( 'Review acquisition', 'hey-woo' ),
					'tone'  => 'neutral',
				),
				array(
					'label' => __( 'Refunds', 'hey-woo' ),
					'value' => __( 'Not run yet', 'hey-woo' ),
					'trend' => __( 'Triage refund risk', 'hey-woo' ),
					'tone'  => 'neutral',
				),
			),
			'items'       => array(
				array(
					'category'     => __( 'Briefing', 'hey-woo' ),
					'status'       => __( 'Start here', 'hey-woo' ),
					'title'        => __( 'Run the weekly store review', 'hey-woo' ),
					'summary'      => __( 'Create the first store-wide briefing across revenue, orders, products, customers, and next steps.', 'hey-woo' ),
					'workflowSlug' => 'weekly-store-review',
				),
				array(
					'category'     => __( 'Trading', 'hey-woo' ),
					'status'       => __( 'Watch', 'hey-woo' ),
					'title'        => __( 'Check whether sales changed materially', 'hey-woo' ),
					'summary'      => __( 'Use revenue triage when you need a clear explanation of what moved and which drivers are addressable.', 'hey-woo' ),
					'workflowSlug' => 'revenue-drop-triage',
				),
				array(
					'category'     => __( 'Operations', 'hey-woo' ),
					'status'       => __( 'Useful monitor', 'hey-woo' ),
					'title'        => __( 'Keep an eye on refunds and failed orders', 'hey-woo' ),
					'summary'      => __( 'Refund and payment issues are strong candidates for action cards because the next steps are usually concrete.', 'hey-woo' ),
					'workflowSlug' => 'refund-triage',
				),
			),
			'monitors'    => array(
				array(
					'title'        => __( 'Weekly revenue', 'hey-woo' ),
					'metric'       => __( 'Revenue versus previous period', 'hey-woo' ),
					'cadence'      => __( 'Weekly', 'hey-woo' ),
					'workflowSlug' => 'weekly-store-review',
				),
				array(
					'title'        => __( 'Refund rate', 'hey-woo' ),
					'metric'       => __( 'Refunded revenue and product drivers', 'hey-woo' ),
					'cadence'      => __( 'Weekly', 'hey-woo' ),
					'workflowSlug' => 'refund-triage',
				),
				array(
					'title'        => __( 'Failed orders', 'hey-woo' ),
					'metric'       => __( 'Failed, on-hold, and unpaid orders', 'hey-woo' ),
					'cadence'      => __( 'Weekly', 'hey-woo' ),
					'workflowSlug' => 'failed-order-triage',
				),
			),
		);
	}

	/**
	 * Build the generation system prompt.
	 *
	 * @return string
	 */
	private function build_system_prompt() {
		return 'You write concise first-run content for Hey Woo, a WooCommerce merchant assistant. Return only valid JSON. Do not invent live store metrics, revenue, customer details, order counts, or private data.';
	}

	/**
	 * Build the user prompt.
	 *
	 * @return string
	 */
	private function build_generation_prompt() {
		$workflows = array();
		foreach ( WorkflowSkills::all() as $workflow ) {
			$workflows[] = array(
				'slug'        => isset( $workflow['slug'] ) ? (string) $workflow['slug'] : '',
				'description' => isset( $workflow['description'] ) ? (string) $workflow['description'] : '',
			);
		}

		$encoded = wp_json_encode(
			array(
				'task'      => 'Create first-run Today briefing content for a merchant who has just opened Hey Woo for the first time.',
				'store'     => array(
					'name'     => get_bloginfo( 'name' ),
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
					'date'     => current_time( 'Y-m-d' ),
				),
				'rules'     => array(
					'Do not invent live metrics. Use values like "Not run yet" or "Ready to review" until a workflow runs.',
					'Keep every item merchant-actionable.',
					'Use British English.',
					'Use only workflowSlug values from the workflow list.',
				),
				'schema'    => array(
					'headline' => 'Short greeting headline without a name.',
					'summary'  => 'One sentence setting up what to review first.',
					'metrics'  => array(
						array(
							'label' => 'Revenue',
							'value' => 'Not run yet',
							'trend' => 'Run weekly review',
							'tone'  => 'neutral',
						),
					),
					'items'    => array(
						array(
							'category'     => 'Briefing',
							'status'       => 'Start here',
							'title'        => 'Run the weekly store review',
							'summary'      => 'Why this is useful.',
							'workflowSlug' => 'weekly-store-review',
						),
					),
					'monitors' => array(
						array(
							'title'        => 'Refund rate',
							'metric'       => 'What to watch',
							'cadence'      => 'Weekly',
							'workflowSlug' => 'refund-triage',
						),
					),
				),
				'limits'    => array(
					'metrics'  => 5,
					'items'    => 4,
					'monitors' => 4,
				),
				'workflows' => $workflows,
			)
		);

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Return a fallback response with a non-fatal message.
	 *
	 * @param string $message Error message.
	 * @return \WP_REST_Response
	 */
	private function fallback_response( $message ) {
		return rest_ensure_response(
			array(
				'status'   => 'fallback',
				'message'  => sanitize_text_field( (string) $message ),
				'briefing' => self::fallback_briefing(),
			)
		);
	}

	/**
	 * Extract text blocks from a normalised AI response.
	 *
	 * @param array $content Content blocks.
	 * @return string
	 */
	private static function extract_text_reply( array $content ) {
		$text = '';
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		return trim( $text );
	}

	/**
	 * Decode a JSON object, including fenced JSON responses.
	 *
	 * @param string $text Provider text.
	 * @return array<string,mixed>|null
	 */
	private static function decode_json_object( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );
		$text = trim( (string) $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Normalise briefing content for storage and page data.
	 *
	 * @param array  $briefing Briefing payload.
	 * @param string $source   Source label.
	 * @return array<string,mixed>
	 */
	private static function normalise_briefing( array $briefing, $source ) {
		$fallback = self::fallback_briefing();

		return array(
			'source'      => 'ai' === $source ? 'ai' : 'fallback',
			'generatedAt' => isset( $briefing['generatedAt'] ) ? absint( $briefing['generatedAt'] ) : 0,
			'headline'    => self::clean_text( isset( $briefing['headline'] ) ? $briefing['headline'] : $fallback['headline'] ),
			'summary'     => self::clean_textarea( isset( $briefing['summary'] ) ? $briefing['summary'] : $fallback['summary'] ),
			'metrics'     => self::normalise_metrics( isset( $briefing['metrics'] ) && is_array( $briefing['metrics'] ) ? $briefing['metrics'] : $fallback['metrics'] ),
			'items'       => self::normalise_items( isset( $briefing['items'] ) && is_array( $briefing['items'] ) ? $briefing['items'] : $fallback['items'] ),
			'monitors'    => self::normalise_monitors( isset( $briefing['monitors'] ) && is_array( $briefing['monitors'] ) ? $briefing['monitors'] : $fallback['monitors'] ),
		);
	}

	/**
	 * Normalise metric strip items.
	 *
	 * @param array $metrics Raw metric payloads.
	 * @return array<int,array<string,string>>
	 */
	private static function normalise_metrics( array $metrics ) {
		$normalised = array();
		foreach ( array_slice( $metrics, 0, 5 ) as $metric ) {
			if ( ! is_array( $metric ) ) {
				continue;
			}
			$tone = isset( $metric['tone'] ) ? sanitize_key( (string) $metric['tone'] ) : 'neutral';
			if ( ! in_array( $tone, array( 'positive', 'warning', 'negative', 'neutral' ), true ) ) {
				$tone = 'neutral';
			}

			$normalised[] = array(
				'label' => self::clean_text( isset( $metric['label'] ) ? $metric['label'] : '' ),
				'value' => self::clean_text( isset( $metric['value'] ) ? $metric['value'] : '' ),
				'trend' => self::clean_text( isset( $metric['trend'] ) ? $metric['trend'] : '' ),
				'tone'  => $tone,
			);
		}

		return $normalised;
	}

	/**
	 * Normalise briefing insight rows.
	 *
	 * @param array $items Raw item payloads.
	 * @return array<int,array<string,string>>
	 */
	private static function normalise_items( array $items ) {
		$normalised = array();
		foreach ( array_slice( $items, 0, 4 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalised[] = array(
				'category'     => self::clean_text( isset( $item['category'] ) ? $item['category'] : '' ),
				'status'       => self::clean_text( isset( $item['status'] ) ? $item['status'] : '' ),
				'title'        => self::clean_text( isset( $item['title'] ) ? $item['title'] : '' ),
				'summary'      => self::clean_textarea( isset( $item['summary'] ) ? $item['summary'] : '' ),
				'workflowSlug' => self::normalise_workflow_slug( isset( $item['workflowSlug'] ) ? $item['workflowSlug'] : '' ),
			);
		}

		return $normalised;
	}

	/**
	 * Normalise monitor suggestions.
	 *
	 * @param array $monitors Raw monitor payloads.
	 * @return array<int,array<string,string>>
	 */
	private static function normalise_monitors( array $monitors ) {
		$normalised = array();
		foreach ( array_slice( $monitors, 0, 4 ) as $monitor ) {
			if ( ! is_array( $monitor ) ) {
				continue;
			}

			$normalised[] = array(
				'title'        => self::clean_text( isset( $monitor['title'] ) ? $monitor['title'] : '' ),
				'metric'       => self::clean_text( isset( $monitor['metric'] ) ? $monitor['metric'] : '' ),
				'cadence'      => self::clean_text( isset( $monitor['cadence'] ) ? $monitor['cadence'] : '' ),
				'workflowSlug' => self::normalise_workflow_slug( isset( $monitor['workflowSlug'] ) ? $monitor['workflowSlug'] : '' ),
			);
		}

		return $normalised;
	}

	/**
	 * Clean a short text value.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private static function clean_text( $text ) {
		return sanitize_text_field( (string) $text );
	}

	/**
	 * Clean a longer text value.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private static function clean_textarea( $text ) {
		return sanitize_textarea_field( (string) $text );
	}

	/**
	 * Return a known workflow slug or an empty string.
	 *
	 * @param mixed $slug Workflow slug.
	 * @return string
	 */
	private static function normalise_workflow_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return WorkflowSkills::get( $slug ) ? $slug : '';
	}
}
