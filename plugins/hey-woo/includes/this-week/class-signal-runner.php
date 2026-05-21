<?php
/**
 * Runner that turns deterministic detections into merchant-friendly signals.
 *
 * The runner is the only place that calls the AI in PR 1. Each detector runs
 * its own threshold check using direct ability calls. For every detection that
 * fires, the runner sends a focused prompt to the configured AI provider —
 * paired with the workflow's SKILL.md — and asks for a small JSON object with
 * the title, summary, evidence chip, and one merchant-doable action. The
 * result is upserted into the SignalStore so the frontend can render cards.
 *
 * @package WooCommerce\HeyWoo\ThisWeek
 */

namespace WooCommerce\HeyWoo\ThisWeek;

use WooCommerce\HeyWoo\Difm\DifmProviderResolver;
use WooCommerce\HeyWoo\Difm\WorkflowSkills;
use WooCommerce\HeyWoo\ThisWeek\Detectors\FailedOrderDetector;
use WooCommerce\HeyWoo\ThisWeek\Detectors\InventoryRiskDetector;
use WooCommerce\HeyWoo\ThisWeek\Detectors\RefundSpikeDetector;
use WooCommerce\HeyWoo\ThisWeek\Detectors\RevenueDropDetector;
use WooCommerce\HeyWoo\ThisWeek\Detectors\SignalDetectorInterface;
use WooCommerce\HeyWoo\ThisWeek\Notifications\SignalLock;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates detection and AI summarisation for the "This Week" feed.
 */
class SignalRunner {

	/**
	 * Token budget for the per-signal summarisation call. The output is a
	 * small JSON object, so 600 tokens is generous and leaves headroom for
	 * minor noise without runaway cost.
	 */
	const SUMMARY_MAX_TOKENS = 600;

	/**
	 * Run all detectors once, summarise any that fired, and persist the result.
	 *
	 * Wrapped in a transient lock so a wp-cron tick can't interleave with a
	 * manual "Refresh now". When the lock is already held, the call returns
	 * a `busy` result without touching state — the caller can decide whether
	 * to wait or surface 409.
	 *
	 * @param array<string,mixed> $options Optional runner options.
	 *                                     - `skip_ai` (bool): skip the AI call and use deterministic fallback strings.
	 * @return array{status:string,detected:array,skipped:array,signals:array,errors:array}
	 */
	public function run( array $options = array() ) {
		$lock = new SignalLock();
		if ( ! $lock->acquire() ) {
			return array(
				'status'   => 'busy',
				'detected' => array(),
				'skipped'  => array(),
				'signals'  => array(),
				'errors'   => array(),
			);
		}

		try {
			$skip_ai   = ! empty( $options['skip_ai'] );
			$detectors = $this->detectors();
			$detected  = array();
			$skipped   = array();
			$signals   = array();
			$errors    = array();
			$client    = $skip_ai ? null : $this->resolve_client( $errors );

			foreach ( $detectors as $detector ) {
				$slug = $detector->slug();

				try {
					$detection = $detector->detect();
				} catch ( \Throwable $e ) {
					$errors[]  = array(
						'detector' => $slug,
						'error'    => 'detector_threw',
						'message'  => $e->getMessage(),
					);
					$skipped[] = $slug;
					continue;
				}

				if ( null === $detection ) {
					$skipped[] = $slug;
					continue;
				}

				$detected[] = $slug;
				$signal     = $this->summarise( $detector, $detection, $client, $errors );
				if ( null !== $signal ) {
					$signals[] = $signal;
				}
			}

			SignalStore::replace_with( $signals );
		} finally {
			$lock->release();
		}

		return array(
			'status'   => 'ok',
			'detected' => $detected,
			'skipped'  => $skipped,
			'signals'  => $signals,
			'errors'   => $errors,
		);
	}

	/**
	 * Detector list used by the runner.
	 *
	 * Override via the `hey_woo_this_week_detectors` filter to add or replace
	 * detectors. Filtered output must consist of SignalDetectorInterface
	 * instances; anything else is dropped.
	 *
	 * @return SignalDetectorInterface[]
	 */
	protected function detectors() {
		$detectors = array(
			new RevenueDropDetector(),
			new RefundSpikeDetector(),
			new FailedOrderDetector(),
			new InventoryRiskDetector(),
		);

		/**
		 * Filter the detector list used by the This Week runner.
		 *
		 * @since 0.5.0
		 *
		 * @param SignalDetectorInterface[] $detectors Default detector instances.
		 */
		$filtered = apply_filters( 'hey_woo_this_week_detectors', $detectors );

		return array_values(
			array_filter(
				is_array( $filtered ) ? $filtered : array(),
				static function ( $detector ) {
					return $detector instanceof SignalDetectorInterface;
				}
			)
		);
	}

	/**
	 * Compose a merchant-friendly signal from a raw detection.
	 *
	 * Calls the AI once when a client is available. Falls back to the
	 * detector's deterministic title plus a minimal evidence label so the
	 * surface still works without an AI provider configured.
	 *
	 * @param SignalDetectorInterface $detector  Detector instance.
	 * @param array<string,mixed>     $detection Detector output.
	 * @param mixed                   $client    Resolved AI client, or null.
	 * @param array<int,array>        $errors    Mutable error log.
	 * @return array<string,mixed>|null
	 */
	private function summarise( SignalDetectorInterface $detector, array $detection, $client, array &$errors ) {
		$workflow_slug = isset( $detection['workflow_slug'] ) ? (string) $detection['workflow_slug'] : $detector->workflow_slug();
		$raw_evidence  = isset( $detection['raw_evidence'] ) && is_array( $detection['raw_evidence'] ) ? $detection['raw_evidence'] : array();
		$fallback      = $this->fallback_payload( $detection );

		if ( null === $client ) {
			return $this->merge_signal( $detection, $fallback );
		}

		$workflow      = WorkflowSkills::get( $workflow_slug );
		$skill_body    = is_array( $workflow ) ? WorkflowSkills::prompt_for_difm( $workflow ) : '';
		$system_prompt = $this->build_system_prompt( $skill_body );
		$user_prompt   = $this->build_user_prompt( $detection );

		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$system_prompt,
			array(),
			self::SUMMARY_MAX_TOKENS,
			array(
				'surface'       => 'this_week_signal_summary',
				'detector_slug' => $detector->slug(),
				'workflow_slug' => $workflow_slug,
				'iteration'     => 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			$errors[] = array(
				'detector' => $detector->slug(),
				'error'    => 'ai_call_failed',
				'message'  => $response->get_error_message(),
			);
			return $this->merge_signal( $detection, $fallback );
		}

		$payload = $this->extract_ai_payload( $response );
		if ( null === $payload ) {
			$errors[] = array(
				'detector' => $detector->slug(),
				'error'    => 'ai_response_unparseable',
				'message'  => '',
			);
			return $this->merge_signal( $detection, $fallback );
		}

		return $this->merge_signal( $detection, $payload );
	}

	/**
	 * Build the runner-specific system prompt with the SKILL.md appended.
	 *
	 * @param string $skill_body Workflow skill body, possibly empty.
	 * @return string
	 */
	private function build_system_prompt( $skill_body ) {
		$intro = 'You are Hey Woo\'s This Week monitor. A deterministic signal has already fired for the store; your job is to write the merchant-friendly card text for it. Return a single compact JSON object (no markdown, no fenced code block, no surrounding prose) with this exact shape:'
			. ' {"title": "<= 80 chars", "summary": "1-2 sentences in plain merchant English", "evidence": {"label": "headline metric name", "value": "headline value", "change": "vs previous period"}, "action": {"title": "<= 60 chars imperative", "detail": "1-2 sentences on why and how"}}. '
			. 'Only use figures present in the supplied evidence. Do not invent metrics, forecasts, margins, conversion rates, sessions, ad spend, ROAS, customer names, or anything not present in the evidence. Do not mention tool names, parameter names, JSON keys, or implementation details in the output text. '
			. 'The action must be doable by the merchant in WooCommerce admin, marketing tools, or a connected platform; "review the report" is not an action.';

		if ( '' === trim( (string) $skill_body ) ) {
			return $intro;
		}

		return $intro . "\n\n" . $skill_body;
	}

	/**
	 * Build the user prompt as a JSON payload of the detector's evidence.
	 *
	 * @param array<string,mixed> $detection Detector output.
	 * @return string
	 */
	private function build_user_prompt( array $detection ) {
		$payload = array(
			'detector_slug'       => isset( $detection['slug'] ) ? (string) $detection['slug'] : '',
			'severity'            => isset( $detection['severity'] ) ? (string) $detection['severity'] : 'medium',
			'deterministic_title' => isset( $detection['title'] ) ? (string) $detection['title'] : '',
			'evidence'            => isset( $detection['raw_evidence'] ) ? $detection['raw_evidence'] : array(),
		);

		$payload_json = wp_json_encode( $payload, JSON_PRETTY_PRINT );
		$payload_json = is_string( $payload_json ) ? $payload_json : '{}';

		return "A signal has fired for this store. Compose the card text from the evidence below. Return only the JSON object specified in the system prompt.\n\n" . $payload_json;
	}

	/**
	 * Extract a JSON payload from the model response.
	 *
	 * @param array $response Provider response.
	 * @return array<string,mixed>|null
	 */
	private function extract_ai_payload( array $response ) {
		$content = isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array();
		$text    = '';
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$decoded = self::decode_first_json_object( $text );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$title    = self::clean_string( $decoded['title'] ?? null, 120 );
		$summary  = self::clean_string( $decoded['summary'] ?? null, 320 );
		$evidence = is_array( $decoded['evidence'] ?? null ) ? $decoded['evidence'] : array();
		$action   = is_array( $decoded['action'] ?? null ) ? $decoded['action'] : array();

		if ( '' === $title && '' === $summary ) {
			return null;
		}

		return array(
			'title'    => $title,
			'summary'  => $summary,
			'evidence' => array(
				'label'  => self::clean_string( $evidence['label'] ?? null, 80 ),
				'value'  => self::clean_string( $evidence['value'] ?? null, 60 ),
				'change' => self::clean_string( $evidence['change'] ?? null, 80 ),
			),
			'action'   => array(
				'title'  => self::clean_string( $action['title'] ?? null, 80 ),
				'detail' => self::clean_string( $action['detail'] ?? null, 320 ),
			),
		);
	}

	/**
	 * Coerce an AI-returned value into a trimmed, length-clamped scalar string.
	 *
	 * Arrays, objects, and non-scalar types collapse to an empty string — the
	 * runner never trusts the provider to keep types correct, so unexpected
	 * shapes degrade gracefully instead of emitting array-to-string warnings
	 * or persisting structured garbage to the signals option.
	 *
	 * @param mixed $value      Raw value from the parsed JSON.
	 * @param int   $max_length Maximum characters to retain.
	 * @return string
	 */
	private static function clean_string( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, (int) $max_length );
		}

		return substr( $text, 0, (int) $max_length );
	}

	/**
	 * Decode the first JSON object found in a text blob.
	 *
	 * Some providers wrap JSON in stray prose despite explicit instructions;
	 * this finds the first matching braces span and decodes it. Returns the
	 * decoded associative array, or null when nothing parses.
	 *
	 * @param string $text Raw text.
	 * @return array<string,mixed>|null
	 */
	private static function decode_first_json_object( $text ) {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}

		$depth     = 0;
		$in_string = false;
		$escape    = false;
		$length    = strlen( $text );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $escape ) {
				$escape = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escape = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
				continue;
			}

			if ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					$candidate = substr( $text, $start, $i - $start + 1 );
					$decoded   = json_decode( $candidate, true );
					return is_array( $decoded ) ? $decoded : null;
				}
			}
		}

		return null;
	}

	/**
	 * Compose the deterministic fallback payload when the AI is unavailable.
	 *
	 * @param array<string,mixed> $detection Detector output.
	 * @return array<string,mixed>
	 */
	private function fallback_payload( array $detection ) {
		return array(
			'title'    => isset( $detection['title'] ) ? (string) $detection['title'] : '',
			'summary'  => '',
			'evidence' => array(
				'label'  => '',
				'value'  => '',
				'change' => '',
			),
			'action'   => array(
				'title'  => '',
				'detail' => '',
			),
		);
	}

	/**
	 * Merge a detection with the chosen text payload into a Signal record.
	 *
	 * @param array<string,mixed> $detection Detector output.
	 * @param array<string,mixed> $payload   Text payload (AI or fallback).
	 * @return array<string,mixed>
	 */
	private function merge_signal( array $detection, array $payload ) {
		$action                  = isset( $payload['action'] ) && is_array( $payload['action'] ) ? $payload['action'] : array();
		$workflow_slug           = isset( $detection['workflow_slug'] ) ? (string) $detection['workflow_slug'] : '';
		$action['workflow_slug'] = $workflow_slug;

		return array(
			'slug'          => isset( $detection['slug'] ) ? (string) $detection['slug'] : '',
			'severity'      => isset( $detection['severity'] ) ? (string) $detection['severity'] : 'medium',
			'workflow_slug' => $workflow_slug,
			'title'         => isset( $payload['title'] ) && '' !== $payload['title'] ? (string) $payload['title'] : ( isset( $detection['title'] ) ? (string) $detection['title'] : '' ),
			'summary'       => isset( $payload['summary'] ) ? (string) $payload['summary'] : '',
			'evidence'      => isset( $payload['evidence'] ) && is_array( $payload['evidence'] ) ? $payload['evidence'] : array(),
			'action'        => $action,
			'raw_evidence'  => isset( $detection['raw_evidence'] ) && is_array( $detection['raw_evidence'] ) ? $detection['raw_evidence'] : array(),
		);
	}

	/**
	 * Resolve the configured AI client, logging any error.
	 *
	 * @param array<int,array> $errors Mutable error log.
	 * @return \WooCommerce\HeyWoo\Difm\DifmAiClientInterface|null
	 */
	private function resolve_client( array &$errors ) {
		$resolver = new DifmProviderResolver();
		$client   = $resolver->resolve_client();

		if ( is_wp_error( $client ) ) {
			$errors[] = array(
				'detector' => '*',
				'error'    => 'ai_client_unresolved',
				'message'  => $client->get_error_message(),
			);
			return null;
		}

		return $client;
	}
}
