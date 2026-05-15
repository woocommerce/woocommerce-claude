/**
 * Shared Hey Woo workflow metadata.
 */
import { __ } from '@wordpress/i18n';

export interface WorkflowAction {
	slug: string;
	label: string;
	description: string;
	message: string;
}

export const WORKFLOWS: WorkflowAction[] = [
	{
		slug: 'weekly-store-review',
		label: __( 'Weekly review', 'hey-woo' ),
		description: __( 'Revenue, orders, products, customers, and next steps.', 'hey-woo' ),
		message: __( 'Give me my weekly store review.', 'hey-woo' ),
	},
	{
		slug: 'revenue-drop-triage',
		label: __( 'Revenue drop', 'hey-woo' ),
		description: __( 'Find what changed across dates, products, channels, and orders.', 'hey-woo' ),
		message: __( 'Revenue is down this month. Triage it.', 'hey-woo' ),
	},
	{
		slug: 'refund-triage',
		label: __( 'Refunds', 'hey-woo' ),
		description: __( 'Spot refund drivers, rates, products, and customer segments.', 'hey-woo' ),
		message: __( 'Triage refunds from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'coupon-performance-triage',
		label: __( 'Coupons', 'hey-woo' ),
		description: __( 'Review discount cost, usage, order value, and margin risk.', 'hey-woo' ),
		message: __( 'Are my coupons working? Triage the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'customer-value-review',
		label: __( 'Customer value', 'hey-woo' ),
		description: __( 'Compare new, returning, repeat, and high-value customers.', 'hey-woo' ),
		message: __( 'Review customer value and repeat purchasing.', 'hey-woo' ),
	},
	{
		slug: 'product-performance-review',
		label: __( 'Products', 'hey-woo' ),
		description: __( 'Rank product revenue, units, refunds, and momentum.', 'hey-woo' ),
		message: __( 'Review product performance from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'channel-performance-review',
		label: __( 'Channels', 'hey-woo' ),
		description: __( 'Compare sources, campaigns, devices, and attribution patterns.', 'hey-woo' ),
		message: __( 'Review channel performance from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'payment-method-review',
		label: __( 'Payments', 'hey-woo' ),
		description: __( 'Review gateway mix, paid revenue, and payment pipeline issues.', 'hey-woo' ),
		message: __( 'Review payment methods from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'failed-order-triage',
		label: __( 'Failed orders', 'hey-woo' ),
		description: __( 'Check failed, on-hold, unpaid, and recoverable orders.', 'hey-woo' ),
		message: __( 'Triage failed and on-hold orders.', 'hey-woo' ),
	},
	{
		slug: 'customer-acquisition-review',
		label: __( 'Acquisition', 'hey-woo' ),
		description: __( 'Review new customer growth, channels, and first-order quality.', 'hey-woo' ),
		message: __( 'Review customer acquisition from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'inventory-risk-review',
		label: __( 'Inventory risk', 'hey-woo' ),
		description: __( 'Find low stock, out-of-stock, sale-priced, and fast-moving risks.', 'hey-woo' ),
		message: __( 'Review inventory risk from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'catalogue-merchandising-review',
		label: __( 'Merchandising', 'hey-woo' ),
		description: __( 'Find products to feature, refresh, de-emphasise, or investigate.', 'hey-woo' ),
		message: __( 'Review catalogue merchandising from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'geography-performance-review',
		label: __( 'Geography', 'hey-woo' ),
		description: __( 'Compare orders, revenue, refunds, and customers by country.', 'hey-woo' ),
		message: __( 'Review geography performance from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'shipping-method-review',
		label: __( 'Shipping', 'hey-woo' ),
		description: __( 'Review shipping method mix, charges, refunds, and order patterns.', 'hey-woo' ),
		message: __( 'Review shipping methods from the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'tax-reconciliation',
		label: __( 'Tax', 'hey-woo' ),
		description: __( 'Reconcile tax collected, refunded, pending, and shipping tax.', 'hey-woo' ),
		message: __( 'Reconcile tax collected in the last 30 days.', 'hey-woo' ),
	},
	{
		slug: 'catalog-audit',
		label: __( 'Catalogue audit', 'hey-woo' ),
		description: __( 'Review catalogue readiness, product data, and content gaps.', 'hey-woo' ),
		message: __( 'Run a catalogue audit.', 'hey-woo' ),
	},
	{
		slug: 'store-health-monitor',
		label: __( 'Store health', 'hey-woo' ),
		description: __( 'Check common store issues such as missing images and pricing gaps.', 'hey-woo' ),
		message: __( 'Check store health.', 'hey-woo' ),
	},
	{
		slug: 'product-content-generator',
		label: __( 'Product content', 'hey-woo' ),
		description: __( 'Draft product descriptions, FAQs, SEO metadata, and alt text.', 'hey-woo' ),
		message: __( 'Help improve product content.', 'hey-woo' ),
	},
];

const QUICK_WORKFLOW_SLUGS = [
	'weekly-store-review',
	'revenue-drop-triage',
	'refund-triage',
	'coupon-performance-triage',
	'product-performance-review',
	'payment-method-review',
];

export const QUICK_WORKFLOWS = WORKFLOWS.filter( ( workflow ) => QUICK_WORKFLOW_SLUGS.includes( workflow.slug ) );
