/**
 * Parse the trailing "Next actions" / "Next steps" section from a merchant-friendly
 * markdown report into addable action cards.
 */
import type { ActionPriority } from '../actions/action-store';
import type { RecommendedReportAction } from './components/ReportActionCards';

interface ParsedReportActions {
	content: string;
	actions: RecommendedReportAction[];
}

const ACTION_HEADING_PATTERN = /^(#{1,6})\s+(?:\d+\.\s*)?(next actions?|next steps?|recommended actions?|actions?)\s*:?\s*$/i;

function priorityFromText( text: string ): ActionPriority {
	const normalised = text.toLowerCase();

	if ( /\b(high|urgent|p0|critical)\b/.test( normalised ) || normalised.includes( '🔴' ) ) {
		return 'high';
	}

	if ( /\b(low|p2|nice to have)\b/.test( normalised ) || normalised.includes( '🟢' ) ) {
		return 'low';
	}

	return 'medium';
}

function stripInlineMarkdown( text: string ): string {
	return text
		.replace( /\*\*(.+?)\*\*/g, '$1' )
		.replace( /__(.+?)__/g, '$1' )
		.replace( /\*(.+?)\*/g, '$1' )
		.replace( /_(.+?)_/g, '$1' )
		.replace( /`([^`]+)`/g, '$1' )
		.replace( /\[(.+?)\]\([^)]+\)/g, '$1' )
		.trim();
}

interface RawActionItem {
	titleLine: string;
	bodyLines: string[];
	subBullets: string[];
}

function splitTitleAndBody( titleLine: string ): { title: string; trailing: string } {
	const boldMatch = titleLine.match( /^\*\*(.+?)\*\*\s*[—–:-]?\s*(.*)$/ );
	if ( boldMatch ) {
		return {
			title: stripInlineMarkdown( boldMatch[ 1 ] ),
			trailing: stripInlineMarkdown( boldMatch[ 2 ] ),
		};
	}

	const sentenceMatch = titleLine.match( /^([^.!?]+[.!?])\s*(.*)$/ );
	if ( sentenceMatch && sentenceMatch[ 1 ].length <= 90 ) {
		return {
			title: stripInlineMarkdown( sentenceMatch[ 1 ].replace( /[.!?]$/, '' ) ),
			trailing: stripInlineMarkdown( sentenceMatch[ 2 ] ),
		};
	}

	return {
		title: stripInlineMarkdown( titleLine ),
		trailing: '',
	};
}

function rawActionToCard( raw: RawActionItem ): RecommendedReportAction | null {
	const { title, trailing } = splitTitleAndBody( raw.titleLine );

	if ( ! title ) {
		return null;
	}

	const evidenceParts: string[] = [];
	if ( trailing ) {
		evidenceParts.push( trailing );
	}
	for ( const line of raw.bodyLines ) {
		const cleaned = stripInlineMarkdown( line );
		if ( cleaned ) {
			evidenceParts.push( cleaned );
		}
	}

	const nextSteps = raw.subBullets
		.map( stripInlineMarkdown )
		.filter( Boolean )
		.slice( 0, 5 );

	const evidence = evidenceParts.join( ' ' ).replace( /\s+/g, ' ' ).trim();
	const priority = priorityFromText( `${ title } ${ evidence }` );

	return {
		title,
		priority,
		summary: evidence || undefined,
		keyMetric: undefined,
		impact: undefined,
		evidence,
		nextSteps,
		expectedOutcome: undefined,
	};
}

function indentOf( line: string ): number {
	const match = line.match( /^(\s*)/ );
	return match ? match[ 1 ].replace( /\t/g, '    ' ).length : 0;
}

function isListItem( line: string ): boolean {
	return /^\s*(?:[-*+]|\d+[.)])\s+/.test( line );
}

function stripListMarker( line: string ): string {
	return line.replace( /^\s*(?:[-*+]|\d+[.)])\s+/, '' ).trim();
}

function parseActionItems( lines: string[] ): RecommendedReportAction[] {
	const items: RawActionItem[] = [];
	let current: RawActionItem | null = null;
	let currentIndent = -1;

	for ( const rawLine of lines ) {
		const line = rawLine.replace( /\s+$/, '' );

		if ( ! line.trim() ) {
			continue;
		}

		if ( isListItem( line ) ) {
			const indent = indentOf( line );

			if ( current && indent > currentIndent ) {
				current.subBullets.push( stripListMarker( line ) );
				continue;
			}

			if ( current ) {
				items.push( current );
			}

			current = {
				titleLine: stripListMarker( line ),
				bodyLines: [],
				subBullets: [],
			};
			currentIndent = indent;
			continue;
		}

		if ( current && indentOf( line ) > currentIndent ) {
			current.bodyLines.push( line.trim() );
		}
	}

	if ( current ) {
		items.push( current );
	}

	return items
		.map( rawActionToCard )
		.filter( ( action ): action is RecommendedReportAction => action !== null )
		.slice( 0, 6 );
}

function findActionSection( lines: string[] ): { start: number; end: number; headingLevel: number } | null {
	let lastMatch: { start: number; headingLevel: number } | null = null;

	for ( let i = 0; i < lines.length; i++ ) {
		const match = lines[ i ].match( ACTION_HEADING_PATTERN );
		if ( match ) {
			lastMatch = { start: i, headingLevel: match[ 1 ].length };
		}
	}

	if ( ! lastMatch ) {
		return null;
	}

	let end = lines.length;
	for ( let i = lastMatch.start + 1; i < lines.length; i++ ) {
		const headingMatch = lines[ i ].match( /^(#{1,6})\s+\S/ );
		if ( headingMatch && headingMatch[ 1 ].length <= lastMatch.headingLevel ) {
			end = i;
			break;
		}
	}

	return { start: lastMatch.start, end, headingLevel: lastMatch.headingLevel };
}

export function parseReportActions( content: string ): ParsedReportActions {
	const lines = content.split( /\r?\n/ );
	const section = findActionSection( lines );

	if ( ! section ) {
		return { content: content.trim(), actions: [] };
	}

	const actionLines = lines.slice( section.start + 1, section.end );
	const actions = parseActionItems( actionLines );

	if ( actions.length === 0 ) {
		return { content: content.trim(), actions: [] };
	}

	const before = lines.slice( 0, section.start ).join( '\n' ).replace( /\s+$/, '' );
	const after = lines.slice( section.end ).join( '\n' ).replace( /^\s+/, '' );
	const cleaned = [ before, after ].filter( Boolean ).join( '\n\n' ).trim();

	return { content: cleaned, actions };
}
