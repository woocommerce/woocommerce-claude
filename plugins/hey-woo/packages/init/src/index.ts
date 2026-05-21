import { store as bootStore } from '@wordpress/boot';
import { dispatch, select } from '@wordpress/data';
import { archive, chartBar, check, commentContent, home } from '@wordpress/icons';

// updateMenuItem on an unregistered id creates a ghost entry with only the
// icon (no label, no route). PHP gates `hey-woo-today` and `hey-woo-actions`
// behind feature flags, so we apply icons only to items the server actually
// registered.
const ICON_BY_ID: Record< string, unknown > = {
	'hey-woo-today': home,
	'hey-woo-chat': commentContent,
	'hey-woo-history': archive,
	'hey-woo-workflows': chartBar,
	'hey-woo-actions': check,
};

export async function init(): Promise< void > {
	const registeredIds = new Set(
		select( bootStore ).getMenuItems().map( ( item ) => item.id )
	);

	for ( const [ id, icon ] of Object.entries( ICON_BY_ID ) ) {
		if ( registeredIds.has( id ) ) {
			dispatch( bootStore ).updateMenuItem( id, { icon } );
		}
	}
}
