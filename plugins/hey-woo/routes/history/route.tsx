/**
 * Route-level behaviour for the history screen.
 */
export const route = {
	inspector: ( { search }: { search: { conversation?: string } } ) =>
		typeof search.conversation === 'string' && search.conversation.length > 0,
};
