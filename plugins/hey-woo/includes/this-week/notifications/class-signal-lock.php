<?php
/**
 * Transient-based mutex for the "This Week" runner.
 *
 * The runner reads and rewrites the `hey_woo_signals` option across an AI
 * call window of several seconds. Without a lock, a concurrent dismiss or
 * a wp-cron tick that fires during a manual "Refresh now" can clobber each
 * other's writes (the PR 1 race documented in SignalStore::replace_with).
 *
 * The lock uses a short-TTL transient: cheap, no schema, auto-recovers if a
 * run crashes mid-flight. The TTL is well above the worst-case end-to-end
 * runner time (4 detectors × 1 AI call each, plus a buffer).
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Notifications
 */

namespace WooCommerce\HeyWoo\ThisWeek\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Single-writer guard for the signal runner.
 */
class SignalLock {

	/**
	 * Transient key holding the lock token.
	 */
	const TRANSIENT_KEY = 'hey_woo_signal_lock';

	/**
	 * Lock lifetime in seconds. A crashed runner auto-recovers after this.
	 */
	const TTL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Currently-held token, used to verify release belongs to the same caller.
	 *
	 * @var string|null
	 */
	private $token;

	/**
	 * Attempt to acquire the lock.
	 *
	 * Uses `wp_cache_add` / `add_option` semantics via the transient API so
	 * two near-simultaneous callers cannot both succeed. The first caller
	 * receives `true`; subsequent callers see `false` until the lock is
	 * released or its TTL expires.
	 *
	 * @return bool
	 */
	public function acquire() {
		if ( null !== $this->token ) {
			return false;
		}

		$token = wp_generate_password( 16, false, false );

		if ( false === get_transient( self::TRANSIENT_KEY ) ) {
			$set = set_transient( self::TRANSIENT_KEY, $token, self::TTL_SECONDS );
			if ( $set ) {
				$held = (string) get_transient( self::TRANSIENT_KEY );
				if ( $held === $token ) {
					$this->token = $token;
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Release the lock if and only if we still own it.
	 *
	 * @return void
	 */
	public function release() {
		if ( null === $this->token ) {
			return;
		}

		$held = (string) get_transient( self::TRANSIENT_KEY );
		if ( $held === $this->token ) {
			delete_transient( self::TRANSIENT_KEY );
		}

		$this->token = null;
	}

	/**
	 * Whether the lock is currently held by someone (possibly another process).
	 *
	 * @return bool
	 */
	public static function is_locked() {
		return false !== get_transient( self::TRANSIENT_KEY );
	}
}
