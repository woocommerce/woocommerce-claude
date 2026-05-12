/**
 * ChatChart — renders a chart spec returned by the render_chart tool.
 *
 * Converts the flat {x, y} data format from the PHP backend into the
 * SeriesData / DataPointPercentage shapes that @automattic/charts expects.
 */
import '@automattic/charts/style.css';
import { LineChart, BarChart, PieChart } from '@automattic/charts';
import { __ } from '@wordpress/i18n';
import type { ChartSpec } from '../types';
import type { SeriesData, DataPointDate, DataPoint, DataPointPercentage } from '@automattic/charts';

interface ChatChartProps {
	spec: ChartSpec;
}

function toLineData( spec: ChartSpec ): SeriesData[] {
	return spec.series.map( ( s ) => ( {
		label: s.name,
		data: s.data.map( ( p ): DataPointDate => ( { dateString: p.x, value: p.y } ) ),
	} ) );
}

function toBarData( spec: ChartSpec ): SeriesData[] {
	return spec.series.map( ( s ) => ( {
		label: s.name,
		data: s.data.map( ( p ): DataPoint => ( { label: p.x, value: p.y } ) ),
	} ) );
}

function toPieData( spec: ChartSpec ): DataPointPercentage[] {
	return spec.series.map( ( s ) => ( {
		label: s.name,
		value: s.data.length > 0 ? s.data[ 0 ].y : 0,
	} ) );
}

export function ChatChart( { spec }: ChatChartProps ) {
	return (
		<figure className="hey-woo-chart">
			{ spec.title && (
				<figcaption className="hey-woo-chart__title">{ spec.title }</figcaption>
			) }
			<div className="hey-woo-chart__canvas">
				{ spec.type === 'line' && (
					<LineChart
						data={ toLineData( spec ) }
						withGradientFill={ false }
						withTooltips
						showLegend={ spec.series.length > 1 }
					/>
				) }
				{ spec.type === 'bar' && (
					<BarChart
						data={ toBarData( spec ) }
						withTooltips
						showLegend={ spec.series.length > 1 }
					/>
				) }
				{ spec.type === 'pie' && (
					<PieChart
						data={ toPieData( spec ) }
						withTooltips
						showLegend
					/>
				) }
			</div>
		</figure>
	);
}
