/**
 * Hey Woo "This Week" home surface — proactive signal feed.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';
import { SignalCard } from './components/SignalCard';
import { fetchSignals, runSignals, type Signal } from './signal-data';

type LoadState = 'loading' | 'idle' | 'running';

export function stage() {
	const [ signals, setSignals ] = useState< Signal[] >( [] );
	const [ loadState, setLoadState ] = useState< LoadState >( 'loading' );
	const [ lastRunLabel, setLastRunLabel ] = useState< string >( '' );
	const [ nextRefreshLabel, setNextRefreshLabel ] = useState< string >( '' );
	const [ monitoringEnabled, setMonitoringEnabled ] = useState< boolean >( true );
	const [ runError, setRunError ] = useState< string >( '' );
	const [ loadFailed, setLoadFailed ] = useState< boolean >( false );

	const loadSignals = useCallback( async () => {
		try {
			const next = await fetchSignals();
			setSignals( next.signals );
			setMonitoringEnabled( next.monitoringEnabled );
			setNextRefreshLabel( next.nextRefreshAt ? formatScheduledTime( next.nextRefreshAt ) : '' );
			setLoadFailed( false );
			if ( next.signals.length > 0 ) {
				const newest = Math.max( ...next.signals.map( ( signal ) => signal.last_detected_at ) );
				setLastRunLabel( formatLastRun( newest ) );
			}
		} catch ( error ) {
			setLoadFailed( true );
			setRunError( error instanceof Error ? error.message : __( 'Could not load signals.', 'hey-woo' ) );
		} finally {
			setLoadState( 'idle' );
		}
	}, [] );

	useEffect( () => {
		void loadSignals();
	}, [ loadSignals ] );

	const handleRunNow = async () => {
		setLoadState( 'running' );
		setRunError( '' );
		try {
			const result = await runSignals();
			setSignals( result.signals );
			setLoadFailed( false );
			setLastRunLabel( formatLastRun( Math.floor( Date.now() / 1000 ) ) );
			// Refresh the schedule readout — the next-scheduled time may shift after a manual run completes.
			await loadSignals();
		} catch ( error ) {
			setRunError( error instanceof Error ? error.message : __( 'Something went wrong.', 'hey-woo' ) );
		} finally {
			setLoadState( 'idle' );
		}
	};

	const isRunning = loadState === 'running';
	const isLoading = loadState === 'loading';
	const storeName = moduleData.storeName || __( 'your store', 'hey-woo' );

	return (
		<div className="hey-woo-page hey-woo-page--this-week">
			<header className="hey-woo-this-week-header">
				<div className="hey-woo-this-week-header__heading">
					<span className="hey-woo-this-week-header__eyebrow">
						{ __( 'Hey Woo', 'hey-woo' ) }
					</span>
					<h1 className="hey-woo-this-week-header__title">
						{ __( 'This week', 'hey-woo' ) }
					</h1>
					<p className="hey-woo-this-week-header__subtitle">
						{ monitoringEnabled
							? sprintf(
									/* translators: %s: store name */
									__( 'Material changes Hey Woo has spotted in %s, refreshed daily in your store timezone.', 'hey-woo' ),
									storeName
							  )
							: sprintf(
									/* translators: %s: store name */
									__( 'This Week monitoring is turned off. Hey Woo will only refresh signals for %s when you press Refresh now.', 'hey-woo' ),
									storeName
							  ) }
					</p>
				</div>
				<div className="hey-woo-this-week-header__actions">
					{ lastRunLabel && (
						<span className="hey-woo-this-week-header__last-run" aria-live="polite">
							{ sprintf(
								/* translators: %s: human-friendly last-run timestamp */
								__( 'Last refreshed %s', 'hey-woo' ),
								lastRunLabel
							) }
						</span>
					) }
					{ monitoringEnabled && nextRefreshLabel && (
						<span className="hey-woo-this-week-header__next-run">
							{ sprintf(
								/* translators: %s: human-friendly next-scheduled-refresh timestamp */
								__( 'Next refresh %s', 'hey-woo' ),
								nextRefreshLabel
							) }
						</span>
					) }
					<Button
						type="button"
						variant="secondary"
						__next40pxDefaultSize
						isBusy={ isRunning }
						disabled={ isRunning }
						onClick={ handleRunNow }
					>
						{ isRunning ? __( 'Refreshing…', 'hey-woo' ) : __( 'Refresh now', 'hey-woo' ) }
					</Button>
				</div>
			</header>

			{ runError && (
				<div className="hey-woo-this-week-error" role="alert">
					{ runError }
				</div>
			) }

			{ isLoading ? (
				<div className="hey-woo-this-week-loading" role="status">
					<Spinner />
					<p>{ __( 'Loading signals…', 'hey-woo' ) }</p>
				</div>
			) : loadFailed ? (
				<LoadFailedState isRunning={ isRunning } onRun={ handleRunNow } />
			) : signals.length === 0 ? (
				<EmptyState
					isRunning={ isRunning }
					monitoringEnabled={ monitoringEnabled }
					nextRefreshLabel={ nextRefreshLabel }
					onRun={ handleRunNow }
				/>
			) : (
				<>
					<p className="hey-woo-this-week-feed__lede" aria-live="polite">
						{ sprintf(
							/* translators: %d: number of signals */
							_n(
								'%d thing needs your attention this week.',
								'%d things need your attention this week.',
								signals.length,
								'hey-woo'
							),
							signals.length
						) }
					</p>
					<div className="hey-woo-this-week-feed">
						{ signals.map( ( signal ) => (
							<SignalCard
								key={ signal.slug }
								signal={ signal }
								onChanged={ setSignals }
							/>
						) ) }
					</div>
				</>
			) }
		</div>
	);
}

interface EmptyStateProps {
	isRunning: boolean;
	monitoringEnabled: boolean;
	nextRefreshLabel: string;
	onRun: () => void;
}

function EmptyState( { isRunning, monitoringEnabled, nextRefreshLabel, onRun }: EmptyStateProps ) {
	const copy = monitoringEnabled
		? nextRefreshLabel
			? sprintf(
					/* translators: %s: scheduled refresh time */
					__( 'Hey Woo did not find any signals worth surfacing right now. The next automatic check is %s — refresh anytime if you want a fresh look.', 'hey-woo' ),
					nextRefreshLabel
			  )
			: __( 'Hey Woo did not find any signals worth surfacing right now. The next automatic check will run within a day.', 'hey-woo' )
		: __( 'Hey Woo did not find any signals worth surfacing right now. Monitoring is off, so use Refresh now to check again.', 'hey-woo' );

	return (
		<div className="hey-woo-this-week-empty" role="status">
			<h2>{ __( 'Nothing material this week', 'hey-woo' ) }</h2>
			<p>{ copy }</p>
			<Button
				type="button"
				variant="secondary"
				__next40pxDefaultSize
				isBusy={ isRunning }
				disabled={ isRunning }
				onClick={ onRun }
			>
				{ isRunning ? __( 'Refreshing…', 'hey-woo' ) : __( 'Refresh now', 'hey-woo' ) }
			</Button>
		</div>
	);
}

function LoadFailedState( { isRunning, onRun }: { isRunning: boolean; onRun: () => void } ) {
	return (
		<div className="hey-woo-this-week-empty" role="alert">
			<h2>{ __( 'Could not load signals', 'hey-woo' ) }</h2>
			<p>
				{ __( 'Hey Woo could not reach the signal feed. Check your connection and try again.', 'hey-woo' ) }
			</p>
			<Button
				type="button"
				variant="secondary"
				__next40pxDefaultSize
				isBusy={ isRunning }
				disabled={ isRunning }
				onClick={ onRun }
			>
				{ isRunning ? __( 'Retrying…', 'hey-woo' ) : __( 'Retry', 'hey-woo' ) }
			</Button>
		</div>
	);
}

function formatLastRun( timestamp: number ): string {
	return new Intl.DateTimeFormat( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( timestamp * 1000 ) );
}

function formatScheduledTime( timestamp: number ): string {
	const date = new Date( timestamp * 1000 );
	const now = new Date();

	const sameDay = date.getFullYear() === now.getFullYear()
		&& date.getMonth() === now.getMonth()
		&& date.getDate() === now.getDate();

	const tomorrow = new Date( now );
	tomorrow.setDate( now.getDate() + 1 );
	const isTomorrow = date.getFullYear() === tomorrow.getFullYear()
		&& date.getMonth() === tomorrow.getMonth()
		&& date.getDate() === tomorrow.getDate();

	const time = new Intl.DateTimeFormat( undefined, { timeStyle: 'short' } ).format( date );

	if ( sameDay ) {
		return sprintf(
			/* translators: %s: time of day */
			__( 'today at %s', 'hey-woo' ),
			time
		);
	}

	if ( isTomorrow ) {
		return sprintf(
			/* translators: %s: time of day */
			__( 'tomorrow at %s', 'hey-woo' ),
			time
		);
	}

	return new Intl.DateTimeFormat( undefined, {
		weekday: 'long',
		hour: 'numeric',
		minute: '2-digit',
	} ).format( date );
}
