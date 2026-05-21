/**
 * Render recommended report actions as addable board cards.
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addActionCard } from '../../actions/action-store';
import type { ActionPriority } from '../../actions/action-store';

export interface RecommendedReportAction {
	title: string;
	priority: ActionPriority;
	summary?: string;
	keyMetric?: string;
	impact?: string;
	evidence: string;
	nextSteps: string[];
	expectedOutcome?: string;
}

interface ReportActionCardsProps {
	actions: RecommendedReportAction[];
	reportLabel?: string;
}

const PRIORITY_LABELS: Record< ActionPriority, string > = {
	high: __( 'High', 'hey-woo' ),
	medium: __( 'Medium', 'hey-woo' ),
	low: __( 'Low', 'hey-woo' ),
};

function textOrUndefined( value: string | undefined ): string | undefined {
	return value?.trim() || undefined;
}

function cardSummary( action: RecommendedReportAction ): string {
	return textOrUndefined( action.summary ) || textOrUndefined( action.evidence ) || '';
}

export function ReportActionCards( {
	actions,
	reportLabel = __( 'Generated report', 'hey-woo' ),
}: ReportActionCardsProps ) {
	const [ addedTitles, setAddedTitles ] = useState< string[] >( [] );

	if ( ! actions.length ) {
		return null;
	}

	const addRecommendedAction = ( action: RecommendedReportAction ) => {
		addActionCard( {
			title: action.title,
			description: cardSummary( action ),
			summary: textOrUndefined( action.summary ),
			keyMetric: textOrUndefined( action.keyMetric ),
			impact: textOrUndefined( action.impact ),
			evidence: textOrUndefined( action.evidence ),
			nextSteps: action.nextSteps,
			expectedOutcome: textOrUndefined( action.expectedOutcome ),
			status: 'todo',
			priority: action.priority,
			source: __( 'Generated report', 'hey-woo' ),
			reportLabel,
		} );

		setAddedTitles( ( current ) => [ ...current, action.title ] );
	};
	const remainingActions = actions.filter( ( action ) => ! addedTitles.includes( action.title ) );
	const addAllRecommendedActions = () => {
		remainingActions.forEach( addRecommendedAction );
	};

	return (
		<div className="hey-woo-report-actions" aria-label={ __( 'Recommended actions', 'hey-woo' ) }>
			<div className="hey-woo-report-actions__header">
				<div>
					<h3>{ __( 'Recommended actions', 'hey-woo' ) }</h3>
					<p>
						{ __( 'Evidence-backed follow-ups you can add to the action board.', 'hey-woo' ) }
					</p>
				</div>
				<Button
					type="button"
					variant="secondary"
					__next40pxDefaultSize
					disabled={ remainingActions.length === 0 }
					onClick={ addAllRecommendedActions }
				>
					{ remainingActions.length === 0
						? __( 'All added', 'hey-woo' )
						: __( 'Add all', 'hey-woo' ) }
				</Button>
			</div>
			<div className="hey-woo-report-actions__list">
				{ actions.map( ( action ) => {
					const isAdded = addedTitles.includes( action.title );
					const summary = cardSummary( action );

					return (
						<Card
							key={ action.title }
							className={ `hey-woo-report-action hey-woo-report-action--${ action.priority }` }
							size="small"
						>
							<CardHeader className="hey-woo-report-action__header">
								<div>
									<div className="hey-woo-report-action__meta">
										<span className="hey-woo-report-action__priority">
											{ PRIORITY_LABELS[ action.priority ] }
										</span>
										{ action.keyMetric && (
											<span className="hey-woo-report-action__metric">
												{ action.keyMetric }
											</span>
										) }
									</div>
									<h4>{ action.title }</h4>
								</div>
								<Button
									type="button"
									variant={ isAdded ? 'tertiary' : 'secondary' }
									size="compact"
									__next40pxDefaultSize
									disabled={ isAdded }
									onClick={ () => addRecommendedAction( action ) }
								>
									{ isAdded
										? __( 'Added', 'hey-woo' )
										: __( 'Add to Actions', 'hey-woo' ) }
								</Button>
							</CardHeader>
							<CardBody>
								{ summary && (
									<p className="hey-woo-report-action__summary">
										{ summary }
									</p>
								) }
								{ action.impact && (
									<p className="hey-woo-report-action__impact">
										{ action.impact }
									</p>
								) }
								{ action.evidence && (
									<p className="hey-woo-report-action__evidence">
										{ action.evidence }
									</p>
								) }
								{ action.nextSteps.length > 0 && (
									<ul>
										{ action.nextSteps.map( ( step ) => (
											<li key={ step }>{ step }</li>
										) ) }
									</ul>
								) }
							</CardBody>
						</Card>
					);
				} ) }
			</div>
		</div>
	);
}
