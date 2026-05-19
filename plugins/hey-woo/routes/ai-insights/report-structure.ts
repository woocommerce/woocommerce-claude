/**
 * Parse structured report blocks from AI responses.
 */
import type { ChartSpec } from './types';
import {
	normaliseReportAction,
	type RawReportAction,
} from './report-actions';
import type { RecommendedReportAction } from './components/ReportActionCards';

export type ReportTone = 'positive' | 'warning' | 'negative' | 'neutral';

export interface ReportMetricTile {
	label: string;
	value: string;
	trend?: string;
	caption?: string;
	tone: ReportTone;
}

export interface ReportInsight {
	title: string;
	summary: string;
	category?: string;
	status?: string;
	metric?: string;
	tone: ReportTone;
}

export interface ReportTable {
	title: string;
	columns: string[];
	rows: string[][];
	note?: string;
}

export interface ReportCaveat {
	title: string;
	detail: string;
	tone: ReportTone;
}

export interface ReportSource {
	label: string;
	detail?: string;
}

export interface StructuredReport {
	title: string;
	subtitle?: string;
	summary?: string;
	metricTiles: ReportMetricTile[];
	insights: ReportInsight[];
	charts: ChartSpec[];
	tables: ReportTable[];
	caveats: ReportCaveat[];
	sources: ReportSource[];
	actions: RecommendedReportAction[];
}

interface ParsedStructuredReports {
	content: string;
	reports: StructuredReport[];
	actions: RecommendedReportAction[];
}

const REPORT_BLOCK_PATTERN = /```hey-woo-report\s*([\s\S]*?)```/gi;
const VALID_TONES: ReportTone[] = [ 'positive', 'warning', 'negative', 'neutral' ];
const VALID_CHART_TYPES = [ 'line', 'bar', 'pie' ];

function textValue( value: unknown ): string {
	return typeof value === 'string' ? value.trim() : '';
}

function textFromUnknown( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value.trim();
	}

	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return String( value );
	}

	return '';
}

function toneValue( value: unknown ): ReportTone {
	const tone = typeof value === 'string' ? value.toLowerCase() : '';

	return VALID_TONES.includes( tone as ReportTone )
		? tone as ReportTone
		: 'neutral';
}

function arrayValue( value: unknown ): unknown[] {
	return Array.isArray( value ) ? value : [];
}

function rawField( raw: Record< string, unknown >, snakeKey: string, camelKey: string ): unknown {
	return raw[ snakeKey ] ?? raw[ camelKey ];
}

function normaliseMetricTile( rawMetric: unknown ): ReportMetricTile | null {
	if ( ! rawMetric || typeof rawMetric !== 'object' ) {
		return null;
	}

	const raw = rawMetric as Record< string, unknown >;
	const label = textValue( raw.label );
	const value = textFromUnknown( raw.value );

	if ( ! label || ! value ) {
		return null;
	}

	return {
		label,
		value,
		trend: textFromUnknown( raw.trend ) || undefined,
		caption: textFromUnknown( raw.caption ) || undefined,
		tone: toneValue( raw.tone ),
	};
}

function normaliseInsight( rawInsight: unknown ): ReportInsight | null {
	if ( ! rawInsight || typeof rawInsight !== 'object' ) {
		return null;
	}

	const raw = rawInsight as Record< string, unknown >;
	const title = textValue( raw.title );
	const summary = textValue( raw.summary );

	if ( ! title || ! summary ) {
		return null;
	}

	return {
		title,
		summary,
		category: textValue( raw.category ) || undefined,
		status: textValue( raw.status ) || undefined,
		metric: textFromUnknown( raw.metric ) || undefined,
		tone: toneValue( raw.tone ),
	};
}

function normaliseTable( rawTable: unknown ): ReportTable | null {
	if ( ! rawTable || typeof rawTable !== 'object' ) {
		return null;
	}

	const raw = rawTable as Record< string, unknown >;
	const columns = arrayValue( raw.columns )
		.map( textFromUnknown )
		.filter( Boolean )
		.slice( 0, 8 );
	const rows = arrayValue( raw.rows )
		.map( ( row ) => arrayValue( row ).map( textFromUnknown ).slice( 0, columns.length ) )
		.filter( ( row ) => row.some( Boolean ) )
		.slice( 0, 10 );
	const title = textValue( raw.title ) || 'Details';

	if ( ! columns.length || ! rows.length ) {
		return null;
	}

	return {
		title,
		columns,
		rows,
		note: textValue( raw.note ) || undefined,
	};
}

function normaliseCaveat( rawCaveat: unknown ): ReportCaveat | null {
	if ( ! rawCaveat || typeof rawCaveat !== 'object' ) {
		return null;
	}

	const raw = rawCaveat as Record< string, unknown >;
	const title = textValue( raw.title );
	const detail = textValue( raw.detail );

	if ( ! title || ! detail ) {
		return null;
	}

	return {
		title,
		detail,
		tone: toneValue( raw.tone || 'warning' ),
	};
}

function normaliseSource( rawSource: unknown ): ReportSource | null {
	if ( ! rawSource || typeof rawSource !== 'object' ) {
		return null;
	}

	const raw = rawSource as Record< string, unknown >;
	const label = textValue( raw.label || raw.name );

	if ( ! label ) {
		return null;
	}

	return {
		label,
		detail: textValue( raw.detail || raw.description ) || undefined,
	};
}

function normaliseChart( rawChart: unknown ): ChartSpec | null {
	if ( ! rawChart || typeof rawChart !== 'object' ) {
		return null;
	}

	const raw = rawChart as Record< string, unknown >;
	const type = textValue( raw.type ).toLowerCase();
	const title = textValue( raw.title );
	const series = arrayValue( raw.series )
		.map( ( rawSeries ) => {
			if ( ! rawSeries || typeof rawSeries !== 'object' ) {
				return null;
			}

			const seriesRecord = rawSeries as Record< string, unknown >;
			const name = textValue( seriesRecord.name ) || title || 'Series';
			const data = arrayValue( seriesRecord.data )
				.map( ( rawPoint ) => {
					if ( ! rawPoint || typeof rawPoint !== 'object' ) {
						return null;
					}

					const point = rawPoint as Record< string, unknown >;
					const x = textFromUnknown( point.x );
					const y = Number( point.y );

					return x && Number.isFinite( y ) ? { x, y } : null;
				} )
				.filter( ( point ): point is { x: string; y: number } => point !== null )
				.slice( 0, 24 );

			return data.length ? { name, data } : null;
		} )
		.filter( ( item ): item is ChartSpec['series'][ number ] => item !== null )
		.slice( 0, 4 );

	if ( ! VALID_CHART_TYPES.includes( type ) || ! title || ! series.length ) {
		return null;
	}

	return {
		type: type as ChartSpec['type'],
		title,
		x_label: textValue( raw.x_label ?? raw.xLabel ) || undefined,
		y_label: textValue( raw.y_label ?? raw.yLabel ) || undefined,
		series,
	};
}

function normaliseStructuredReport( parsed: unknown ): StructuredReport | null {
	if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
		return null;
	}

	const raw = parsed as Record< string, unknown >;
	const title = textValue( raw.title );

	if ( ! title ) {
		return null;
	}

	return {
		title,
		subtitle: textValue( raw.subtitle ) || undefined,
		summary: textValue( raw.summary ) || undefined,
		metricTiles: arrayValue( rawField( raw, 'metric_tiles', 'metricTiles' ) )
			.map( normaliseMetricTile )
			.filter( ( item ): item is ReportMetricTile => item !== null )
			.slice( 0, 6 ),
		insights: arrayValue( raw.insights )
			.map( normaliseInsight )
			.filter( ( item ): item is ReportInsight => item !== null )
			.slice( 0, 6 ),
		charts: arrayValue( raw.charts )
			.map( normaliseChart )
			.filter( ( item ): item is ChartSpec => item !== null )
			.slice( 0, 3 ),
		tables: arrayValue( raw.tables )
			.map( normaliseTable )
			.filter( ( item ): item is ReportTable => item !== null )
			.slice( 0, 3 ),
		caveats: arrayValue( raw.caveats )
			.map( normaliseCaveat )
			.filter( ( item ): item is ReportCaveat => item !== null )
			.slice( 0, 3 ),
		sources: arrayValue( raw.sources )
			.map( normaliseSource )
			.filter( ( item ): item is ReportSource => item !== null )
			.slice( 0, 8 ),
		actions: arrayValue( raw.actions )
			.map( ( action ) => normaliseReportAction( action as RawReportAction ) )
			.filter( ( action ): action is RecommendedReportAction => action !== null )
			.slice( 0, 6 ),
	};
}

function parseJsonObject( json: string ): StructuredReport | null {
	try {
		return normaliseStructuredReport( JSON.parse( json ) );
	} catch {
		return null;
	}
}

export function parseStructuredReports( content: string ): ParsedStructuredReports {
	const reports: StructuredReport[] = [];
	const cleanedContent = content.replace( REPORT_BLOCK_PATTERN, ( _match, json ) => {
		const report = parseJsonObject( json );
		if ( report ) {
			reports.push( report );
		}

		return '';
	} ).trim();

	return {
		content: cleanedContent,
		reports,
		actions: reports.flatMap( ( report ) => report.actions ),
	};
}
