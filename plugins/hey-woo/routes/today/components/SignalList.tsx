/**
 * Signal list — single bordered container of rows for Today's home surface.
 *
 * Each row is title + brief summary + "View report" button. Snooze and
 * Dismiss are tucked under a hover ellipsis menu so the row stays clean
 * but the merchant keeps the full action set.
 */
import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, moreVertical } from '@wordpress/icons';
import { useNavigate } from '@wordpress/route';
import {
	dismissSignal,
	snoozeSignal,
	workflowChatPrompt,
	type Signal,
} from '../signal-data';

interface SignalListProps {
	signals: Signal[];
	onChanged: ( signals: Signal[] ) => void;
}

interface SignalRowProps {
	signal: Signal;
	onChanged: ( signals: Signal[] ) => void;
}

function SignalRow( { signal, onChanged }: SignalRowProps ) {
	const navigate = useNavigate();
	const [ busy, setBusy ] = useState< 'dismiss' | 'snooze' | null >( null );

	const openWorkflow = () => {
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

	const handleDismiss = async () => {
		setBusy( 'dismiss' );
		try {
			const next = await dismissSignal( signal.slug );
			onChanged( next );
		} finally {
			setBusy( null );
		}
	};

	const handleSnooze = async () => {
		setBusy( 'snooze' );
		try {
			const next = await snoozeSignal( signal.slug );
			onChanged( next );
		} finally {
			setBusy( null );
		}
	};

	const toneClass = signal.tone === 'positive'
		? 'hey-woo-signal-row hey-woo-signal-row--positive'
		: 'hey-woo-signal-row';

	return (
		<div className={ toneClass }>
			<div className="hey-woo-signal-row__body">
				<h3 className="hey-woo-signal-row__title">{ signal.title }</h3>
				{ signal.summary && (
					<p className="hey-woo-signal-row__summary">{ signal.summary }</p>
				) }
			</div>
			<div className="hey-woo-signal-row__actions">
				<Button
					type="button"
					variant="secondary"
					__next40pxDefaultSize
					disabled={ busy !== null }
					onClick={ openWorkflow }
				>
					{ __( 'View report', 'hey-woo' ) }
				</Button>
				<Dropdown
					popoverProps={ { placement: 'bottom-end' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							type="button"
							variant="tertiary"
							size="compact"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							aria-label={ __( 'More actions', 'hey-woo' ) }
							className="hey-woo-signal-row__more"
						>
							<Icon icon={ moreVertical } size={ 20 } />
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<MenuGroup>
							<MenuItem
								disabled={ busy !== null }
								onClick={ () => {
									onClose();
									void handleSnooze();
								} }
							>
								{ __( 'Snooze for 3 days', 'hey-woo' ) }
							</MenuItem>
							<MenuItem
								disabled={ busy !== null }
								isDestructive
								onClick={ () => {
									onClose();
									void handleDismiss();
								} }
							>
								{ __( 'Dismiss', 'hey-woo' ) }
							</MenuItem>
						</MenuGroup>
					) }
				/>
			</div>
		</div>
	);
}

export function SignalList( { signals, onChanged }: SignalListProps ) {
	if ( signals.length === 0 ) {
		return null;
	}

	return (
		<section className="hey-woo-signal-list" aria-label={ __( 'Signals that need attention', 'hey-woo' ) }>
			{ signals.map( ( signal ) => (
				<SignalRow key={ signal.slug } signal={ signal } onChanged={ onChanged } />
			) ) }
		</section>
	);
}
