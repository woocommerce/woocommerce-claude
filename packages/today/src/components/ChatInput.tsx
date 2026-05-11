/**
 * ChatInput — the message compose bar at the bottom of the chat.
 */
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface ChatInputProps {
	onSend: ( message: string ) => void;
	disabled: boolean;
}

export function ChatInput( { onSend, disabled }: ChatInputProps ) {
	const [ value, setValue ] = useState( '' );
	const textareaRef = useRef< HTMLTextAreaElement >( null );

	const handleSubmit = () => {
		const trimmed = value.trim();
		if ( ! trimmed || disabled ) {
			return;
		}
		onSend( trimmed );
		setValue( '' );
		textareaRef.current?.focus();
	};

	const handleKeyDown = ( e: React.KeyboardEvent< HTMLTextAreaElement > ) => {
		// Send on Enter (without Shift) for a compact compose experience.
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSubmit();
		}
	};

	return (
		<div className="hey-woo-input">
			<textarea
				ref={ textareaRef }
				className="hey-woo-input__textarea"
				value={ value }
				onChange={ ( e ) => setValue( e.target.value ) }
				onKeyDown={ handleKeyDown }
				placeholder={ __( 'Ask about your store…', 'woocommerce-claude' ) }
				rows={ 2 }
				disabled={ disabled }
				aria-label={ __( 'Chat message', 'woocommerce-claude' ) }
			/>
			<button
				type="button"
				className="button button-primary hey-woo-input__send"
				onClick={ handleSubmit }
				disabled={ disabled || ! value.trim() }
				aria-label={ __( 'Send message', 'woocommerce-claude' ) }
			>
				{ disabled ? __( '…', 'woocommerce-claude' ) : __( 'Send', 'woocommerce-claude' ) }
			</button>
		</div>
	);
}
