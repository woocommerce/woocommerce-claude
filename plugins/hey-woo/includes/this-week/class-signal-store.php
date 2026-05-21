<?php
/**
 * Persistent storage for "This Week" signals.
 *
 * Signals are per-store, not per-user. They live in a single autoloaded option
 * as a JSON array. PR 1 keeps this lightweight; a custom table can replace it
 * later if cross-time queries become useful.
 *
 * Only one signal per workflow slug is retained at any time. A fresh detection
 * for the same slug overwrites the previous record. Dismissing or snoozing
 * marks the signal so the runner can choose not to re-surface it until the
 * snooze window expires or the condition next fires.
 *
 * @package WooCommerce\HeyWoo\ThisWeek
 */

namespace WooCommerce\HeyWoo\ThisWeek;

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed store of detected signals.
 */
class SignalStore {

	/**
	 * Option name holding the JSON list of signals.
	 */
	const OPTION_NAME = 'hey_woo_signals';

	/**
	 * Allowed severity tokens.
	 */
	const SEVERITIES = array( 'high', 'medium', 'low' );

	/**
	 * Return every persisted signal in storage order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$signals = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $signals ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( array( __CLASS__, 'normalise' ), $signals ),
				static function ( $signal ) {
					return is_array( $signal );
				}
			)
		);
	}

	/**
	 * Return unresolved signals — not dismissed and not currently snoozed.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function unresolved() {
		$now = time();

		return array_values(
			array_filter(
				self::all(),
				static function ( $signal ) use ( $now ) {
					if ( ! empty( $signal['dismissed_at'] ) ) {
						return false;
					}

					$snoozed_until = isset( $signal['snoozed_until'] ) ? (int) $signal['snoozed_until'] : 0;
					if ( $snoozed_until > $now ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Upsert a freshly detected signal by slug.
	 *
	 * If a signal for the same slug already exists, the new fields overlay the
	 * previous record and `first_detected_at` is preserved. Dismissal and
	 * snooze markers carry over when present so a still-snoozed signal stays
	 * suppressed even when the underlying condition re-fires.
	 *
	 * @param array<string,mixed> $signal Signal fields.
	 * @return array<string,mixed> The stored signal.
	 */
	public static function upsert( array $signal ) {
		$slug = isset( $signal['slug'] ) ? sanitize_key( $signal['slug'] ) : '';
		if ( '' === $slug ) {
			return array();
		}

		$now      = time();
		$existing = self::find( $slug );
		$record   = array(
			'slug'              => $slug,
			'severity'          => self::clamp_severity( $signal['severity'] ?? 'medium' ),
			'title'             => isset( $signal['title'] ) ? (string) $signal['title'] : '',
			'summary'           => isset( $signal['summary'] ) ? (string) $signal['summary'] : '',
			'evidence'          => self::normalise_evidence( $signal['evidence'] ?? array() ),
			'action'            => self::normalise_action( $signal['action'] ?? array() ),
			'workflow_slug'     => isset( $signal['workflow_slug'] ) ? sanitize_key( $signal['workflow_slug'] ) : '',
			'raw_evidence'      => is_array( $signal['raw_evidence'] ?? null ) ? $signal['raw_evidence'] : array(),
			'first_detected_at' => $existing ? (int) ( $existing['first_detected_at'] ?? $now ) : $now,
			'last_detected_at'  => $now,
			'dismissed_at'      => $existing && ! empty( $existing['dismissed_at'] ) ? (int) $existing['dismissed_at'] : null,
			'snoozed_until'     => $existing && ! empty( $existing['snoozed_until'] ) ? (int) $existing['snoozed_until'] : null,
		);

		$signals = self::all();
		$index   = self::index_of( $signals, $slug );
		if ( null === $index ) {
			$signals[] = $record;
		} else {
			$signals[ $index ] = $record;
		}

		update_option( self::OPTION_NAME, $signals, false );

		return $record;
	}

	/**
	 * Replace the stored set with the provided fresh detections.
	 *
	 * Each fresh signal is upserted individually so existing dismissed/snoozed
	 * state survives a runner pass. After the upserts, a separate read-modify-
	 * write drops any previously stored signal whose slug is no longer in the
	 * fresh set. Splitting the stale-cleanup off from the upsert loop ensures
	 * a concurrent dismiss/snooze landing during a runner's AI calls is not
	 * overwritten by an earlier in-memory snapshot.
	 *
	 * Note: a small race window remains during the cleanup write itself. The
	 * follow-up scheduler PR will replace this with a transient-based lock.
	 *
	 * @param array<int,array<string,mixed>> $fresh Fresh detections from a runner pass.
	 * @return array<int,array<string,mixed>>
	 */
	public static function replace_with( array $fresh ) {
		$retained_slugs = array();
		foreach ( $fresh as $signal ) {
			$slug = isset( $signal['slug'] ) ? sanitize_key( $signal['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$retained_slugs[ $slug ] = true;
			self::upsert( $signal );
		}

		$current = self::all();
		$next    = array_values(
			array_filter(
				$current,
				static function ( $signal ) use ( $retained_slugs ) {
					$slug = isset( $signal['slug'] ) ? (string) $signal['slug'] : '';
					return '' !== $slug && isset( $retained_slugs[ $slug ] );
				}
			)
		);

		if ( count( $next ) !== count( $current ) ) {
			update_option( self::OPTION_NAME, $next, false );
		}

		return $next;
	}

	/**
	 * Mark a signal as dismissed.
	 *
	 * @param string $slug Signal slug.
	 * @return bool
	 */
	public static function dismiss( $slug ) {
		return self::mutate(
			$slug,
			static function ( $signal ) {
				$signal['dismissed_at'] = time();
				return $signal;
			}
		);
	}

	/**
	 * Snooze a signal until the given timestamp.
	 *
	 * @param string $slug          Signal slug.
	 * @param int    $snoozed_until Unix timestamp.
	 * @return bool
	 */
	public static function snooze( $slug, $snoozed_until ) {
		$snoozed_until = (int) $snoozed_until;
		if ( $snoozed_until <= time() ) {
			return false;
		}

		return self::mutate(
			$slug,
			static function ( $signal ) use ( $snoozed_until ) {
				$signal['snoozed_until'] = $snoozed_until;
				return $signal;
			}
		);
	}

	/**
	 * Erase the entire store. Useful for tests.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Apply a mutator to the signal with the given slug, persisting the change.
	 *
	 * @param string   $slug    Signal slug.
	 * @param callable $mutator Function that receives and returns a signal array.
	 * @return bool Whether a signal was found and mutated.
	 */
	private static function mutate( $slug, callable $mutator ) {
		$slug    = sanitize_key( $slug );
		$signals = self::all();
		$index   = self::index_of( $signals, $slug );
		if ( null === $index ) {
			return false;
		}

		$signals[ $index ] = $mutator( $signals[ $index ] );
		update_option( self::OPTION_NAME, $signals, false );

		return true;
	}

	/**
	 * Locate a signal by slug.
	 *
	 * @param string $slug Signal slug.
	 * @return array<string,mixed>|null
	 */
	private static function find( $slug ) {
		$slug    = sanitize_key( $slug );
		$signals = self::all();
		$index   = self::index_of( $signals, $slug );

		return null === $index ? null : $signals[ $index ];
	}

	/**
	 * Return the array index of the signal matching `$slug`, or null.
	 *
	 * @param array<int,array<string,mixed>> $signals Signal list.
	 * @param string                         $slug    Slug to find.
	 * @return int|null
	 */
	private static function index_of( array $signals, $slug ) {
		foreach ( $signals as $index => $signal ) {
			if ( isset( $signal['slug'] ) && (string) $signal['slug'] === $slug ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Coerce a stored signal into the documented shape.
	 *
	 * @param mixed $signal Raw stored value.
	 * @return array<string,mixed>|null
	 */
	private static function normalise( $signal ) {
		if ( ! is_array( $signal ) ) {
			return null;
		}

		$slug = isset( $signal['slug'] ) ? sanitize_key( $signal['slug'] ) : '';
		if ( '' === $slug ) {
			return null;
		}

		return array(
			'slug'              => $slug,
			'severity'          => self::clamp_severity( $signal['severity'] ?? 'medium' ),
			'title'             => isset( $signal['title'] ) ? (string) $signal['title'] : '',
			'summary'           => isset( $signal['summary'] ) ? (string) $signal['summary'] : '',
			'evidence'          => self::normalise_evidence( $signal['evidence'] ?? array() ),
			'action'            => self::normalise_action( $signal['action'] ?? array() ),
			'workflow_slug'     => isset( $signal['workflow_slug'] ) ? sanitize_key( $signal['workflow_slug'] ) : '',
			'raw_evidence'      => is_array( $signal['raw_evidence'] ?? null ) ? $signal['raw_evidence'] : array(),
			'first_detected_at' => isset( $signal['first_detected_at'] ) ? (int) $signal['first_detected_at'] : 0,
			'last_detected_at'  => isset( $signal['last_detected_at'] ) ? (int) $signal['last_detected_at'] : 0,
			'dismissed_at'      => isset( $signal['dismissed_at'] ) && null !== $signal['dismissed_at'] ? (int) $signal['dismissed_at'] : null,
			'snoozed_until'     => isset( $signal['snoozed_until'] ) && null !== $signal['snoozed_until'] ? (int) $signal['snoozed_until'] : null,
		);
	}

	/**
	 * Clamp severity to the allowed token set.
	 *
	 * @param string $severity Raw severity value.
	 * @return string
	 */
	private static function clamp_severity( $severity ) {
		$severity = is_string( $severity ) ? strtolower( $severity ) : '';
		return in_array( $severity, self::SEVERITIES, true ) ? $severity : 'medium';
	}

	/**
	 * Normalise the evidence block.
	 *
	 * @param mixed $evidence Raw evidence input.
	 * @return array<string,string>
	 */
	private static function normalise_evidence( $evidence ) {
		if ( ! is_array( $evidence ) ) {
			return array();
		}

		return array(
			'label'  => isset( $evidence['label'] ) ? (string) $evidence['label'] : '',
			'value'  => isset( $evidence['value'] ) ? (string) $evidence['value'] : '',
			'change' => isset( $evidence['change'] ) ? (string) $evidence['change'] : '',
		);
	}

	/**
	 * Normalise the action block.
	 *
	 * @param mixed $action Raw action input.
	 * @return array<string,string>
	 */
	private static function normalise_action( $action ) {
		if ( ! is_array( $action ) ) {
			return array();
		}

		return array(
			'title'         => isset( $action['title'] ) ? (string) $action['title'] : '',
			'detail'        => isset( $action['detail'] ) ? (string) $action['detail'] : '',
			'workflow_slug' => isset( $action['workflow_slug'] ) ? sanitize_key( $action['workflow_slug'] ) : '',
		);
	}
}
