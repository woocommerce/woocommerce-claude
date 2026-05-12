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
				setState( {
					board: null,
					status: 'error',
					errorMessage: json.message,
				} );
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

	useEffect( () => {
		refresh( false );
	}, [ refresh ] );

	return { ...state, refresh };
}
