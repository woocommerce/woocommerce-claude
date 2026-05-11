/**
 * ChatBubble — a single message bubble in the conversation.
 */
import type { ChatMessage } from '../types';
import { MarkdownContent } from './MarkdownContent';

interface ChatBubbleProps {
	message: ChatMessage;
}

export function ChatBubble( { message }: ChatBubbleProps ) {
	const isUser = message.role === 'user';

	return (
		<div
			className={ `hey-woo-bubble hey-woo-bubble--${ message.role }` }
			role="article"
			aria-label={ isUser ? 'You' : 'Assistant' }
		>
			<span className="hey-woo-bubble__role">
				{ isUser ? 'You' : 'Hey Woo' }
			</span>
			{ isUser ? (
				<p className="hey-woo-bubble__content hey-woo-bubble__content--plain">
					{ message.content }
				</p>
			) : (
				<MarkdownContent content={ message.content } />
			) }
		</div>
	);
}
