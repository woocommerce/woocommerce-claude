/**
 * Workflow shortcut cards — the row of three cards at the bottom of Today.
 *
 * Each card is a static suggestion. Clicking it launches the underlying
 * workflow in /chat the same way a SignalList row does.
 */
import { __ } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';

interface ShortcutDefinition {
	slug: string;
	eyebrow: string;
	description: string;
}

const SHORTCUTS: ShortcutDefinition[] = [
	{
		slug: 'customer-value-review',
		eyebrow: __( 'CUSTOMER VALUE', 'hey-woo' ),
		description: __( 'Compare new, returning, repeat, and high-value customers.', 'hey-woo' ),
	},
	{
		slug: 'catalog-audit',
		eyebrow: __( 'CATALOGUE AUDIT', 'hey-woo' ),
		description: __( 'Review catalogue readiness, product data, and content gaps.', 'hey-woo' ),
	},
	{
		slug: 'inventory-risk-review',
		eyebrow: __( 'INVENTORY RISK', 'hey-woo' ),
		description: __( 'Find low stock, out-of-stock, sale-priced, and fast-moving risks.', 'hey-woo' ),
	},
];

export function WorkflowShortcuts() {
	const navigate = useNavigate();

	const launch = ( shortcut: ShortcutDefinition ) => {
		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: `/${ shortcut.slug }`,
				workflowDisplay: shortcut.eyebrow,
			},
		} );
	};

	return (
		<section className="hey-woo-shortcuts" aria-label={ __( 'Workflow shortcuts', 'hey-woo' ) }>
			{ SHORTCUTS.map( ( shortcut ) => (
				<button
					key={ shortcut.slug }
					type="button"
					className="hey-woo-shortcut"
					onClick={ () => launch( shortcut ) }
				>
					<span className="hey-woo-shortcut__eyebrow">{ shortcut.eyebrow }</span>
					<p className="hey-woo-shortcut__description">{ shortcut.description }</p>
				</button>
			) ) }
		</section>
	);
}
