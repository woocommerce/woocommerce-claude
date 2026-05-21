<?php
/**
 * Signal detector contract for the "This Week" runner.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * A deterministic detector that decides whether a signal should fire.
 *
 * Detectors call the existing analytics / WooCommerce APIs directly and apply
 * threshold logic. They never call the AI. When a threshold is breached, the
 * detector returns a Detection — an array containing `slug`, `severity`,
 * `workflow_slug`, a deterministic fallback `title`, and a `raw_evidence`
 * payload that the runner hands to the AI for merchant-friendly summarisation.
 */
interface SignalDetectorInterface {

	/**
	 * Run the detection.
	 *
	 * @return array<string,mixed>|null Detection payload, or null when no signal fires.
	 */
	public function detect();

	/**
	 * Slug identifying this detector's signal type.
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Workflow slug whose SKILL.md is used to summarise a fired detection.
	 *
	 * @return string
	 */
	public function workflow_slug();
}
