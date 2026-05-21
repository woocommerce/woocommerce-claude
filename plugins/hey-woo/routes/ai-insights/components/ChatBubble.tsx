/**
 * ChatBubble — a single message bubble in the conversation.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage, FeedbackRating } from '../types';
import { MarkdownContent } from './MarkdownContent';
import { ChatChart } from './ChatChart';
import { ReportActionCards } from './ReportActionCards';
import { MessageFeedback } from './MessageFeedback';
import { parseReportActions } from '../report-actions';

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
	const { content, actions } = isUser
		? { content: message.content, actions: [] }
		: parseReportActions( message.content );

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
					{ content && <MarkdownContent content={ content } /> }
					<ReportActionCards actions={ actions } />
					{ message.charts && message.charts.length > 0 && (
						<div className="hey-woo-charts">
							{ message.charts.map( ( spec, i ) => (
								<ChatChart key={ i } spec={ spec } />
							) ) }
						</div>
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
