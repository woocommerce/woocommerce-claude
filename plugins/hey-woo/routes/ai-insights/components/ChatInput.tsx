/**
 * ChatInput — the message compose bar at the bottom of the chat.
 */
import { useMemo, useRef, useState } from '@wordpress/element';
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { send } from '@wordpress/icons';
import { WORKFLOWS } from '../workflows';
import type { WorkflowAction } from '../workflows';

interface ChatInputProps {
	onSend: ( message: string ) => void;
	disabled: boolean;
}

function commandMatchRank( workflow: WorkflowAction, query: string ): number | null {
	const label = workflow.label.toLowerCase();
	const slug = workflow.slug.toLowerCase();
	const slugParts = slug.split( '-' );

	if ( '' === query ) {
		return 0;
	}

	if ( label.startsWith( query ) ) {
		return 0;
	}

	if ( slug.startsWith( query ) ) {
		return 1;
	}

	if ( slugParts.some( ( part ) => part.startsWith( query ) ) ) {
		return 2;
	}

	if ( label.includes( query ) ) {
		return 3;
	}

	if ( slug.includes( query ) ) {
		return 4;
	}

	return null;
}

export function ChatInput( { onSend, disabled }: ChatInputProps ) {
	const [ value, setValue ] = useState( '' );
	const [ activeCommandIndex, setActiveCommandIndex ] = useState( 0 );
	const [ dismissedCommandValue, setDismissedCommandValue ] = useState( '' );
	const textareaRef = useRef< HTMLTextAreaElement >( null );
	const commandMatch = value.match( /^\/([a-z0-9-]*)$/i );
	const commandQuery = commandMatch ? commandMatch[1].toLowerCase() : null;
	const matchingCommands = useMemo( () => {
		if ( null === commandQuery ) {
			return [];
		}

		return WORKFLOWS.map( ( workflow, index ) => ( {
			index,
			rank: commandMatchRank( workflow, commandQuery ),
			workflow,
		} ) )
			.filter( ( match ) => null !== match.rank )
			.sort( ( a, b ) => {
				if ( a.rank !== b.rank ) {
					return ( a.rank ?? 0 ) - ( b.rank ?? 0 );
				}

				return a.index - b.index;
			} )
			.map( ( match ) => match.workflow );
	}, [ commandQuery ] );
	const isCommandMenuOpen = null !== commandQuery && value !== dismissedCommandValue && ! disabled;
	const activeCommand = matchingCommands[ Math.min( activeCommandIndex, matchingCommands.length - 1 ) ];

	const handleSubmit = () => {
		const trimmed = value.trim();
		if ( ! trimmed || disabled ) {
			return;
		}
		onSend( trimmed );
		setValue( '' );
		setDismissedCommandValue( '' );
		textareaRef.current?.focus();
	};

	const handleChange = ( nextValue: string ) => {
		setValue( nextValue );
		setActiveCommandIndex( 0 );
	};

	const insertCommand = ( workflow: WorkflowAction | undefined ) => {
		if ( ! workflow || disabled ) {
			return;
		}

		setValue( `/${ workflow.slug } ` );
		setDismissedCommandValue( '' );
		textareaRef.current?.focus();
	};

	const handleKeyDown = ( e: React.KeyboardEvent< HTMLTextAreaElement > ) => {
		if ( isCommandMenuOpen && matchingCommands.length > 0 ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActiveCommandIndex( ( current ) => ( current + 1 ) % matchingCommands.length );
				return;
			}

			if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActiveCommandIndex( ( current ) => (
					( current - 1 + matchingCommands.length ) % matchingCommands.length
				) );
				return;
			}

			if ( e.key === 'Enter' || e.key === 'Tab' ) {
				e.preventDefault();
				insertCommand( activeCommand );
				return;
			}
		}

		if ( isCommandMenuOpen && 'Escape' === e.key ) {
			e.preventDefault();
			setDismissedCommandValue( value );
			return;
		}

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
			<div className="hey-woo-input__main">
				<TextareaControl
					ref={ textareaRef }
					className="hey-woo-input__textarea"
					label={ __( 'Chat message', 'hey-woo' ) }
					hideLabelFromVision
					value={ value }
					onChange={ handleChange }
					onKeyDown={ handleKeyDown }
					placeholder={ __( 'Ask about your store, or type / to see workflows…', 'hey-woo' ) }
					rows={ 2 }
					disabled={ disabled }
					aria-expanded={ isCommandMenuOpen }
					aria-controls={ isCommandMenuOpen ? 'hey-woo-command-menu' : undefined }
				/>
				{ isCommandMenuOpen && (
					<div id="hey-woo-command-menu" className="hey-woo-input__commands" role="listbox">
						{ matchingCommands.length > 0 ? (
							matchingCommands.map( ( workflow, index ) => (
								<button
									key={ workflow.slug }
									type="button"
									role="option"
									aria-selected={ workflow === activeCommand }
									className={ `hey-woo-input__command${ index === activeCommandIndex ? ' is-active' : '' }` }
									onMouseDown={ ( event ) => event.preventDefault() }
									onClick={ () => insertCommand( workflow ) }
								>
									<span className="hey-woo-input__command-title">{ workflow.label }</span>
									<span className="hey-woo-input__command-slug">/{ workflow.slug }</span>
									<span className="hey-woo-input__command-description">{ workflow.description }</span>
								</button>
							) )
						) : (
							<div className="hey-woo-input__commands-empty">
								{ __( 'No matching workflows', 'hey-woo' ) }
							</div>
						) }
					</div>
				) }
			</div>
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
				aria-label={ __( 'Send message', 'hey-woo' ) }
			>
				{ __( 'Send', 'hey-woo' ) }
			</Button>
		</form>
	);
}
