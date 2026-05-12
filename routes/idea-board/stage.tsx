/**
 * Idea board stage — visual brainstorming board for store signals.
 */
import { IdeaBoard } from '../../packages/idea-board/src';
import moduleData from '../ai-insights/data';

export function stage() {
	return <IdeaBoard restBase={ moduleData.restBase } nonce={ moduleData.nonce } days={ 90 } />;
}
