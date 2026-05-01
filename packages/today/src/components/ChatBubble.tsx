/**
 * ChatBubble — a single message bubble in the conversation.
 */
import type { ChatMessage } from '../types';

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
			<p className="hey-woo-bubble__content">{ message.content }</p>
		</div>
	);
}
