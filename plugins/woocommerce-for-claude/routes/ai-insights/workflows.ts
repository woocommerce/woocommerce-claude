/**
 * Shared AI Insights workflow metadata.
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
		label: __( 'Weekly review', 'woocommerce-claude' ),
		description: __( 'Revenue, orders, products, customers, and next steps.', 'woocommerce-claude' ),
		message: __( 'Give me my weekly store review.', 'woocommerce-claude' ),
	},
	{
		slug: 'revenue-drop-triage',
		label: __( 'Revenue drop', 'woocommerce-claude' ),
		description: __( 'Find what changed across dates, products, channels, and orders.', 'woocommerce-claude' ),
		message: __( 'Revenue is down this month. Triage it.', 'woocommerce-claude' ),
	},
	{
		slug: 'refund-triage',
		label: __( 'Refunds', 'woocommerce-claude' ),
		description: __( 'Spot refund drivers, rates, products, and customer segments.', 'woocommerce-claude' ),
		message: __( 'Triage refunds from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'coupon-performance-triage',
		label: __( 'Coupons', 'woocommerce-claude' ),
		description: __( 'Review discount cost, usage, order value, and margin risk.', 'woocommerce-claude' ),
		message: __( 'Are my coupons working? Triage the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'customer-value-review',
		label: __( 'Customer value', 'woocommerce-claude' ),
		description: __( 'Compare new, returning, repeat, and high-value customers.', 'woocommerce-claude' ),
		message: __( 'Review customer value and repeat purchasing.', 'woocommerce-claude' ),
	},
	{
		slug: 'product-performance-review',
		label: __( 'Products', 'woocommerce-claude' ),
		description: __( 'Rank product revenue, units, refunds, and momentum.', 'woocommerce-claude' ),
		message: __( 'Review product performance from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'channel-performance-review',
		label: __( 'Channels', 'woocommerce-claude' ),
		description: __( 'Compare sources, campaigns, devices, and attribution patterns.', 'woocommerce-claude' ),
		message: __( 'Review channel performance from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'payment-method-review',
		label: __( 'Payments', 'woocommerce-claude' ),
		description: __( 'Review gateway mix, paid revenue, and payment pipeline issues.', 'woocommerce-claude' ),
		message: __( 'Review payment methods from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'failed-order-triage',
		label: __( 'Failed orders', 'woocommerce-claude' ),
		description: __( 'Check failed, on-hold, unpaid, and recoverable orders.', 'woocommerce-claude' ),
		message: __( 'Triage failed and on-hold orders.', 'woocommerce-claude' ),
	},
	{
		slug: 'customer-acquisition-review',
		label: __( 'Acquisition', 'woocommerce-claude' ),
		description: __( 'Review new customer growth, channels, and first-order quality.', 'woocommerce-claude' ),
		message: __( 'Review customer acquisition from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'inventory-risk-review',
		label: __( 'Inventory risk', 'woocommerce-claude' ),
		description: __( 'Find low stock, out-of-stock, sale-priced, and fast-moving risks.', 'woocommerce-claude' ),
		message: __( 'Review inventory risk from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'catalogue-merchandising-review',
		label: __( 'Merchandising', 'woocommerce-claude' ),
		description: __( 'Find products to feature, refresh, de-emphasise, or investigate.', 'woocommerce-claude' ),
		message: __( 'Review catalogue merchandising from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'geography-performance-review',
		label: __( 'Geography', 'woocommerce-claude' ),
		description: __( 'Compare orders, revenue, refunds, and customers by country.', 'woocommerce-claude' ),
		message: __( 'Review geography performance from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'shipping-method-review',
		label: __( 'Shipping', 'woocommerce-claude' ),
		description: __( 'Review shipping method mix, charges, refunds, and order patterns.', 'woocommerce-claude' ),
		message: __( 'Review shipping methods from the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'tax-reconciliation',
		label: __( 'Tax', 'woocommerce-claude' ),
		description: __( 'Reconcile tax collected, refunded, pending, and shipping tax.', 'woocommerce-claude' ),
		message: __( 'Reconcile tax collected in the last 30 days.', 'woocommerce-claude' ),
	},
	{
		slug: 'catalog-audit',
		label: __( 'Catalogue audit', 'woocommerce-claude' ),
		description: __( 'Review catalogue readiness, product data, and content gaps.', 'woocommerce-claude' ),
		message: __( 'Run a catalogue audit.', 'woocommerce-claude' ),
	},
	{
		slug: 'store-health-monitor',
		label: __( 'Store health', 'woocommerce-claude' ),
		description: __( 'Check common store issues such as missing images and pricing gaps.', 'woocommerce-claude' ),
		message: __( 'Check store health.', 'woocommerce-claude' ),
	},
	{
		slug: 'product-content-generator',
		label: __( 'Product content', 'woocommerce-claude' ),
		description: __( 'Draft product descriptions, FAQs, SEO metadata, and alt text.', 'woocommerce-claude' ),
		message: __( 'Help improve product content.', 'woocommerce-claude' ),
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
