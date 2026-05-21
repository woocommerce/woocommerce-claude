/**
 * "Ask anything" input — a chat starter that hands off to /chat.
 *
 * Submitting prefills the chat workspace with the merchant's question and
 * the existing tool-loop handles the response. This is intentionally not
 * an inline chat — Today is a feed surface, not a chat surface.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, arrowRight } from '@wordpress/icons';
import { useNavigate } from '@wordpress/route';

export function AskAnything() {
	const navigate = useNavigate();
	const [ value, setValue ] = useState( '' );

	const submit = () => {
		const trimmed = value.trim();
		if ( '' === trimmed ) {
			return;
		}

		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: trimmed,
				workflowDisplay: trimmed,
			},
		} );
	};

	const handleKeyDown = ( event: React.KeyboardEvent< HTMLTextAreaElement > ) => {
		// Cmd/Ctrl+Enter submits, plain Enter inserts a newline for multi-line questions.
		if ( ( event.metaKey || event.ctrlKey ) && event.key === 'Enter' ) {
			event.preventDefault();
			submit();
		}
	};

	return (
		<form
			className="hey-woo-ask-anything"
			onSubmit={ ( event ) => {
				event.preventDefault();
				submit();
			} }
			aria-label={ __( 'Ask anything', 'hey-woo' ) }
		>
			<textarea
				className="hey-woo-ask-anything__input"
				value={ value }
				onChange={ ( event ) => setValue( event.target.value ) }
				onKeyDown={ handleKeyDown }
				placeholder={ __( 'Ask anything', 'hey-woo' ) }
				rows={ 3 }
			/>
			<div className="hey-woo-ask-anything__footer">
				<span className="hey-woo-ask-anything__hint">
					{ __( 'Press ⌘↵ to send', 'hey-woo' ) }
				</span>
				<Button
					type="submit"
					variant="primary"
					size="compact"
					disabled={ '' === value.trim() }
					aria-label={ __( 'Send', 'hey-woo' ) }
					className="hey-woo-ask-anything__send"
				>
					<Icon icon={ arrowRight } size={ 18 } />
				</Button>
			</div>
		</form>
	);
}
