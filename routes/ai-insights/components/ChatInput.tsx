/**
 * ChatInput — the message compose bar at the bottom of the chat.
 */
import { useState, useRef } from '@wordpress/element';
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { send } from '@wordpress/icons';

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

	const handleFormSubmit = ( e: React.FormEvent< HTMLFormElement > ) => {
		e.preventDefault();
		handleSubmit();
	};

	return (
		<form className="hey-woo-input" onSubmit={ handleFormSubmit }>
			<TextareaControl
				ref={ textareaRef }
				className="hey-woo-input__textarea"
				label={ __( 'Chat message', 'woocommerce-claude' ) }
				hideLabelFromVision
				value={ value }
				onChange={ setValue }
				onKeyDown={ handleKeyDown }
				placeholder={ __( 'Ask about your store…', 'woocommerce-claude' ) }
				rows={ 2 }
				disabled={ disabled }
			/>
			<Button
				type="submit"
				className="hey-woo-input__send"
				variant="primary"
				icon={ send }
				iconPosition="right"
				__next40pxDefaultSize
				disabled={ disabled || ! value.trim() }
				isBusy={ disabled }
				accessibleWhenDisabled
				aria-label={ __( 'Send message', 'woocommerce-claude' ) }
			>
				{ __( 'Send', 'woocommerce-claude' ) }
			</Button>
		</form>
	);
}
