/**
 * Rich structured report renderer for workflow outputs.
 */
import { __, sprintf } from '@wordpress/i18n';
import type {
	ReportCaveat,
	ReportInsight,
	ReportMetricTile,
	ReportSource,
	ReportTable,
	StructuredReport,
} from '../report-structure';
import { ChatChart } from './ChatChart';

interface StructuredReportViewProps {
	report: StructuredReport;
}

const TONE_LABELS = {
	positive: __( 'Positive', 'hey-woo' ),
	warning: __( 'Watch', 'hey-woo' ),
	negative: __( 'Concern', 'hey-woo' ),
	neutral: __( 'Signal', 'hey-woo' ),
};

function MetricTile( { tile }: { tile: ReportMetricTile } ) {
	return (
		<div className={ `hey-woo-structured-report-metric hey-woo-structured-report-metric--${ tile.tone }` }>
			<span>{ tile.label }</span>
			<strong>{ tile.value }</strong>
			{ tile.trend && <small>{ tile.trend }</small> }
			{ tile.caption && <em>{ tile.caption }</em> }
		</div>
	);
}

function InsightRow( { insight, index }: { insight: ReportInsight; index: number } ) {
	return (
		<article className={ `hey-woo-structured-report-insight hey-woo-structured-report-insight--${ insight.tone }` }>
			<div className="hey-woo-structured-report-insight__number">
				{ String( index + 1 ).padStart( 2, '0' ) }
			</div>
			<div className="hey-woo-structured-report-insight__body">
				<div className="hey-woo-structured-report-insight__meta">
					{ insight.category && <span>{ insight.category }</span> }
					<span>{ insight.status || TONE_LABELS[ insight.tone ] }</span>
				</div>
				<h4>{ insight.title }</h4>
				<p>{ insight.summary }</p>
				{ insight.metric && (
					<strong className="hey-woo-structured-report-insight__metric">
						{ insight.metric }
					</strong>
				) }
			</div>
		</article>
	);
}

function ReportTableView( { table }: { table: ReportTable } ) {
	return (
		<section className="hey-woo-structured-report-section">
			<h3>{ table.title }</h3>
			<div className="hey-woo-structured-report-table-wrap">
				<table className="hey-woo-structured-report-table">
					<thead>
						<tr>
							{ table.columns.map( ( column ) => (
								<th key={ column }>{ column }</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ table.rows.map( ( row, rowIndex ) => (
							<tr key={ rowIndex }>
								{ table.columns.map( ( column, columnIndex ) => (
									<td key={ `${ column }-${ columnIndex }` }>
										{ row[ columnIndex ] || '' }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ table.note && <p className="hey-woo-structured-report-note">{ table.note }</p> }
		</section>
	);
}

function Caveat( { caveat }: { caveat: ReportCaveat } ) {
	return (
		<div className={ `hey-woo-structured-report-caveat hey-woo-structured-report-caveat--${ caveat.tone }` }>
			<strong>{ caveat.title }</strong>
			<p>{ caveat.detail }</p>
		</div>
	);
}

function Sources( { sources }: { sources: ReportSource[] } ) {
	if ( ! sources.length ) {
		return null;
	}

	return (
		<section className="hey-woo-structured-report-sources">
			<h3>
				{ sprintf(
					/* translators: %d: number of sources */
					__( 'Sources · %d', 'hey-woo' ),
					sources.length
				) }
			</h3>
			<ol>
				{ sources.map( ( source ) => (
					<li key={ `${ source.label }-${ source.detail || '' }` }>
						<strong>{ source.label }</strong>
						{ source.detail && <span>{ source.detail }</span> }
					</li>
				) ) }
			</ol>
		</section>
	);
}

export function StructuredReportView( { report }: StructuredReportViewProps ) {
	return (
		<article className="hey-woo-structured-report">
			<header className="hey-woo-structured-report__header">
				{ report.subtitle && (
					<p className="hey-woo-structured-report__eyebrow">{ report.subtitle }</p>
				) }
				<h2>{ report.title }</h2>
				{ report.summary && <p>{ report.summary }</p> }
			</header>

			{ report.metricTiles.length > 0 && (
				<section className="hey-woo-structured-report-metrics" aria-label={ __( 'Report metrics', 'hey-woo' ) }>
					{ report.metricTiles.map( ( tile ) => (
						<MetricTile key={ `${ tile.label }-${ tile.value }` } tile={ tile } />
					) ) }
				</section>
			) }

			{ report.insights.length > 0 && (
				<section className="hey-woo-structured-report-section">
					<h3>{ __( 'Key findings', 'hey-woo' ) }</h3>
					<div className="hey-woo-structured-report-insights">
						{ report.insights.map( ( insight, index ) => (
							<InsightRow
								key={ `${ insight.title }-${ index }` }
								insight={ insight }
								index={ index }
							/>
						) ) }
					</div>
				</section>
			) }

			{ report.charts.length > 0 && (
				<section className="hey-woo-structured-report-section">
					<h3>{ __( 'Visuals', 'hey-woo' ) }</h3>
					<div className="hey-woo-structured-report-charts">
						{ report.charts.map( ( chart, index ) => (
							<ChatChart key={ `${ chart.title }-${ index }` } spec={ chart } />
						) ) }
					</div>
				</section>
			) }

			{ report.tables.map( ( table ) => (
				<ReportTableView key={ table.title } table={ table } />
			) ) }

			{ report.caveats.length > 0 && (
				<section className="hey-woo-structured-report-section">
					<h3>{ __( 'Caveats', 'hey-woo' ) }</h3>
					<div className="hey-woo-structured-report-caveats">
						{ report.caveats.map( ( caveat ) => (
							<Caveat key={ caveat.title } caveat={ caveat } />
						) ) }
					</div>
				</section>
			) }

			<Sources sources={ report.sources } />
		</article>
	);
}
