<?php
/**
 * Weekly "This Week" email digest sender.
 *
 * Sends to the WooCommerce admin email — the same address WC uses for new
 * order / failed order emails — so the digest lands in the inbox the
 * merchant already monitors. Skipped silently when there are no unresolved
 * signals; merchants who don't want any digests can disable it under
 * WooCommerce > Settings > Hey Woo.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Notifications
 */

namespace WooCommerce\HeyWoo\ThisWeek\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Compose and send the weekly digest.
 */
class DigestMailer {

	/**
	 * Severity order used when ranking signals for the digest.
	 *
	 * @return array<string,int>
	 */
	private static function severity_weight() {
		return array(
			'high'   => 0,
			'medium' => 1,
			'low'    => 2,
		);
	}

	/**
	 * Send the digest for the supplied signals.
	 *
	 * Skips sending when the list is empty so merchants don't get "nothing
	 * to do" emails — the absence of mail is itself the all-clear signal.
	 *
	 * @param array<int,array<string,mixed>> $signals Unresolved signals.
	 * @return bool Whether wp_mail returned success.
	 */
	public function send( array $signals ) {
		if ( empty( $signals ) ) {
			return false;
		}

		$ranked    = $this->rank_and_cap( $signals, 3 );
		$recipient = $this->recipient_email();
		if ( '' === $recipient ) {
			return false;
		}

		$store_name = (string) get_bloginfo( 'name' );
		$subject    = $this->subject_line( $store_name, count( $ranked ) );
		$plain_body = $this->plain_body( $store_name, $ranked );
		$html_body  = $this->html_body( $store_name, $ranked );

		add_filter( 'wp_mail_content_type', array( $this, 'html_content_type' ) );
		$sent = wp_mail( $recipient, $subject, $html_body, array(), array() );
		remove_filter( 'wp_mail_content_type', array( $this, 'html_content_type' ) );

		// Logged for support visibility — plain body is the canonical record.
		unset( $plain_body );

		return (bool) $sent;
	}

	/**
	 * Filter callback to send the digest as HTML.
	 *
	 * @return string
	 */
	public function html_content_type() {
		return 'text/html';
	}

	/**
	 * Rank signals by severity then by detection recency and cap the list.
	 *
	 * @param array<int,array<string,mixed>> $signals Raw signal list.
	 * @param int                            $limit   Maximum number to keep.
	 * @return array<int,array<string,mixed>>
	 */
	private function rank_and_cap( array $signals, $limit ) {
		$weights = self::severity_weight();

		usort(
			$signals,
			static function ( $a, $b ) use ( $weights ) {
				$severity_a = isset( $a['severity'] ) ? (string) $a['severity'] : 'medium';
				$severity_b = isset( $b['severity'] ) ? (string) $b['severity'] : 'medium';
				$weight_a   = isset( $weights[ $severity_a ] ) ? $weights[ $severity_a ] : $weights['medium'];
				$weight_b   = isset( $weights[ $severity_b ] ) ? $weights[ $severity_b ] : $weights['medium'];

				if ( $weight_a !== $weight_b ) {
					return $weight_a <=> $weight_b;
				}

				$ts_a = isset( $a['last_detected_at'] ) ? (int) $a['last_detected_at'] : 0;
				$ts_b = isset( $b['last_detected_at'] ) ? (int) $b['last_detected_at'] : 0;

				return $ts_b <=> $ts_a;
			}
		);

		return array_slice( $signals, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Build the subject line.
	 *
	 * @param string $store_name Store name.
	 * @param int    $count      Number of signals included.
	 * @return string
	 */
	private function subject_line( $store_name, $count ) {
		return sprintf(
			/* translators: 1: store name, 2: number of signals included in the digest */
			_n(
				'This week at %1$s: %2$d thing to check',
				'This week at %1$s: %2$d things to check',
				$count,
				'hey-woo'
			),
			$store_name,
			$count
		);
	}

	/**
	 * Recipient — WC admin email, falling back to the site admin.
	 *
	 * @return string
	 */
	private function recipient_email() {
		$wc_email = (string) get_option( 'woocommerce_email_from_address', '' );
		if ( '' !== $wc_email && is_email( $wc_email ) ) {
			return $wc_email;
		}

		$site_admin = (string) get_option( 'admin_email', '' );
		return is_email( $site_admin ) ? $site_admin : '';
	}

	/**
	 * Plain-text digest body. Kept for the future text/plain fallback when
	 * the merchant's mail client doesn't render the HTML version.
	 *
	 * @param string                         $store_name Store name.
	 * @param array<int,array<string,mixed>> $signals    Ranked signal list.
	 * @return string
	 */
	private function plain_body( $store_name, array $signals ) {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: store name */
			__( 'Hey Woo has spotted these things at %s this week:', 'hey-woo' ),
			$store_name
		);
		$lines[] = '';

		foreach ( $signals as $index => $signal ) {
			$position = $index + 1;
			$title    = isset( $signal['title'] ) ? (string) $signal['title'] : '';
			$summary  = isset( $signal['summary'] ) ? (string) $signal['summary'] : '';
			$evidence = isset( $signal['evidence'] ) && is_array( $signal['evidence'] ) ? $signal['evidence'] : array();
			$action   = isset( $signal['action'] ) && is_array( $signal['action'] ) ? $signal['action'] : array();

			$lines[] = sprintf( '%d. %s', $position, $title );
			if ( '' !== $summary ) {
				$lines[] = '   ' . $summary;
			}
			$evidence_line = $this->plain_evidence_line( $evidence );
			if ( '' !== $evidence_line ) {
				$lines[] = '   ' . $evidence_line;
			}
			$action_title = isset( $action['title'] ) ? (string) $action['title'] : '';
			if ( '' !== $action_title ) {
				$lines[] = '   → ' . $action_title;
			}
			$lines[] = '';
		}

		$lines[] = sprintf(
			/* translators: %s: URL to the This Week feed */
			__( 'See all signals: %s', 'hey-woo' ),
			$this->this_week_url()
		);

		return implode( "\n", $lines );
	}

	/**
	 * HTML digest body — kept intentionally minimal so email clients render
	 * it consistently and Hey Woo doesn't drift into bespoke email templates.
	 *
	 * @param string                         $store_name Store name.
	 * @param array<int,array<string,mixed>> $signals    Ranked signal list.
	 * @return string
	 */
	private function html_body( $store_name, array $signals ) {
		$intro = sprintf(
			/* translators: %s: store name */
			esc_html__( 'Hey Woo has spotted these things at %s this week:', 'hey-woo' ),
			esc_html( $store_name )
		);

		$items_html = '';
		foreach ( $signals as $signal ) {
			$items_html .= $this->html_signal_card( $signal );
		}

		$cta_url   = esc_url( $this->this_week_url() );
		$cta_label = esc_html__( 'Open the This Week feed', 'hey-woo' );

		return '<!DOCTYPE html><html><body style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; color: #1e1e1e; max-width: 640px; margin: 0 auto; padding: 24px;">'
			. '<p style="font-size: 14px; color: #555; margin: 0 0 16px;">' . $intro . '</p>'
			. $items_html
			. '<p style="margin: 24px 0 0;"><a href="' . $cta_url . '" style="background: #1e1e1e; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; display: inline-block;">' . $cta_label . '</a></p>'
			. '</body></html>';
	}

	/**
	 * Render a single signal as a self-contained HTML card.
	 *
	 * @param array<string,mixed> $signal Signal record.
	 * @return string
	 */
	private function html_signal_card( array $signal ) {
		$severity     = isset( $signal['severity'] ) ? (string) $signal['severity'] : 'medium';
		$severity_bar = $this->severity_color( $severity );
		$title        = isset( $signal['title'] ) ? (string) $signal['title'] : '';
		$summary      = isset( $signal['summary'] ) ? (string) $signal['summary'] : '';
		$evidence     = isset( $signal['evidence'] ) && is_array( $signal['evidence'] ) ? $signal['evidence'] : array();
		$action       = isset( $signal['action'] ) && is_array( $signal['action'] ) ? $signal['action'] : array();

		$evidence_line = $this->plain_evidence_line( $evidence );

		$card  = '<div style="border-left: 4px solid ' . esc_attr( $severity_bar ) . '; padding: 12px 16px; margin: 12px 0; background: #f6f7f7;">';
		$card .= '<h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 600;">' . esc_html( $title ) . '</h3>';
		if ( '' !== $summary ) {
			$card .= '<p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== $evidence_line ) {
			$card .= '<p style="margin: 0; font-size: 13px; color: #555;">' . esc_html( $evidence_line ) . '</p>';
		}
		if ( ! empty( $action['title'] ) ) {
			$card .= '<p style="margin: 8px 0 0; font-size: 13px;"><strong>' . esc_html__( 'Next step:', 'hey-woo' ) . '</strong> ' . esc_html( (string) $action['title'] ) . '</p>';
		}
		$card .= '</div>';

		return $card;
	}

	/**
	 * Map severity → email-safe colour hex.
	 *
	 * @param string $severity Severity slug.
	 * @return string
	 */
	private function severity_color( $severity ) {
		switch ( $severity ) {
			case 'high':
				return '#cc1818';
			case 'low':
				return '#3858e9';
			default:
				return '#b88600';
		}
	}

	/**
	 * Compose a single-line evidence summary.
	 *
	 * @param array<string,mixed> $evidence Evidence chip data.
	 * @return string
	 */
	private function plain_evidence_line( array $evidence ) {
		$label  = isset( $evidence['label'] ) ? (string) $evidence['label'] : '';
		$value  = isset( $evidence['value'] ) ? (string) $evidence['value'] : '';
		$change = isset( $evidence['change'] ) ? (string) $evidence['change'] : '';

		$parts = array();
		if ( '' !== $label ) {
			$parts[] = $label;
		}
		if ( '' !== $value ) {
			$parts[] = $value;
		}
		if ( '' !== $change ) {
			$parts[] = $change;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Deep link to the This Week feed in wp-admin.
	 *
	 * @return string
	 */
	private function this_week_url() {
		return admin_url( 'admin.php?page=hey-woo-insights#/this-week' );
	}
}
