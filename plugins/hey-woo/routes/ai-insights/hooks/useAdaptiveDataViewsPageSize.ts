import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import type { View } from '@wordpress/dataviews/wp';

interface AdaptiveDataViewsPageSizeOptions {
	itemCount: number;
	maxPerPage?: number;
	minPerPage?: number;
	pageSizeOptions?: number[];
	setView: ( update: View | ( ( current: View ) => View ) ) => void;
	view: View;
}

const FALLBACK_ITEM_HEIGHTS: Record< string, number > = {
	grid: 236,
	list: 88,
	table: 54,
};
const DEFAULT_PAGE_SIZE_OPTIONS = [ 10, 20, 50 ];

const ITEM_SELECTORS: Record< string, string > = {
	grid: '.dataviews-view-grid__card:not(.dataviews-view-grid__placeholder)',
	list: '.dataviews-view-list__item-wrapper',
	table: 'tbody .dataviews-view-table__row',
};

function uniqueSortedPageSizes( sizes: number[] ) {
	return Array.from( new Set( sizes.filter( ( size ) => size > 0 ) ) ).sort( ( a, b ) => a - b );
}

function getAverageItemHeight( wrapper: HTMLElement, viewType: string ) {
	const selector = ITEM_SELECTORS[ viewType ] ?? ITEM_SELECTORS.table;
	const items = Array.from( wrapper.querySelectorAll< HTMLElement >( selector ) )
		.filter( ( item ) => item.offsetParent !== null )
		.slice( 0, 6 );

	if ( items.length === 0 ) {
		return FALLBACK_ITEM_HEIGHTS[ viewType ] ?? FALLBACK_ITEM_HEIGHTS.table;
	}

	const totalHeight = items.reduce( ( total, item ) => total + item.getBoundingClientRect().height, 0 );
	return Math.max( 1, totalHeight / items.length );
}

function getGridColumnCount( wrapper: HTMLElement ) {
	const cards = Array.from(
		wrapper.querySelectorAll< HTMLElement >( ITEM_SELECTORS.grid )
	).filter( ( card ) => card.offsetParent !== null );

	if ( cards.length < 2 ) {
		return 1;
	}

	const firstTop = Math.round( cards[ 0 ].getBoundingClientRect().top );
	const firstRowCards = cards.filter(
		( card ) => Math.abs( Math.round( card.getBoundingClientRect().top ) - firstTop ) <= 2
	);

	return Math.max( 1, firstRowCards.length );
}

function getAdaptivePageSize(
	root: HTMLElement,
	viewType: string,
	itemCount: number,
	minPerPage: number,
	maxPerPage: number
) {
	const wrapper = root.querySelector< HTMLElement >( '.dataviews-wrapper' );

	if ( ! wrapper ) {
		return minPerPage;
	}

	const wrapperHeight = wrapper.clientHeight;

	if ( wrapperHeight <= 0 ) {
		return minPerPage;
	}

	const viewActionsHeight = wrapper.querySelector< HTMLElement >( '.dataviews__view-actions' )
		?.getBoundingClientRect().height ?? 0;
	const filtersHeight = wrapper.querySelector< HTMLElement >( '.dataviews-filters__container' )
		?.getBoundingClientRect().height ?? 0;
	const layoutHeaderHeight = viewType === 'table'
		? wrapper.querySelector< HTMLElement >( '.dataviews-view-table thead' )
			?.getBoundingClientRect().height ?? 0
		: 0;
	const itemHeight = getAverageItemHeight( wrapper, viewType );
	const columnCount = viewType === 'grid' ? getGridColumnCount( wrapper ) : 1;
	const baseChromeHeight = viewActionsHeight + filtersHeight + layoutHeaderHeight + 24;
	const noFooterRows = Math.max( 1, Math.floor( ( wrapperHeight - baseChromeHeight ) / itemHeight ) );
	const noFooterCapacity = Math.min( maxPerPage, noFooterRows * columnCount );

	if ( itemCount > 0 && itemCount <= noFooterCapacity ) {
		return itemCount;
	}

	const footerHeight = wrapper.querySelector< HTMLElement >( '.dataviews-footer' )
		?.getBoundingClientRect().height ?? 56;
	const paginatedRows = Math.max(
		1,
		Math.floor( ( wrapperHeight - baseChromeHeight - footerHeight ) / itemHeight )
	);
	const nextPerPage = Math.max( minPerPage, paginatedRows * columnCount );

	if ( itemCount > 0 ) {
		return Math.min( itemCount, maxPerPage, nextPerPage );
	}

	return Math.min( maxPerPage, nextPerPage );
}

export function useAdaptiveDataViewsPageSize( {
	itemCount,
	maxPerPage = 50,
	minPerPage = 6,
	pageSizeOptions = DEFAULT_PAGE_SIZE_OPTIONS,
	setView,
	view,
}: AdaptiveDataViewsPageSizeOptions ) {
	const rootRef = useRef< HTMLDivElement >( null );
	const [ adaptivePageSize, setAdaptivePageSize ] = useState( view.perPage ?? minPerPage );

	useEffect( () => {
		const root = rootRef.current;

		if ( ! root ) {
			return;
		}

		let animationFrame = 0;
		const recalculate = () => {
			window.cancelAnimationFrame( animationFrame );
			animationFrame = window.requestAnimationFrame( () => {
				const nextPageSize = getAdaptivePageSize(
					root,
					view.type,
					itemCount,
					minPerPage,
					maxPerPage
				);

				setAdaptivePageSize( ( currentPageSize ) => (
					currentPageSize === nextPageSize ? currentPageSize : nextPageSize
				) );
			} );
		};

		recalculate();

		const resizeObserver = new ResizeObserver( recalculate );
		resizeObserver.observe( root );

		const wrapper = root.querySelector< HTMLElement >( '.dataviews-wrapper' );
		if ( wrapper ) {
			resizeObserver.observe( wrapper );
		}

		window.addEventListener( 'resize', recalculate );

		return () => {
			window.cancelAnimationFrame( animationFrame );
			window.removeEventListener( 'resize', recalculate );
			resizeObserver.disconnect();
		};
	}, [ itemCount, maxPerPage, minPerPage, view.type, view.perPage ] );

	useEffect( () => {
		setView( ( currentView ) => {
			if ( currentView.perPage === adaptivePageSize ) {
				return currentView;
			}

			const previousPerPage = currentView.perPage ?? adaptivePageSize;
			const currentPage = currentView.page ?? 1;
			const firstVisibleItem = ( currentPage - 1 ) * previousPerPage + 1;
			const nextPage = Math.max( 1, Math.ceil( firstVisibleItem / adaptivePageSize ) );

			return {
				...currentView,
				page: nextPage,
				perPage: adaptivePageSize,
			};
		} );
	}, [ adaptivePageSize, setView ] );

	const perPageSizes = useMemo(
		() => uniqueSortedPageSizes( [ adaptivePageSize, ...pageSizeOptions ] ),
		[ adaptivePageSize, pageSizeOptions ]
	);

	return {
		perPageSizes,
		rootRef,
	};
}
