/**
 * Hey Woo "Today" home surface.
 *
 * Centered hero + KPI tape + signal list + ask-anything input + workflow
 * shortcuts. Replaces the original card-feed layout with a denser
 * dashboard-style page the merchant can scan in seconds.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';
import { AskAnything } from './components/AskAnything';
import { KpiTape } from './components/KpiTape';
import { SignalList } from './components/SignalList';
import { WorkflowShortcuts } from './components/WorkflowShortcuts';
import {
	fetchSignals,
	RunnerBusyError,
	runSignals,
	type KpiCell,
	type KpiPeriod,
	type Signal,
} from './signal-data';

type LoadState = 'loading' | 'idle' | 'running';

export function stage() {
	const [ signals, setSignals ] = useState< Signal[] >( [] );
	const [ kpis, setKpis ] = useState< KpiCell[] >( [] );
	const [ kpiPeriod, setKpiPeriod ] = useState< KpiPeriod | null >( null );
	const [ loadState, setLoadState ] = useState< LoadState >( 'loading' );
	const [ nextRefreshLabel, setNextRefreshLabel ] = useState< string >( '' );
	const [ monitoringEnabled, setMonitoringEnabled ] = useState< boolean >( true );
	const [ runError, setRunError ] = useState< string >( '' );
	const [ loadFailed, setLoadFailed ] = useState< boolean >( false );

	const loadSignals = useCallback( async () => {
		try {
			const next = await fetchSignals();
			setSignals( next.signals );
			setKpis( next.kpis );
			setKpiPeriod( next.kpiPeriod );
			setMonitoringEnabled( next.monitoringEnabled );
			setNextRefreshLabel( next.nextRefreshAt ? formatScheduledTime( next.nextRefreshAt ) : '' );
			setLoadFailed( false );
		} catch ( error ) {
			setLoadFailed( true );
			setRunError( error instanceof Error ? error.message : __( 'Could not load Today.', 'hey-woo' ) );
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
			// Pick up the freshly computed KPIs + next-refresh chip after a successful run.
			await loadSignals();
		} catch ( error ) {
			const isBusy = error instanceof RunnerBusyError;
			setRunError(
				isBusy
					? __( 'Hey Woo is already refreshing in the background. We’ll show the new signals as soon as it finishes.', 'hey-woo' )
					: error instanceof Error
						? error.message
						: __( 'Something went wrong.', 'hey-woo' )
			);
			if ( isBusy ) {
				window.setTimeout( () => void loadSignals(), 5000 );
			}
		} finally {
			setLoadState( 'idle' );
		}
	};

	const isRunning = loadState === 'running';
	const isLoading = loadState === 'loading';
	const firstName = firstNameFromUser( moduleData.userName || '' );
	const storeName = moduleData.storeName || __( 'your store', 'hey-woo' );
	const greeting = firstName
		? sprintf(
				/* translators: 1: merchant first name, 2: store name. */
				__( 'Hi %1$s, here’s the latest from %2$s.', 'hey-woo' ),
				firstName,
				storeName
		  )
		: sprintf(
				/* translators: %s: store name. */
				__( 'Here’s the latest from %s.', 'hey-woo' ),
				storeName
		  );

	return (
		<div className="hey-woo-page hey-woo-page--today">
			<header className="hey-woo-today-topbar">
				<h1 className="hey-woo-today-topbar__title">{ __( 'Today', 'hey-woo' ) }</h1>
				<div className="hey-woo-today-topbar__actions">
					{ monitoringEnabled && nextRefreshLabel && (
						<span className="hey-woo-today-topbar__next-run">
							{ sprintf(
								/* translators: %s: human-friendly next-scheduled-refresh timestamp */
								__( 'Next refresh %s', 'hey-woo' ),
								nextRefreshLabel
							) }
						</span>
					) }
					<Button
						type="button"
						variant="primary"
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
				<div className="hey-woo-today-error" role="alert">
					{ runError }
				</div>
			) }

			{ isLoading ? (
				<div className="hey-woo-today-loading" role="status">
					<Spinner />
					<p>{ __( 'Loading…', 'hey-woo' ) }</p>
				</div>
			) : loadFailed ? (
				<LoadFailedState isRunning={ isRunning } onRun={ handleRunNow } />
			) : (
				<div className="hey-woo-today-body">
					<div className="hey-woo-today-hero">
						<HeySparkle />
						<h2 className="hey-woo-today-hero__greeting">{ greeting }</h2>
					</div>

					{ kpiPeriod && kpiPeriod.label && (
						<p className="hey-woo-today-period">{ kpiPeriod.label }</p>
					) }

					<KpiTape kpis={ kpis } />

					{ signals.length > 0 ? (
						<SignalList signals={ signals } onChanged={ setSignals } />
					) : (
						<div className="hey-woo-today-empty">
							<p>
								{ monitoringEnabled
									? __( 'Nothing material is happening at the store right now. Hey Woo will keep an eye out and surface anything worth your attention.', 'hey-woo' )
									: __( 'Monitoring is off. Turn it on under Settings, or use Refresh now to take a fresh look.', 'hey-woo' ) }
							</p>
						</div>
					) }

					<AskAnything />

					<WorkflowShortcuts />
				</div>
			) }
		</div>
	);
}

function HeySparkle() {
	return (
		<div className="hey-woo-today-hero__sparkle" aria-hidden="true">
			<svg
				width="36"
				height="36"
				viewBox="0 0 36 36"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
			>
				<path
					d="M18 2 L21 13 L32 16 L21 19 L18 30 L15 19 L4 16 L15 13 Z"
					fill="url(#hey-woo-today-sparkle)"
				/>
				<defs>
					<linearGradient
						id="hey-woo-today-sparkle"
						x1="4"
						y1="2"
						x2="32"
						y2="30"
						gradientUnits="userSpaceOnUse"
					>
						<stop stopColor="#7c3aed" />
						<stop offset="1" stopColor="#4338ca" />
					</linearGradient>
				</defs>
			</svg>
		</div>
	);
}

function LoadFailedState( { isRunning, onRun }: { isRunning: boolean; onRun: () => void } ) {
	return (
		<div className="hey-woo-today-empty" role="alert">
			<h2>{ __( 'Could not load Today', 'hey-woo' ) }</h2>
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

function firstNameFromUser( fullName: string ): string {
	const first = fullName.split( /\s+/ )[ 0 ];
	return typeof first === 'string' ? first.trim() : '';
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
