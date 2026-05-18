/**
 * Idea board stage — visual brainstorming board for store signals.
 */
import { __ } from '@wordpress/i18n';
import { IdeaBoard } from '../../packages/idea-board/src';
import moduleData from '../ai-insights/data';

export function stage() {
	if ( ! moduleData.ideaBoardEnabled ) {
		return (
			<div className="hey-woo-idea-page">
				<header className="hey-woo-idea-header">
					<div className="hey-woo-idea-header__summary">
						<h1>{ __( 'Idea board', 'hey-woo' ) }</h1>
						<p className="hey-woo-idea-header__date-range">
							{ __( 'The idea board is not enabled for this store.', 'hey-woo' ) }
						</p>
					</div>
				</header>
			</div>
		);
	}

	return <IdeaBoard restBase={ moduleData.restBase } nonce={ moduleData.nonce } days={ 90 } />;
}
