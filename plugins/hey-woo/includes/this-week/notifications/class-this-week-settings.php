<?php
/**
 * Typed accessors for the "This Week" notification settings.
 *
 * Hides the raw option names and 'yes'/'no' string conventions WooCommerce
 * settings use, so the scheduler, runner, and digest don't depend on the
 * settings page implementation. Defaults live here too: monitoring is on,
 * the digest is on, and the default cadence is Monday 09:00 site-local time.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Notifications
 */

namespace WooCommerce\HeyWoo\ThisWeek\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Read the merchant's notification preferences with safe defaults.
 */
class ThisWeekSettings {

	const OPTION_ENABLED        = 'hey_woo_this_week_enabled';
	const OPTION_DIGEST_ENABLED = 'hey_woo_this_week_digest_enabled';
	const OPTION_DIGEST_DAY     = 'hey_woo_this_week_digest_day';
	const OPTION_DIGEST_TIME    = 'hey_woo_this_week_digest_time';

	const DEFAULT_DIGEST_DAY  = 'monday';
	const DEFAULT_DIGEST_TIME = '09:00';

	/**
	 * Weekday slugs accepted in the digest day option.
	 *
	 * @return string[]
	 */
	public static function valid_days() {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	/**
	 * Whether the proactive monitoring loop should run.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' );
	}

	/**
	 * Whether the weekly email digest should be sent.
	 *
	 * Always false when monitoring itself is off.
	 *
	 * @return bool
	 */
	public static function is_digest_enabled() {
		if ( ! self::is_enabled() ) {
			return false;
		}

		return 'no' !== get_option( self::OPTION_DIGEST_ENABLED, 'yes' );
	}

	/**
	 * Lowercase weekday slug for the digest schedule (e.g. "monday").
	 *
	 * @return string
	 */
	public static function digest_day() {
		$day = strtolower( (string) get_option( self::OPTION_DIGEST_DAY, self::DEFAULT_DIGEST_DAY ) );
		return in_array( $day, self::valid_days(), true ) ? $day : self::DEFAULT_DIGEST_DAY;
	}

	/**
	 * Digest send time as "HH:MM" in site-local time.
	 *
	 * @return string
	 */
	public static function digest_time() {
		$raw = (string) get_option( self::OPTION_DIGEST_TIME, self::DEFAULT_DIGEST_TIME );
		if ( 1 === preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $matches ) ) {
			return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
		}

		return self::DEFAULT_DIGEST_TIME;
	}

	/**
	 * PHP weekday index for the digest day (0 = Sunday, matches `date('w')`).
	 *
	 * @return int
	 */
	public static function digest_day_index() {
		$map = array(
			'sunday'    => 0,
			'monday'    => 1,
			'tuesday'   => 2,
			'wednesday' => 3,
			'thursday'  => 4,
			'friday'    => 5,
			'saturday'  => 6,
		);

		return $map[ self::digest_day() ];
	}
}
