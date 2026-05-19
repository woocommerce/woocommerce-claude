/**
 * Today briefing route.
 */
import { Button } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';
import moduleData from './data';
import { WORKFLOWS } from './workflows';
import {
	buildWorkflowPrompt,
	getReportMetadata,
	launchChatWorkflow,
} from '../reports/report-data';
import type { BriefingItem, BriefingMonitor, FirstRunBriefing, StoredConversation } from './types';

type BriefingStatus = 'idle' | 'generating' | 'ready' | 'fallback';

const FALLBACK_BRIEFING: FirstRunBriefing = {
	source: 'fallback',
	generatedAt: 0,
	headline: __( 'Good morning.', 'hey-woo' ),
	summary: __( 'Start with a weekly review, then turn the strongest findings into actions or monitors.', 'hey-woo' ),
	metrics: [
		{
			label: __( 'Revenue', 'hey-woo' ),
			value: __( 'Not run yet', 'hey-woo' ),
			trend: __( 'Run weekly review', 'hey-woo' ),
			tone: 'neutral',
		},
		{
			label: __( 'Orders', 'hey-woo' ),
			value: __( 'Not run yet', 'hey-woo' ),
			trend: __( 'Check failed orders', 'hey-woo' ),
			tone: 'neutral',
		},
		{
			label: __( 'AOV', 'hey-woo' ),
			value: __( 'Not run yet', 'hey-woo' ),
			trend: __( 'Review basket value', 'hey-woo' ),
			tone: 'neutral',
		},
		{
			label: __( 'Customers', 'hey-woo' ),
			value: __( 'Not run yet', 'hey-woo' ),
			trend: __( 'Review acquisition', 'hey-woo' ),
			tone: 'neutral',
		},
		{
			label: __( 'Refunds', 'hey-woo' ),
			value: __( 'Not run yet', 'hey-woo' ),
			trend: __( 'Triage refund risk', 'hey-woo' ),
			tone: 'neutral',
		},
	],
	items: [
		{
			category: __( 'Briefing', 'hey-woo' ),
			status: __( 'Start here', 'hey-woo' ),
			title: __( 'Run the weekly store review', 'hey-woo' ),
			summary: __( 'Create the first store-wide briefing across revenue, orders, products, customers, and next steps.', 'hey-woo' ),
			workflowSlug: 'weekly-store-review',
		},
		{
			category: __( 'Trading', 'hey-woo' ),
			status: __( 'Watch', 'hey-woo' ),
			title: __( 'Check whether sales changed materially', 'hey-woo' ),
			summary: __( 'Use revenue triage when you need a clear explanation of what moved and which drivers are addressable.', 'hey-woo' ),
			workflowSlug: 'revenue-drop-triage',
		},
		{
			category: __( 'Operations', 'hey-woo' ),
			status: __( 'Useful monitor', 'hey-woo' ),
			title: __( 'Keep an eye on refunds and failed orders', 'hey-woo' ),
			summary: __( 'Refund and payment issues are strong candidates for action cards because the next steps are usually concrete.', 'hey-woo' ),
			workflowSlug: 'refund-triage',
		},
	],
	monitors: [
		{
			title: __( 'Weekly revenue', 'hey-woo' ),
			metric: __( 'Revenue versus previous period', 'hey-woo' ),
			cadence: __( 'Weekly', 'hey-woo' ),
			workflowSlug: 'weekly-store-review',
		},
		{
			title: __( 'Refund rate', 'hey-woo' ),
			metric: __( 'Refunded revenue and product drivers', 'hey-woo' ),
			cadence: __( 'Weekly', 'hey-woo' ),
			workflowSlug: 'refund-triage',
		},
		{
			title: __( 'Failed orders', 'hey-woo' ),
			metric: __( 'Failed, on-hold, and unpaid orders', 'hey-woo' ),
			cadence: __( 'Weekly', 'hey-woo' ),
			workflowSlug: 'failed-order-triage',
		},
	],
};

function firstName(): string {
	const name = moduleData.userName.trim();
	if ( ! name ) {
		return __( 'there', 'hey-woo' );
	}

	return name.split( /\s+/ )[ 0 ];
}

function formatToday(): string {
	return new Intl.DateTimeFormat( undefined, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( new Date() );
}

function runWorkflow( workflowSlug: string, displayText: string ): void {
	const workflow = WORKFLOWS.find( ( item ) => item.slug === workflowSlug );

	if ( ! workflow ) {
		return;
	}

	const metadata = getReportMetadata( workflow );
	const prompt = buildWorkflowPrompt(
		workflow,
		'now',
		metadata.defaultPeriod,
		true,
		metadata.defaultDay,
		'09:00',
		true,
		true
	);

	launchChatWorkflow( prompt, displayText );
}

function recentLibraryItems( conversations: StoredConversation[] ): StoredConversation[] {
	return conversations.slice( 0, 3 );
}

function stripActionBlocks( text: string ): string {
	return text
		.replace( /```hey-woo-actions[\s\S]*?```/gi, '' )
		.replace( /```hey-woo-report[\s\S]*?```/gi, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

function conversationPreview( conversation: StoredConversation ): string {
	const message = [ ...conversation.messages ].reverse().find( ( item ) => item.content.trim() );
	if ( ! message ) {
		return __( 'No messages yet.', 'hey-woo' );
	}

	const preview = stripActionBlocks( message.content );
	return preview.length > 110 ? `${ preview.slice( 0, 107 ) }...` : preview;
}

export function TodayBriefing() {
	const navigate = useNavigate();
	const [ briefing, setBriefing ] = useState< FirstRunBriefing >(
		moduleData.firstRunBriefing ?? FALLBACK_BRIEFING
	);
	const [ status, setStatus ] = useState< BriefingStatus >(
		moduleData.firstRunBriefing?.source === 'ai' ? 'ready' : 'idle'
	);
	const recentItems = useMemo(
		() => recentLibraryItems( moduleData.conversations ),
		[]
	);

	useEffect( () => {
		if ( ! moduleData.hasKey || briefing.source === 'ai' ) {
			return;
		}

		let cancelled = false;
		setStatus( 'generating' );

		void fetch( `${ moduleData.restBase }/briefing/first-run`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': moduleData.nonce,
			},
			body: JSON.stringify( {} ),
		} )
			.then( ( response ) => response.ok ? response.json() : null )
			.then( ( json ) => {
				if ( cancelled || ! json?.briefing ) {
					return;
				}

				setBriefing( json.briefing );
				setStatus( json.briefing.source === 'ai' ? 'ready' : 'fallback' );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setStatus( 'fallback' );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ briefing.source ] );

	const goToChat = () => {
		void navigate( {
			to: '/chat',
			search: {},
		} );
	};

	const goToReports = () => {
		void navigate( {
			to: '/reports',
			search: {},
		} );
	};

	const goToLibrary = () => {
		void navigate( {
			to: '/history',
			search: {},
		} );
	};

	const goToMonitors = () => {
		void navigate( {
			to: '/monitors',
			search: {},
		} );
	};

	return (
		<div className="hey-woo-page hey-woo-page--today">
			<header className="hey-woo-today-header">
				<div>
					<p className="hey-woo-today-header__eyebrow">
						{ sprintf(
							/* translators: %s: formatted date */
							__( 'Today · %s', 'hey-woo' ),
							formatToday()
						) }
					</p>
					<h1>
						{ sprintf(
							/* translators: %s: current user's first name */
							__( 'Good morning, %s.', 'hey-woo' ),
							firstName()
						) }
					</h1>
					<p>{ briefing.summary }</p>
					{ status === 'generating' && (
						<span className="hey-woo-today-header__status">
							{ __( 'Preparing first briefing with your connected AI...', 'hey-woo' ) }
						</span>
					) }
				</div>
				<div className="hey-woo-today-header__actions">
					<Button type="button" variant="secondary" onClick={ goToReports }>
						{ __( 'Reports', 'hey-woo' ) }
					</Button>
					<Button type="button" variant="primary" onClick={ goToChat }>
						{ __( 'New chat', 'hey-woo' ) }
					</Button>
				</div>
			</header>

			<section className="hey-woo-today-metrics" aria-label={ __( 'Briefing signals', 'hey-woo' ) }>
				{ briefing.metrics.map( ( metric ) => (
					<div key={ metric.label } className={ `hey-woo-today-metric hey-woo-today-metric--${ metric.tone }` }>
						<span>{ metric.label }</span>
						<strong>{ metric.value }</strong>
						<small>{ metric.trend }</small>
					</div>
				) ) }
			</section>

			<div className="hey-woo-today-layout">
				<main className="hey-woo-today-main">
					<div className="hey-woo-section-heading">
						<div>
							<h2>{ __( 'What to look at', 'hey-woo' ) }</h2>
							<p>{ __( 'A prioritised starting point for reports, investigations, and action cards.', 'hey-woo' ) }</p>
						</div>
					</div>

					<div className="hey-woo-today-insights">
						{ briefing.items.map( ( item: BriefingItem, index ) => (
							<article key={ `${ item.workflowSlug }-${ item.title }` } className="hey-woo-today-insight">
								<div className="hey-woo-today-insight__number">
									{ String( index + 1 ).padStart( 2, '0' ) }
								</div>
								<div className="hey-woo-today-insight__body">
									<div className="hey-woo-today-insight__meta">
										<span>{ item.category }</span>
										<span>{ item.status }</span>
									</div>
									<h3>{ item.title }</h3>
									<p>{ item.summary }</p>
									{ item.workflowSlug && (
										<Button
											type="button"
											variant="link"
											onClick={ () => runWorkflow( item.workflowSlug, item.title ) }
										>
											{ __( 'Run investigation', 'hey-woo' ) }
										</Button>
									) }
								</div>
							</article>
						) ) }
					</div>
				</main>

				<aside className="hey-woo-today-side">
					<section className="hey-woo-today-panel">
						<div className="hey-woo-section-heading">
							<div>
								<h2>{ __( 'Suggested monitors', 'hey-woo' ) }</h2>
								<p>{ __( 'Useful recurring checks to set up once scheduled runs are wired in.', 'hey-woo' ) }</p>
							</div>
							<Button type="button" variant="tertiary" onClick={ goToMonitors }>
								{ __( 'View all', 'hey-woo' ) }
							</Button>
						</div>
						<div className="hey-woo-today-monitor-list">
							{ briefing.monitors.map( ( monitor: BriefingMonitor ) => (
								<button
									key={ `${ monitor.workflowSlug }-${ monitor.title }` }
									type="button"
									className="hey-woo-today-monitor"
									onClick={ () => runWorkflow( monitor.workflowSlug, monitor.title ) }
								>
									<span>{ monitor.title }</span>
									<strong>{ monitor.metric }</strong>
									<small>{ monitor.cadence }</small>
								</button>
							) ) }
						</div>
					</section>

					<section className="hey-woo-today-panel">
						<div className="hey-woo-section-heading">
							<div>
								<h2>{ __( 'Library', 'hey-woo' ) }</h2>
								<p>{ __( 'Recent reports and chats.', 'hey-woo' ) }</p>
							</div>
							<Button type="button" variant="tertiary" onClick={ goToLibrary }>
								{ __( 'Open', 'hey-woo' ) }
							</Button>
						</div>
						<div className="hey-woo-today-library-list">
							{ recentItems.length === 0 && (
								<p>{ __( 'No saved conversations yet.', 'hey-woo' ) }</p>
							) }
							{ recentItems.map( ( conversation ) => (
								<button
									key={ conversation.id }
									type="button"
									className="hey-woo-today-library-item"
									onClick={ () => {
										void navigate( {
											to: '/chat',
											search: {
												conversationId: conversation.id,
											},
										} );
									} }
								>
									<span>{ conversation.title }</span>
									<small>{ conversationPreview( conversation ) }</small>
								</button>
							) ) }
						</div>
					</section>
				</aside>
			</div>
		</div>
	);
}
