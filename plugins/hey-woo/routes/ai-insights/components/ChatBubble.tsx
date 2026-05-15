/**
 * ChatBubble — a single message bubble in the conversation.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage } from '../types';
import { MarkdownContent } from './MarkdownContent';
import { ChatChart } from './ChatChart';

interface ChatBubbleProps {
	message: ChatMessage;
}

export function ChatBubble( { message }: ChatBubbleProps ) {
	const isUser = message.role === 'user';

	return (
		<div
			className={ `hey-woo-bubble hey-woo-bubble--${ message.role }` }
			role="article"
			aria-label={ isUser ? __( 'You', 'hey-woo' ) : __( 'Assistant', 'hey-woo' ) }
		>
			{ ! isUser && (
				<span className="hey-woo-bubble__role">
					{ __( 'Hey Woo', 'hey-woo' ) }
				</span>
			) }
			{ isUser ? (
				<p className="hey-woo-bubble__content hey-woo-bubble__content--plain">
					{ message.content }
				</p>
			) : (
				<>
					<MarkdownContent content={ message.content } />
					{ message.charts && message.charts.length > 0 && (
						<div className="hey-woo-charts">
							{ message.charts.map( ( spec, i ) => (
								<ChatChart key={ i } spec={ spec } />
							) ) }
						</div>
					) }
				</>
			) }
		</div>
	);
}
