/**
 * ChatBubble — a single message bubble in the conversation.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage, FeedbackRating } from '../types';
import { parseFollowups } from '../report-followups';
import { FollowupChips } from './FollowupChips';
import { MarkdownContent } from './MarkdownContent';
import { MessageFeedback } from './MessageFeedback';

interface ChatBubbleProps {
	message: ChatMessage;
	isLatest?: boolean;
	onAskFollowup?: ( prompt: string ) => void;
	followupDisabled?: boolean;
	onSubmitFeedback?: (
		messageId: number,
		rating: FeedbackRating,
		comment?: string
	) => Promise< void >;
}

export function ChatBubble( {
	message,
	isLatest,
	onAskFollowup,
	followupDisabled,
	onSubmitFeedback,
}: ChatBubbleProps ) {
	const isUser = message.role === 'user';
	const { content, suggestions } = isUser
		? { content: message.content, suggestions: [] }
		: parseFollowups( message.content );

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
					<MarkdownContent content={ content } />
					{ isLatest && onAskFollowup && (
						<FollowupChips
							suggestions={ suggestions }
							onAsk={ onAskFollowup }
							disabled={ followupDisabled }
						/>
					) }
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
