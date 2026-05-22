/**
 * Shared Hey Woo workflow metadata.
 */
import { __ } from '@wordpress/i18n';
import {
	calendar,
	commentContent,
	gallery,
	globe,
	inbox,
	lifesaver,
	megaphone,
	payment,
	people,
	percent,
	receipt,
	reset,
	shield,
	shipping,
	store,
	tag,
	trendingDown,
	trendingUp,
} from '@wordpress/icons';

export interface WorkflowAction {
	slug: string;
	label: string;
	description: string;
	/**
	 * Longer benefit/why/when copy used on the workflows catalogue cards.
	 * Other surfaces (chat shortcuts, autocomplete) stay on `description`
	 * because they sit in narrower, more compact UI.
	 */
	summary?: string;
	message: string;
	icon: JSX.Element;
}

export const WORKFLOWS: WorkflowAction[] = [
	{
		slug: 'weekly-store-review',
		label: __( 'Weekly review', 'hey-woo' ),
		description: __( 'Revenue, orders, products, customers, and next steps.', 'hey-woo' ),
		summary: __( 'Take a week-over-week look at overall store performance — revenue, orders, products, customers — and walk away with a short list of recommended next steps.', 'hey-woo' ),
		message: __( 'Give me my weekly store review.', 'hey-woo' ),
		icon: calendar,
	},
	{
		slug: 'revenue-drop-triage',
		label: __( 'Revenue drop', 'hey-woo' ),
		description: __( 'Find what changed across dates, products, channels, and orders.', 'hey-woo' ),
		summary: __( 'Run this when sales have softened and you want a quick read on whether it’s a single product, a channel, or a wider trading issue — with concrete things to look at next.', 'hey-woo' ),
		message: __( 'Revenue is down this month. Triage it.', 'hey-woo' ),
		icon: trendingDown,
	},
	{
		slug: 'refund-triage',
		label: __( 'Refunds', 'hey-woo' ),
		description: __( 'Spot refund drivers, rates, products, and customer segments.', 'hey-woo' ),
		summary: __( 'Use this when refunds feel high or you want to keep ahead of returns. Surfaces which products, customers, and reasons are driving refunds so you can fix the upstream problem rather than just process the refund.', 'hey-woo' ),
		message: __( 'Triage refunds from the last 30 days.', 'hey-woo' ),
		icon: reset,
	},
	{
		slug: 'coupon-performance-triage',
		label: __( 'Coupons', 'hey-woo' ),
		description: __( 'Review discount cost, usage, order value, and margin risk.', 'hey-woo' ),
		summary: __( 'Run this after a promotion or once a quarter to see which coupon codes actually moved revenue, which ate margin, and which ones are worth keeping or retiring.', 'hey-woo' ),
		message: __( 'Are my coupons working? Triage the last 30 days.', 'hey-woo' ),
		icon: percent,
	},
	{
		slug: 'customer-value-review',
		label: __( 'Customer value', 'hey-woo' ),
		description: __( 'Compare new, returning, repeat, and high-value customers.', 'hey-woo' ),
		summary: __( 'Use this when planning loyalty, retention, or VIP work. Compares new vs. returning behaviour, repeat purchase rate, and where value is concentrated across your customer base.', 'hey-woo' ),
		message: __( 'Review customer value and repeat purchasing.', 'hey-woo' ),
		icon: people,
	},
	{
		slug: 'product-performance-review',
		label: __( 'Products', 'hey-woo' ),
		description: __( 'Rank product revenue, units, refunds, and momentum.', 'hey-woo' ),
		summary: __( 'Run this before merchandising, restock, or pricing decisions. Ranks products by revenue, units sold, refund rate, and recent momentum so you can prioritise where to push and where to pull back.', 'hey-woo' ),
		message: __( 'Review product performance from the last 30 days.', 'hey-woo' ),
		icon: tag,
	},
	{
		slug: 'channel-performance-review',
		label: __( 'Channels', 'hey-woo' ),
		description: __( 'Compare sources, campaigns, devices, and attribution patterns.', 'hey-woo' ),
		summary: __( 'Use this to see where customers are actually coming from — which sources, campaigns, and devices are converting — so you can spend marketing budget where it’s working.', 'hey-woo' ),
		message: __( 'Review channel performance from the last 30 days.', 'hey-woo' ),
		icon: megaphone,
	},
	{
		slug: 'payment-method-review',
		label: __( 'Payments', 'hey-woo' ),
		description: __( 'Review gateway mix, paid revenue, and payment pipeline issues.', 'hey-woo' ),
		summary: __( 'Run this monthly to keep tabs on which payment methods drive paid revenue and whether any gateway is causing on-hold or stuck pipeline you should chase before it ages out.', 'hey-woo' ),
		message: __( 'Review payment methods from the last 30 days.', 'hey-woo' ),
		icon: payment,
	},
	{
		slug: 'failed-order-triage',
		label: __( 'Failed orders', 'hey-woo' ),
		description: __( 'Check failed, on-hold, unpaid, and recoverable orders.', 'hey-woo' ),
		summary: __( 'Run this regularly to surface failed, on-hold, and unpaid orders worth chasing — money that’s still recoverable if you reach out before the customer moves on.', 'hey-woo' ),
		message: __( 'Triage failed and on-hold orders.', 'hey-woo' ),
		icon: lifesaver,
	},
	{
		slug: 'customer-acquisition-review',
		label: __( 'Acquisition', 'hey-woo' ),
		description: __( 'Review new customer growth, channels, and first-order quality.', 'hey-woo' ),
		summary: __( 'Use this before changing acquisition spend. Shows whether new customers are growing, which channels they’re coming from, and how their first orders compare with returning-customer behaviour.', 'hey-woo' ),
		message: __( 'Review customer acquisition from the last 30 days.', 'hey-woo' ),
		icon: trendingUp,
	},
	{
		slug: 'inventory-risk-review',
		label: __( 'Inventory risk', 'hey-woo' ),
		description: __( 'Find low stock, out-of-stock, sale-priced, and fast-moving risks.', 'hey-woo' ),
		summary: __( 'Run this weekly to catch low-stock and out-of-stock items, sale-priced products burning through inventory, and fast-moving SKUs that need a restock before you miss revenue.', 'hey-woo' ),
		message: __( 'Review inventory risk from the last 30 days.', 'hey-woo' ),
		icon: inbox,
	},
	{
		slug: 'catalogue-merchandising-review',
		label: __( 'Merchandising', 'hey-woo' ),
		description: __( 'Find products to feature, refresh, de-emphasise, or investigate.', 'hey-woo' ),
		summary: __( 'Use this when planning your next merchandising round. Surfaces which products deserve more visibility, which slow movers are worth refreshing, and which need investigating before they drag the catalogue.', 'hey-woo' ),
		message: __( 'Review catalogue merchandising from the last 30 days.', 'hey-woo' ),
		icon: gallery,
	},
	{
		slug: 'geography-performance-review',
		label: __( 'Geography', 'hey-woo' ),
		description: __( 'Compare orders, revenue, refunds, and customers by country.', 'hey-woo' ),
		summary: __( 'Run this when thinking about international expansion or tax thresholds. Compares orders, revenue, refunds, and customer count by country so you can see where to invest and where to keep an eye on compliance.', 'hey-woo' ),
		message: __( 'Review geography performance from the last 30 days.', 'hey-woo' ),
		icon: globe,
	},
	{
		slug: 'shipping-method-review',
		label: __( 'Shipping', 'hey-woo' ),
		description: __( 'Review shipping method mix, charges, refunds, and order patterns.', 'hey-woo' ),
		summary: __( 'Use this monthly to see which shipping methods customers actually pick, how much shipping you’re collecting, and whether any method is correlated with refunds or unfinished orders.', 'hey-woo' ),
		message: __( 'Review shipping methods from the last 30 days.', 'hey-woo' ),
		icon: shipping,
	},
	{
		slug: 'tax-reconciliation',
		label: __( 'Tax', 'hey-woo' ),
		description: __( 'Reconcile tax collected, refunded, pending, and shipping tax.', 'hey-woo' ),
		summary: __( 'Run this at the end of a tax period to reconcile tax collected, refunded, pending, and on shipping — useful before filing, or when figures don’t match what WooCommerce admin reports.', 'hey-woo' ),
		message: __( 'Reconcile tax collected in the last 30 days.', 'hey-woo' ),
		icon: receipt,
	},
	{
		slug: 'catalog-audit',
		label: __( 'Catalogue audit', 'hey-woo' ),
		description: __( 'Review catalogue readiness, product data, and content gaps.', 'hey-woo' ),
		summary: __( 'Use this when prepping the store for new traffic or AI search. Audits product data, content quality, and schema coverage so you know what’s holding readiness back before it costs you discoverability.', 'hey-woo' ),
		message: __( 'Run a catalogue audit.', 'hey-woo' ),
		icon: shield,
	},
	{
		slug: 'store-health-monitor',
		label: __( 'Store health', 'hey-woo' ),
		description: __( 'Check common store issues such as missing images and pricing gaps.', 'hey-woo' ),
		summary: __( 'Run this regularly to catch common issues that hurt discoverability and trust: missing product images, empty descriptions, pricing gaps, and catalogue structure problems.', 'hey-woo' ),
		message: __( 'Check store health.', 'hey-woo' ),
		icon: store,
	},
	{
		slug: 'product-content-generator',
		label: __( 'Product content', 'hey-woo' ),
		description: __( 'Draft product descriptions, FAQs, SEO metadata, and alt text.', 'hey-woo' ),
		summary: __( 'Use this when launching a product or refreshing existing listings. Drafts merchant-tone descriptions, FAQs, SEO metadata, and alt text — ready for you to review and publish.', 'hey-woo' ),
		message: __( 'Help improve product content.', 'hey-woo' ),
		icon: commentContent,
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
