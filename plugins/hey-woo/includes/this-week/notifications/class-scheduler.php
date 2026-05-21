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
		add_filter( 'cron_schedules', array( $this, 'register_weekly_schedule' ) );
		add_action( self::HOOK_DAILY_REFRESH, array( $this, 'handle_daily_refresh' ) );
		add_action( self::HOOK_WEEKLY_DIGEST, array( $this, 'handle_weekly_digest' ) );
		add_action( 'update_option_' . ThisWeekSettings::OPTION_DIGEST_DAY, array( $this, 'reschedule_weekly_digest' ), 10, 0 );
		add_action( 'update_option_' . ThisWeekSettings::OPTION_DIGEST_TIME, array( $this, 'reschedule_weekly_digest' ), 10, 0 );
		add_action( 'update_option_' . ThisWeekSettings::OPTION_ENABLED, array( $this, 'reschedule_all' ), 10, 0 );
		add_action( 'update_option_' . ThisWeekSettings::OPTION_DIGEST_ENABLED, array( $this, 'reschedule_weekly_digest' ), 10, 0 );
	}

	/**
	 * Register a `weekly` schedule for wp-cron.
	 *
	 * WordPress core ships hourly/twicedaily/daily; weekly recurrence has to
	 * be added by plugins via the `cron_schedules` filter.
	 *
	 * @param array<string,array<string,mixed>> $schedules Registered cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_weekly_schedule( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			return array();
		}

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'hey-woo' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule both events to fire at their next site-local times.
	 *
	 * Idempotent — when an event is already scheduled, leaves it alone.
	 *
	 * @return void
	 */
	public function ensure_events_scheduled() {
		if ( ThisWeekSettings::is_enabled() && ! wp_next_scheduled( self::HOOK_DAILY_REFRESH ) ) {
			wp_schedule_event( $this->next_daily_timestamp(), 'daily', self::HOOK_DAILY_REFRESH );
		}

		if ( ThisWeekSettings::is_digest_enabled() && ! wp_next_scheduled( self::HOOK_WEEKLY_DIGEST ) ) {
			wp_schedule_event( $this->next_weekly_timestamp(), 'weekly', self::HOOK_WEEKLY_DIGEST );
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
	 * Re-schedule both events when the master toggle changes.
	 *
	 * @return void
	 */
	public function reschedule_all() {
		self::clear_all_events();
		$this->ensure_events_scheduled();
	}

	/**
	 * Re-schedule only the weekly digest after a digest-setting change.
	 *
	 * @return void
	 */
	public function reschedule_weekly_digest() {
		wp_clear_scheduled_hook( self::HOOK_WEEKLY_DIGEST );
		if ( ThisWeekSettings::is_digest_enabled() ) {
			wp_schedule_event( $this->next_weekly_timestamp(), 'weekly', self::HOOK_WEEKLY_DIGEST );
		}
	}

	/**
	 * Cron callback for the daily refresh.
	 *
	 * @return void
	 */
	public function handle_daily_refresh() {
		if ( ! ThisWeekSettings::is_enabled() ) {
			return;
		}

		( new SignalRunner() )->run();
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

		( new DigestMailer() )->send( SignalStore::unresolved() );
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

		// "next $day" returns today's date when today already matches that
		// weekday, even when the time-of-day has already passed. We compare
		// against $now after applying the configured time and advance by a
		// week if needed.
		$target = $now->modify( 'next ' . $day )->setTime( $hour, $minute );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 week' );
		}

		return $target->getTimestamp();
	}
}
