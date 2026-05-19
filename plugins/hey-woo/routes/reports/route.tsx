/**
 * Route-level behaviour for the reports screen.
 */
export const route = {
	inspector: ( { search }: { search: { workflow?: string } } ) =>
		typeof search.workflow === 'string' && search.workflow.length > 0,
};
