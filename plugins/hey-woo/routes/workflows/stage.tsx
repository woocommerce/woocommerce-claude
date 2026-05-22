/**
 * Hey Woo Workflows — catalogue.
 *
 * A merchant-facing catalogue of the slash-command skills. Clicking "Run"
 * navigates to /chat with the matching `/<workflow-slug>` prompt prefilled;
 * the chat workspace turns that into a workflow run on first send. No
 * per-workflow setup form lives here — period, cadence, and other knobs
 * (when needed) are configured inside the chat.
 */
import '../ai-insights/style.scss';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
} from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { Icon } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';
import moduleData from '../ai-insights/data';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';
import { getCategoryColorSlot, getReportMetadata } from './workflow-data';
import type { CategoryColorSlot, ReportMetadata } from './workflow-data';

interface WorkflowCardData extends WorkflowAction {
	id: string;
	metadata: ReportMetadata;
	categoryColor: CategoryColorSlot;
	lastRun: number | null;
}

/**
 * Most-recent completed run timestamp for the given workflow slug, derived from
 * the conversations payload the SPA boots with. Returns null when the merchant
 * has not run this workflow yet.
 */
function findLastRun( workflowSlug: string ): number | null {
	const conversations = moduleData.conversations ?? [];
	let latest = 0;
	for ( const conv of conversations ) {
		if ( conv.workflowRun?.slug !== workflowSlug ) {
			continue;
		}
		if ( conv.workflowRun.status !== 'complete' ) {
			continue;
		}
		const at = conv.workflowRun.completedAt ?? conv.updatedAt;
		if ( at > latest ) {
			latest = at;
		}
	}
	return latest > 0 ? latest : null;
}

function formatLastRun( timestamp: number ): string {
	return new Date( timestamp ).toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
}

export function stage() {
	const navigate = useNavigate();

	const workflows = useMemo< WorkflowCardData[] >(
		() => WORKFLOWS.map( ( workflow ) => {
			const metadata = getReportMetadata( workflow );
			return {
				...workflow,
				id: workflow.slug,
				metadata,
				categoryColor: getCategoryColorSlot( metadata.category ),
				lastRun: findLastRun( workflow.slug ),
			};
		} ),
		[]
	);

	const runWorkflow = ( workflow: WorkflowCardData ) => {
		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: `/${ workflow.slug }`,
				workflowDisplay: workflow.label,
			},
		} );
	};

	return (
		<div className="hey-woo-page hey-woo-page--workflows">
			<header className="hey-woo-workflows-header">
				<div>
					<h1 className="hey-woo-workflows-header__title">{ __( 'Workflows', 'hey-woo' ) }</h1>
					<p className="hey-woo-workflows-header__count">
						{ sprintf(
							/* translators: %d: number of available workflows */
							_n( '%d workflow', '%d workflows', workflows.length, 'hey-woo' ),
							workflows.length
						) }
					</p>
				</div>
			</header>

			<div className="hey-woo-workflows-grid">
				{ workflows.map( ( workflow ) => (
					<Card key={ workflow.id } className="hey-woo-workflow-card" size="small">
						<CardHeader className="hey-woo-workflow-card__header">
							<div className="hey-woo-workflow-card__media" aria-hidden="true">
								<Icon icon={ workflow.icon } size={ 24 } />
							</div>
							<div className="hey-woo-workflow-card__heading">
								<h2 className="hey-woo-workflow-card__title">{ workflow.label }</h2>
								<span
									className={ `hey-woo-workflow-card__badge hey-woo-workflow-card__badge--${ workflow.categoryColor }` }
								>
									{ workflow.metadata.category }
								</span>
							</div>
							<Button
								variant="secondary"
								className="hey-woo-workflow-card__run"
								onClick={ () => runWorkflow( workflow ) }
							>
								{ __( 'Run', 'hey-woo' ) }
							</Button>
						</CardHeader>
						<CardBody className="hey-woo-workflow-card__body">
							<p className="hey-woo-workflow-card__description">
								{ workflow.summary ?? workflow.description }
							</p>
							{ workflow.lastRun !== null && (
								<span className="hey-woo-workflow-card__badge hey-woo-workflow-card__badge--neutral hey-woo-workflow-card__last-run">
									{ sprintf(
										/* translators: %s: short-format date string. */
										__( 'Last run: %s', 'hey-woo' ),
										formatLastRun( workflow.lastRun )
									) }
								</span>
							) }
						</CardBody>
					</Card>
				) ) }
			</div>
		</div>
	);
}
