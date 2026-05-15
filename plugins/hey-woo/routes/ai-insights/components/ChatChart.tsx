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

const MIN_BAR_POINT_WIDTH = 92;
const MAX_BAR_POINT_WIDTH = 220;
const BAR_AXIS_PADDING = 96;

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

function barChartMinWidth( spec: ChartSpec ): number | undefined {
	if ( spec.type !== 'bar' ) {
		return undefined;
	}

	const dataSets = spec.series.map( ( s ) => s.data );
	const pointCount = Math.max( 0, ...dataSets.map( ( points ) => points.length ) );
	const longestLabel = dataSets.reduce(
		( maxLength, points ) => Math.max(
			maxLength,
			...points.map( ( point ) => point.x.length )
		),
		0
	);

	if ( pointCount === 0 ) {
		return undefined;
	}

	const pointWidth = Math.min(
		MAX_BAR_POINT_WIDTH,
		Math.max( MIN_BAR_POINT_WIDTH, longestLabel * 7 )
	);

	return Math.max( 640, Math.ceil( pointCount * pointWidth + BAR_AXIS_PADDING ) );
}

export function ChatChart( { spec }: ChatChartProps ) {
	const minWidth = barChartMinWidth( spec );

	return (
		<figure className={ `hey-woo-chart hey-woo-chart--${ spec.type }` }>
			{ spec.title && (
				<figcaption className="hey-woo-chart__title">{ spec.title }</figcaption>
			) }
			<div className="hey-woo-chart__canvas">
				<div
					className="hey-woo-chart__plot"
					style={ minWidth ? { minWidth: `${ minWidth }px` } : undefined }
				>
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
			</div>
		</figure>
	);
}
