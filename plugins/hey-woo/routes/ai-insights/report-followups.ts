/**
 * Parse a trailing ```suggested-followups``` fenced block out of an
 * assistant message. The block is emitted by the model per a system-prompt
 * instruction; the UI hides it from the rendered markdown and surfaces the
 * suggestions as clickable chips below the message.
 *
 * Tolerant of:
 *  - Bullet markers `-`, `*`, or numbered (`1.`).
 *  - Trailing whitespace or blank lines inside the fence.
 *  - The block appearing only at the very end of the content (we anchor to
 *    end-of-string so a stray mid-message fence will not be consumed).
 *
 * If no valid block is found, returns the original content with an empty
 * suggestions list — chips simply do not render.
 */

const FOLLOWUP_FENCE_RE = /\n*```suggested-followups[^\n]*\n([\s\S]*?)\n```\s*$/i;
const BULLET_PREFIX_RE = /^\s*(?:[-*]|\d+\.)\s+/;

const MAX_SUGGESTIONS = 4;
const MAX_SUGGESTION_LENGTH = 240;

export interface ParsedFollowups {
	content: string;
	suggestions: string[];
}

export function parseFollowups( content: string ): ParsedFollowups {
	const match = content.match( FOLLOWUP_FENCE_RE );

	if ( ! match || match.index === undefined ) {
		return { content, suggestions: [] };
	}

	const block = match[ 1 ];
	const stripped = content.slice( 0, match.index ).trimEnd();

	const suggestions = block
		.split( /\r?\n/ )
		.map( ( line ) => line.replace( BULLET_PREFIX_RE, '' ).trim() )
		.filter( ( line ) => line.length > 0 && line.length <= MAX_SUGGESTION_LENGTH )
		.slice( 0, MAX_SUGGESTIONS );

	return { content: stripped, suggestions };
}
