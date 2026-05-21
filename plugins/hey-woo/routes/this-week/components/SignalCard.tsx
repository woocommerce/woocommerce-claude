/**
 * Single signal card in the This Week feed.
 */
import { Button, Card, CardBody, CardFooter, CardHeader } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';
import {
	dismissSignal,
	severityLabel,
	snoozeSignal,
	workflowChatPrompt,
	type Signal,
} from '../signal-data';

interface SignalCardProps {
	signal: Signal;
	onChanged: ( signals: Signal[] ) => void;
}

function detectedLabel( signal: Signal ): string {
	const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - signal.first_detected_at );

	if ( seconds < 60 ) {
		return __( 'Just now', 'hey-woo' );
	}

	if ( seconds < 3600 ) {
		const minutes = Math.floor( seconds / 60 );
		return sprintf(
			/* translators: %d: number of minutes */
			__( '%d min ago', 'hey-woo' ),
			minutes
		);
	}

	if ( seconds < 86400 ) {
		const hours = Math.floor( seconds / 3600 );
		return sprintf(
			/* translators: %d: number of hours */
			__( '%dh ago', 'hey-woo' ),
			hours
		);
	}

	const days = Math.floor( seconds / 86400 );
	return sprintf(
		/* translators: %d: number of days */
		__( '%dd ago', 'hey-woo' ),
		days
	);
}

export function SignalCard( { signal, onChanged }: SignalCardProps ) {
	const navigate = useNavigate();
	const [ busyAction, setBusyAction ] = useState< 'dismiss' | 'snooze' | null >( null );

	const handleDismiss = async () => {
		setBusyAction( 'dismiss' );
		try {
			const next = await dismissSignal( signal.slug );
			onChanged( next );
		} finally {
			setBusyAction( null );
		}
	};

	const handleSnooze = async () => {
		setBusyAction( 'snooze' );
		try {
			const next = await snoozeSignal( signal.slug );
			onChanged( next );
		} finally {
			setBusyAction( null );
		}
	};

	const handleOpenWorkflow = () => {
		const prompt = workflowChatPrompt( signal );
		if ( ! prompt ) {
			return;
		}

		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: prompt,
				workflowDisplay: signal.action.title || signal.title,
			},
		} );
	};

	const actionTitle = signal.action.title.trim() || __( 'Open workflow', 'hey-woo' );

	return (
		<Card
			className={ `hey-woo-signal-card hey-woo-signal-card--${ signal.severity }` }
			size="small"
		>
			<CardHeader className="hey-woo-signal-card__header">
				<div className="hey-woo-signal-card__meta">
					<span
						className={ `hey-woo-signal-card__severity-dot hey-woo-signal-card__severity-dot--${ signal.severity }` }
						aria-hidden="true"
					/>
					<span className="hey-woo-signal-card__severity-label">
						{ severityLabel( signal.severity ) }
					</span>
					<span className="hey-woo-signal-card__detected" aria-label={ __( 'First detected', 'hey-woo' ) }>
						{ detectedLabel( signal ) }
					</span>
				</div>
				<h3 className="hey-woo-signal-card__title">{ signal.title }</h3>
			</CardHeader>

			<CardBody className="hey-woo-signal-card__body">
				{ signal.summary && (
					<p className="hey-woo-signal-card__summary">{ signal.summary }</p>
				) }
				{ ( signal.evidence.label || signal.evidence.value ) && (
					<div className="hey-woo-signal-card__evidence">
						{ signal.evidence.label && (
							<span className="hey-woo-signal-card__evidence-label">
								{ signal.evidence.label }
							</span>
						) }
						{ signal.evidence.value && (
							<strong className="hey-woo-signal-card__evidence-value">
								{ signal.evidence.value }
							</strong>
						) }
						{ signal.evidence.change && (
							<span className="hey-woo-signal-card__evidence-change">
								{ signal.evidence.change }
							</span>
						) }
					</div>
				) }
				{ signal.action.detail && (
					<p className="hey-woo-signal-card__action-detail">{ signal.action.detail }</p>
				) }
			</CardBody>

			<CardFooter className="hey-woo-signal-card__footer">
				<div className="hey-woo-signal-card__secondary">
					<Button
						type="button"
						variant="tertiary"
						size="compact"
						isBusy={ busyAction === 'snooze' }
						disabled={ busyAction !== null }
						onClick={ handleSnooze }
					>
						{ __( 'Snooze', 'hey-woo' ) }
					</Button>
					<Button
						type="button"
						variant="tertiary"
						size="compact"
						isBusy={ busyAction === 'dismiss' }
						disabled={ busyAction !== null }
						onClick={ handleDismiss }
					>
						{ __( 'Dismiss', 'hey-woo' ) }
					</Button>
				</div>
				<Button
					type="button"
					variant="primary"
					__next40pxDefaultSize
					disabled={ busyAction !== null }
					onClick={ handleOpenWorkflow }
				>
					{ actionTitle }
				</Button>
			</CardFooter>
		</Card>
	);
}
