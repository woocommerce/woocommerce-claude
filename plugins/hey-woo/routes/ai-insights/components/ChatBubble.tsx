/**
 * ChatBubble — a single message bubble in the conversation.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage } from '../types';
import { MarkdownContent } from './MarkdownContent';
import { ChatChart } from './ChatChart';
import { ReportActionCards } from './ReportActionCards';
import { parseReportActions } from '../report-actions';
import { parseStructuredReports } from '../report-structure';
import { StructuredReportView } from './StructuredReportView';

interface ChatBubbleProps {
	message: ChatMessage;
}

export function ChatBubble( { message }: ChatBubbleProps ) {
	const isUser = message.role === 'user';
	const reportActions = isUser
		? { content: message.content, actions: [] }
		: parseReportActions( message.content );
	const structuredReports = isUser
		? { content: reportActions.content, reports: [], actions: [] }
		: parseStructuredReports( reportActions.content );
	const recommendedActions = [
		...structuredReports.actions,
		...reportActions.actions,
	];

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
					{ structuredReports.content && (
						<MarkdownContent content={ structuredReports.content } />
					) }
					{ structuredReports.reports.map( ( report, index ) => (
						<StructuredReportView
							key={ `${ report.title }-${ index }` }
							report={ report }
						/>
					) ) }
					<ReportActionCards actions={ recommendedActions } />
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
