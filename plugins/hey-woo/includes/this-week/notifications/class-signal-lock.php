<?php
/**
 * Atomic, option-backed mutex for the "This Week" runner.
 *
 * The runner reads and rewrites the `hey_woo_signals` option across an AI
 * call window of several seconds. Without a lock, a concurrent dismiss or
 * a wp-cron tick that fires during a manual "Refresh now" can clobber
 * each other's writes.
 *
 * `get_transient` + `set_transient` is not atomic — two near-simultaneous
 * workers can both see the lock as free, both set their token, and both
 * proceed. Object caches make the window wider. We use `add_option()`
 * instead: it returns false when the option already exists, providing the
 * canonical WordPress check-and-set primitive. Expiry is encoded in the
 * stored payload so a crashed run still auto-recovers after the TTL.
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
	 * Option key holding the lock payload.
	 */
	const OPTION_KEY = 'hey_woo_signal_lock';

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
	 * `add_option()` is atomic: the underlying INSERT IGNORE either creates
	 * the row exactly once or fails. We then verify by reading the stored
	 * token back, so a stale expired payload that someone else just
	 * overwrote can't trick us into thinking we hold the lock.
	 *
	 * @return bool
	 */
	public function acquire() {
		if ( null !== $this->token ) {
			return false;
		}

		$this->purge_if_expired();

		$token   = wp_generate_password( 16, false, false );
		$payload = array(
			'token'   => $token,
			'expires' => time() + self::TTL_SECONDS,
		);

		if ( ! add_option( self::OPTION_KEY, $payload, '', 'no' ) ) {
			return false;
		}

		$stored = get_option( self::OPTION_KEY );
		if ( ! is_array( $stored ) || ! isset( $stored['token'] ) || $stored['token'] !== $token ) {
			return false;
		}

		$this->token = $token;
		return true;
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

		$stored = get_option( self::OPTION_KEY );
		if ( is_array( $stored ) && isset( $stored['token'] ) && $stored['token'] === $this->token ) {
			delete_option( self::OPTION_KEY );
		}

		$this->token = null;
	}

	/**
	 * Whether a non-expired lock currently exists (possibly held by another process).
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$stored = get_option( self::OPTION_KEY );
		if ( ! is_array( $stored ) || ! isset( $stored['expires'] ) ) {
			return false;
		}

		return (int) $stored['expires'] > time();
	}

	/**
	 * Delete the lock option when its TTL has elapsed.
	 *
	 * Called once at the start of an acquire attempt so a crashed run cannot
	 * permanently block subsequent acquirers. Returns early when no payload
	 * is stored or the stored payload is still within its TTL.
	 *
	 * @return void
	 */
	private function purge_if_expired() {
		$stored = get_option( self::OPTION_KEY );
		if ( ! is_array( $stored ) || ! isset( $stored['expires'] ) ) {
			return;
		}

		if ( (int) $stored['expires'] <= time() ) {
			delete_option( self::OPTION_KEY );
		}
	}
}
