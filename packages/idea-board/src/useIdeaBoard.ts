import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { IdeaBoardData, IdeaBoardResponse, IdeaBoardStatus } from './types';

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

			const response = await fetch( `${ restBase }/idea-board?${ params.toString() }`, {
				method: 'GET',
				headers: {
					'X-WP-Nonce': nonce,
				},
			} );

			if ( ! response.ok ) {
				throw new Error( 'request_failed' );
			}

			const json: IdeaBoardResponse = await response.json();
			if ( json.status === 'error' ) {
				setState( ( previous ) => ( {
					...previous,
					status: 'error',
					errorMessage: json.message,
				} ) );
				return;
			}

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
		} catch ( error ) {
			setState( {
				board: null,
				status: 'error',
				errorMessage: __( 'The idea board could not be loaded. Please try again.', 'woocommerce-claude' ),
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
			const response = await fetch( `${ restBase }/idea-board/reanalyse`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { board } ),
			} );

			if ( ! response.ok ) {
				throw new Error( 'request_failed' );
			}

			const json: IdeaBoardResponse = await response.json();
			if ( json.status === 'error' ) {
				setState( ( previous ) => ( {
					...previous,
					status: 'error',
					errorMessage: json.message,
				} ) );
				return;
			}

			setState( {
				board: json.board,
				status: 'idle',
				errorMessage: '',
			} );
		} catch ( error ) {
			setState( ( previous ) => ( {
				...previous,
				status: 'error',
				errorMessage: __( 'The idea board could not be re-analysed. Please try again.', 'woocommerce-claude' ),
			} ) );
		}
	}, [ nonce, restBase ] );

	useEffect( () => {
		refresh( false );
	}, [ refresh ] );

	return { ...state, refresh, reanalyse, updateBoard };
}
