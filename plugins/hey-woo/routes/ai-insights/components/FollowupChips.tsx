/**
 * FollowupChips — render the suggestions parsed out of an assistant turn
 * as clickable chips. Each click submits the chip text as a new user
 * message via the onAsk callback. Mirrors the Claude Code follow-up
 * pattern.
 */
import { __ } from '@wordpress/i18n';

interface FollowupChipsProps {
	suggestions: string[];
	onAsk: ( prompt: string ) => void;
	disabled?: boolean;
}

export function FollowupChips( { suggestions, onAsk, disabled }: FollowupChipsProps ) {
	if ( suggestions.length === 0 ) {
		return null;
	}

	return (
		<nav className="hey-woo-followups" aria-label={ __( 'Suggested follow-ups', 'hey-woo' ) }>
			<span className="hey-woo-followups__label">
				{ __( 'Suggested follow-ups', 'hey-woo' ) }
			</span>
			<ul className="hey-woo-followups__list">
				{ suggestions.map( ( prompt, i ) => (
					<li key={ i } className="hey-woo-followups__item">
						<button
							type="button"
							className="hey-woo-followups__chip"
							disabled={ disabled }
							onClick={ () => onAsk( prompt ) }
						>
							{ prompt }
						</button>
					</li>
				) ) }
			</ul>
		</nav>
	);
}
