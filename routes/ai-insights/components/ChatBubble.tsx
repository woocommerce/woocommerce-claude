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
			aria-label={ isUser ? __( 'You', 'woocommerce-claude' ) : __( 'Assistant', 'woocommerce-claude' ) }
		>
			<span className="hey-woo-bubble__role">
				{ isUser ? __( 'You', 'woocommerce-claude' ) : __( 'WooCommerce for Claude', 'woocommerce-claude' ) }
			</span>
			{ isUser ? (
				<p className="hey-woo-bubble__content hey-woo-bubble__content--plain">
					{ message.content }
				</p>
			) : (
				<>
					<MarkdownContent content={ message.content } />
					{ message.charts?.map( ( spec, i ) => (
						<ChatChart key={ i } spec={ spec } />
					) ) }
				</>
			) }
		</div>
	);
}
