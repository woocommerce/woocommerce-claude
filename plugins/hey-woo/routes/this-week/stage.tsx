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
	const [ runError, setRunError ] = useState< string >( '' );

	const loadSignals = useCallback( async () => {
		try {
			const next = await fetchSignals();
			setSignals( next );
			if ( next.length > 0 ) {
				const newest = Math.max( ...next.map( ( signal ) => signal.last_detected_at ) );
				setLastRunLabel( formatLastRun( newest ) );
			}
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
			setLastRunLabel( formatLastRun( Math.floor( Date.now() / 1000 ) ) );
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
						{ sprintf(
							/* translators: %s: store name */
							__( 'Material changes Hey Woo has spotted in %s — refreshed on demand.', 'hey-woo' ),
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
			) : signals.length === 0 ? (
				<EmptyState isRunning={ isRunning } onRun={ handleRunNow } />
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

function EmptyState( { isRunning, onRun }: { isRunning: boolean; onRun: () => void } ) {
	return (
		<div className="hey-woo-this-week-empty" role="status">
			<h2>{ __( 'Nothing material this week', 'hey-woo' ) }</h2>
			<p>
				{ __( 'Hey Woo did not find any signals worth surfacing right now. Refresh to run a new check, or revisit later — the daily schedule will keep watch.', 'hey-woo' ) }
			</p>
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

function formatLastRun( timestamp: number ): string {
	return new Intl.DateTimeFormat( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( timestamp * 1000 ) );
}
