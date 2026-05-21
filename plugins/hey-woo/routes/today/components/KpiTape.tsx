/**
 * KPI tape — four-cell metric strip above the Today signal list.
 */
import { __ } from '@wordpress/i18n';
import type { KpiCell } from '../signal-data';

interface KpiTapeProps {
	kpis: KpiCell[];
}

function formatChange( cell: KpiCell ): string {
	if ( cell.change_direction === 'flat' ) {
		return __( '±0%', 'hey-woo' );
	}

	const absolute = Math.abs( cell.change_percent );
	const rendered = absolute >= 10 ? Math.round( absolute ).toString() : absolute.toFixed( 1 );
	const sign = cell.change_direction === 'up' ? '+' : '-';

	return `${ sign }${ rendered }%`;
}

function changeClass( cell: KpiCell ): string {
	switch ( cell.tone ) {
		case 'positive':
			return 'hey-woo-kpi-tape__change hey-woo-kpi-tape__change--positive';
		case 'negative':
			return 'hey-woo-kpi-tape__change hey-woo-kpi-tape__change--negative';
		default:
			return 'hey-woo-kpi-tape__change hey-woo-kpi-tape__change--neutral';
	}
}

export function KpiTape( { kpis }: KpiTapeProps ) {
	if ( kpis.length === 0 ) {
		return null;
	}

	return (
		<section className="hey-woo-kpi-tape" aria-label={ __( 'Last 7 days metrics', 'hey-woo' ) }>
			{ kpis.map( ( cell ) => (
				<div key={ cell.id } className="hey-woo-kpi-tape__cell">
					<span className="hey-woo-kpi-tape__label">{ cell.label }</span>
					<div className="hey-woo-kpi-tape__value-row">
						<strong className="hey-woo-kpi-tape__value">{ cell.value }</strong>
						{ cell.change_direction !== 'flat' && (
							<span className={ changeClass( cell ) }>
								{ formatChange( cell ) }
							</span>
						) }
					</div>
				</div>
			) ) }
		</section>
	);
}
