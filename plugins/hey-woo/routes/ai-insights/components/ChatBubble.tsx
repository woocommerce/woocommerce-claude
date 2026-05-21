/**
 * ChatBubble — a single message bubble in the conversation.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage, FeedbackRating } from '../types';
import { MarkdownContent } from './MarkdownContent';
import { MessageFeedback } from './MessageFeedback';

interface ChatBubbleProps {
	message: ChatMessage;
	onSubmitFeedback?: (
		messageId: number,
		rating: FeedbackRating,
		comment?: string
	) => Promise< void >;
}

export function ChatBubble( { message, onSubmitFeedback }: ChatBubbleProps ) {
	const isUser = message.role === 'user';

	return (
		<div
			className={ `hey-woo-bubble hey-woo-bubble--${ message.role }` }
			role="article"
			aria-label={ isUser ? __( 'You', 'hey-woo' ) : __( 'Assistant', 'hey-woo' ) }
		>
			{ isUser ? (
				<p className="hey-woo-bubble__content hey-woo-bubble__content--plain">
					{ message.content }
				</p>
			) : (
				<>
					<MarkdownContent content={ message.content } />
					{ onSubmitFeedback && (
						<MessageFeedback
							messageId={ message.id }
							feedback={ message.feedback }
							onSubmit={ onSubmitFeedback }
						/>
					) }
				</>
			) }
		</div>
	);
}
