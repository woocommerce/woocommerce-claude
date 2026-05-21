<?php
/**
 * Cron scheduling for the "This Week" runner and weekly digest.
 *
 * Two distinct events:
 *
 * - `hey_woo_this_week_daily_refresh` — runs once a day at the configured
 *   wall-clock time in the site timezone (default 03:00). Calls the runner
 *   so the merchant lands on fresh signals the next time they open the app.
 * - `hey_woo_this_week_weekly_digest` — runs once a week at the configured
 *   weekday + time in the site timezone (default Monday 09:00). Sends the
 *   email digest when monitoring + digest preferences are both enabled.
 *
 * The site-timezone choice matters: a merchant in Sydney expects "Monday
 * 9am" to mean Sydney-9am, not UTC. We compute the next site-local time
 * and then convert to a UTC timestamp for wp_schedule_event().
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Notifications
 */

namespace WooCommerce\HeyWoo\ThisWeek\Notifications;

use WooCommerce\HeyWoo\ThisWeek\SignalRunner;
use WooCommerce\HeyWoo\ThisWeek\SignalStore;

defined( 'ABSPATH' ) || exit;

/**
 * Register, reschedule, and run the daily + weekly events.
 */
class Scheduler {

	/**
	 * Cron hook for the daily runner refresh.
	 */
	const HOOK_DAILY_REFRESH = 'hey_woo_this_week_daily_refresh';

	/**
	 * Cron hook for the weekly email digest send.
	 */
	const HOOK_WEEKLY_DIGEST = 'hey_woo_this_week_weekly_digest';

	/**
	 * Fixed hour-of-day for the daily refresh in store-local time.
	 *
	 * Pinned away from typical merchant working hours so the AI calls and
	 * analytics queries don't compete with checkout traffic. The follow-up
	 * UX work can expose this as a setting.
	 */
	const DAILY_HOUR = 3;

	/**
	 * Bind hooks. Safe to call multiple times via `plugins_loaded`.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_DAILY_REFRESH, array( $this, 'handle_daily_refresh' ) );
		add_action( self::HOOK_WEEKLY_DIGEST, array( $this, 'handle_weekly_digest' ) );
		// WC settings page save fires regardless of whether values changed and
		// catches the add_option path on first-time saves that update_option_*
		// hooks miss. Per-option add/update hooks remain as belt-and-braces for
		// programmatic option changes.
		add_action( 'woocommerce_settings_save_hey-woo', array( $this, 'reschedule_all' ), 20 );
		foreach (
			array(
				ThisWeekSettings::OPTION_ENABLED,
				ThisWeekSettings::OPTION_DIGEST_ENABLED,
				ThisWeekSettings::OPTION_DIGEST_DAY,
				ThisWeekSettings::OPTION_DIGEST_TIME,
			) as $option_name
		) {
			add_action( 'add_option_' . $option_name, array( $this, 'reschedule_all' ), 10, 0 );
			add_action( 'update_option_' . $option_name, array( $this, 'reschedule_all' ), 10, 0 );
		}
	}

	/**
	 * Schedule the next single event for each hook, when missing.
	 *
	 * Events are intentionally NOT recurring. wp-cron's recurring schedules
	 * fire at fixed UTC intervals, which drifts an hour off the configured
	 * wall-clock time across DST transitions. Single events recomputed in
	 * the handler stay anchored to the merchant's local 03:00 (or whatever
	 * weekly time they pick).
	 *
	 * Idempotent — when an event is already scheduled, leaves it alone.
	 *
	 * @return void
	 */
	public function ensure_events_scheduled() {
		if ( ThisWeekSettings::is_enabled() && ! wp_next_scheduled( self::HOOK_DAILY_REFRESH ) ) {
			wp_schedule_single_event( $this->next_daily_timestamp(), self::HOOK_DAILY_REFRESH );
		}

		if ( ThisWeekSettings::is_digest_enabled() && ! wp_next_scheduled( self::HOOK_WEEKLY_DIGEST ) ) {
			wp_schedule_single_event( $this->next_weekly_timestamp(), self::HOOK_WEEKLY_DIGEST );
		}
	}

	/**
	 * Drop all scheduled events. Called from deactivation.
	 *
	 * @return void
	 */
	public static function clear_all_events() {
		wp_clear_scheduled_hook( self::HOOK_DAILY_REFRESH );
		wp_clear_scheduled_hook( self::HOOK_WEEKLY_DIGEST );
	}

	/**
	 * Re-schedule both events when a relevant setting changes.
	 *
	 * @return void
	 */
	public function reschedule_all() {
		self::clear_all_events();
		$this->ensure_events_scheduled();
	}

	/**
	 * Cron callback for the daily refresh.
	 *
	 * Reschedules itself at the end so the next firing stays anchored to the
	 * configured wall-clock time, surviving DST transitions. The reschedule
	 * is wrapped in a try/finally so a runner failure can't break the cron.
	 *
	 * @return void
	 */
	public function handle_daily_refresh() {
		if ( ! ThisWeekSettings::is_enabled() ) {
			return;
		}

		try {
			( new SignalRunner() )->run();
		} finally {
			$this->ensure_events_scheduled();
		}
	}

	/**
	 * Cron callback for the weekly digest.
	 *
	 * @return void
	 */
	public function handle_weekly_digest() {
		if ( ! ThisWeekSettings::is_digest_enabled() ) {
			return;
		}

		try {
			( new DigestMailer() )->send( SignalStore::unresolved() );
		} finally {
			$this->ensure_events_scheduled();
		}
	}

	/**
	 * Return the next UTC timestamp for the daily refresh, in site timezone.
	 *
	 * @return int
	 */
	public function next_daily_timestamp() {
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );

		$target = $now->setTime( self::DAILY_HOUR, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}

		return $target->getTimestamp();
	}

	/**
	 * Return the next UTC timestamp for the weekly digest, in site timezone.
	 *
	 * @return int
	 */
	public function next_weekly_timestamp() {
		$tz     = wp_timezone();
		$now    = new \DateTimeImmutable( 'now', $tz );
		$time   = ThisWeekSettings::digest_time();
		$day    = ThisWeekSettings::digest_day();
		$parts  = explode( ':', $time );
		$hour   = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$minute = isset( $parts[1] ) ? (int) $parts[1] : 0;

		// PHP's "next $day" always advances at least one day, so a Monday
		// 08:00 save for Monday 09:00 would get scheduled a week late. Use
		// "this $day" — the Monday of the current week — and then push to
		// next week only when the resulting target is already in the past.
		$target = $now->modify( 'this ' . $day )->setTime( $hour, $minute );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 week' );
		}

		return $target->getTimestamp();
	}
}
