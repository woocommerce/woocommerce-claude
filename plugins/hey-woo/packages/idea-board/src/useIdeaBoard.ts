import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { IdeaBoardData, IdeaBoardResponse, IdeaBoardStatus } from './types';

async function apiFetch( url: string, options: RequestInit ): Promise< Extract< IdeaBoardResponse, { status: 'ok' } > > {
	const response = await fetch( url, options );
	if ( ! response.ok ) {
		throw new Error( 'request_failed' );
	}
	const json: IdeaBoardResponse = await response.json();
	if ( json.status === 'error' ) {
		throw new Error( json.message );
	}
	return json as Extract< IdeaBoardResponse, { status: 'ok' } >;
}

interface UseIdeaBoardArgs {
	restBase: string;
	nonce: string;
	days: number;
}

interface IdeaBoardState {
	board: IdeaBoardData | null;
	status: IdeaBoardStatus;
	errorMessage: string;
}

type BoardUpdater = IdeaBoardData | ( ( board: IdeaBoardData ) => IdeaBoardData );

export function useIdeaBoard( { restBase, nonce, days }: UseIdeaBoardArgs ) {
	const [ state, setState ] = useState< IdeaBoardState >( {
		board: null,
		status: 'loading',
		errorMessage: '',
	} );

	const refresh = useCallback( async ( force = true ) => {
		if ( ! restBase ) {
			setState( {
				board: null,
				status: 'error',
				errorMessage: __( 'The idea board endpoint is not available.', 'woocommerce-claude' ),
			} );
			return;
		}

		setState( ( previous ) => ( {
			...previous,
			status: 'loading',
			errorMessage: '',
		} ) );

		try {
			const params = new URLSearchParams( {
				days: String( days ),
			} );
			if ( force ) {
				params.set( 'refresh', 'true' );
			}

			const json = await apiFetch( `${ restBase }/idea-board?${ params.toString() }`, {
				method: 'GET',
				headers: {
					'X-WP-Nonce': nonce,
				},
			} );

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
		} catch ( error ) {
			const message = error instanceof Error && error.message !== 'request_failed'
				? error.message
				: __( 'The idea board could not be loaded. Please try again.', 'woocommerce-claude' );
			setState( {
				board: null,
				status: 'error',
				errorMessage: message,
			} );
		}
	}, [ days, nonce, restBase ] );

	const updateBoard = useCallback( ( updater: BoardUpdater ) => {
		setState( ( previous ) => {
			if ( ! previous.board ) {
				return previous;
			}

			const board = typeof updater === 'function' ? updater( previous.board ) : updater;
			return {
				...previous,
				board,
				status: previous.status === 'error' ? 'idle' : previous.status,
				errorMessage: previous.status === 'error' ? '' : previous.errorMessage,
			};
		} );
	}, [] );

	const reanalyse = useCallback( async ( board: IdeaBoardData ) => {
		if ( ! restBase ) {
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: __( 'The idea board endpoint is not available.', 'woocommerce-claude' ),
			} ) );
			return;
		}

		setState( ( previous ) => ( {
			...previous,
			status: 'reanalysing',
			errorMessage: '',
		} ) );

		try {
			const json = await apiFetch( `${ restBase }/idea-board/reanalyse`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { board } ),
			} );

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
		} catch ( error ) {
			const message = error instanceof Error && error.message !== 'request_failed'
				? error.message
				: __( 'The idea board could not be re-analysed. Please try again.', 'woocommerce-claude' );
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: message,
			} ) );
		}
	}, [ nonce, restBase ] );

	const brainstorm = useCallback( async ( board: IdeaBoardData, rootInsightIds: string[] ) => {
		if ( ! restBase ) {
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: __( 'The idea board endpoint is not available.', 'woocommerce-claude' ),
			} ) );
			return null;
		}

		setState( ( previous ) => ( {
			...previous,
			status: 'brainstorming',
			errorMessage: '',
		} ) );

		try {
			const json = await apiFetch( `${ restBase }/idea-board/brainstorm`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { board, rootInsightIds } ),
			} );

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
			return json.board;
		} catch ( error ) {
			const message = error instanceof Error && error.message !== 'request_failed'
				? error.message
				: __( 'The brainstorm session could not be started. Please try again.', 'woocommerce-claude' );
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: message,
			} ) );
			return null;
		}
	}, [ nonce, restBase ] );

	const answerQuestion = useCallback( async ( board: IdeaBoardData, cardId: string ) => {
		if ( ! restBase ) {
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: __( 'The idea board endpoint is not available.', 'woocommerce-claude' ),
			} ) );
			return null;
		}

		setState( ( previous ) => ( {
			...previous,
			status: 'answering',
			errorMessage: '',
		} ) );

		try {
			const json = await apiFetch( `${ restBase }/idea-board/answer-question`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { board, cardId } ),
			} );

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
			return json.board;
		} catch ( error ) {
			const message = error instanceof Error && error.message !== 'request_failed'
				? error.message
				: __( 'The question could not be answered with AI. Please try again.', 'woocommerce-claude' );
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: message,
			} ) );
			return null;
		}
	}, [ nonce, restBase ] );

	const saveBoard = useCallback( async ( board: IdeaBoardData, rollbackBoard?: IdeaBoardData ) => {
		if ( ! restBase ) {
			return false;
		}

		setState( ( previous ) => ( {
			...previous,
			status: 'saving',
			errorMessage: '',
		} ) );

		try {
			const json = await apiFetch( `${ restBase }/idea-board/save`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { board } ),
			} );
			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
			return true;
		} catch ( error ) {
			setState( ( previous ) => ( {
				...previous,
				board: rollbackBoard || previous.board,
				status: 'error',
				errorMessage: __( 'The idea board could not be saved. Please try again.', 'woocommerce-claude' ),
			} ) );
			return false;
		}
	}, [ nonce, restBase ] );

	useEffect( () => {
		refresh( false );
	}, [ refresh ] );

	return { ...state, refresh, reanalyse, brainstorm, answerQuestion, saveBoard, updateBoard };
}
