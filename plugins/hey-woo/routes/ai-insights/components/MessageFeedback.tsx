/**
 * MessageFeedback — thumbs + optional comment on an assistant message.
 *
 * Click a thumb to submit the rating immediately (telemetry + persistence).
 * The comment textarea reveals on success; Send re-saves the message with the
 * comment attached, Skip closes the textarea without a second submission.
 *
 * If the message arrives with existing feedback, the widget renders directly
 * in the muted "done" state without prompting.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { Icon, thumbsDown, thumbsUp } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import type { FeedbackRating, MessageFeedback as Feedback } from '../types';

const COMMENT_MAX_LENGTH = 1000;

type Mode = 'idle' | 'comment' | 'done';

interface MessageFeedbackProps {
	messageId: number;
	feedback?: Feedback;
	onSubmit: (
		messageId: number,
		rating: FeedbackRating,
		comment?: string
	) => Promise< void >;
}

function ratingDoneLabel( rating: FeedbackRating ): string {
	return rating === 'up'
		? __( 'Thanks — marked as helpful.', 'hey-woo' )
		: __( 'Thanks — marked as not helpful.', 'hey-woo' );
}

function ratingChosenLabel( rating: FeedbackRating ): string {
	return rating === 'up'
		? __( 'Helpful', 'hey-woo' )
		: __( 'Not helpful', 'hey-woo' );
}

export function MessageFeedback( { messageId, feedback, onSubmit }: MessageFeedbackProps ) {
	const initialMode: Mode = feedback ? 'done' : 'idle';
	const [ mode, setMode ] = useState< Mode >( initialMode );
	const [ chosenRating, setChosenRating ] = useState< FeedbackRating | undefined >(
		feedback?.rating
	);
	const [ comment, setComment ] = useState( '' );
	const [ pending, setPending ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const handleThumb = async ( rating: FeedbackRating ) => {
		if ( pending ) {
			return;
		}
		setPending( true );
		setError( null );
		try {
			await onSubmit( messageId, rating );
			setChosenRating( rating );
			setMode( 'comment' );
		} catch {
			setError( __( 'Could not save your feedback. Please try again.', 'hey-woo' ) );
		} finally {
			setPending( false );
		}
	};

	const handleSendComment = async () => {
		if ( ! chosenRating || pending ) {
			return;
		}
		const trimmed = comment.trim();
		if ( '' === trimmed ) {
			setMode( 'done' );
			return;
		}
		setPending( true );
		setError( null );
		try {
			await onSubmit( messageId, chosenRating, trimmed );
			setMode( 'done' );
		} catch {
			setError( __( 'Could not save your comment. Please try again.', 'hey-woo' ) );
		} finally {
			setPending( false );
		}
	};

	const handleSkip = () => {
		setMode( 'done' );
	};

	if ( 'done' === mode ) {
		const label = chosenRating ? ratingDoneLabel( chosenRating ) : '';
		return (
			<div className="hey-woo-feedback hey-woo-feedback--done" aria-live="polite">
				<span className="hey-woo-feedback__done-text">{ label }</span>
			</div>
		);
	}

	if ( 'comment' === mode && chosenRating ) {
		const textareaId = `hey-woo-feedback-comment-${ messageId }`;
		return (
			<div className="hey-woo-feedback hey-woo-feedback--comment">
				<div className="hey-woo-feedback__chosen">
					<Icon icon={ 'up' === chosenRating ? thumbsUp : thumbsDown } size={ 18 } />
					<span>{ ratingChosenLabel( chosenRating ) }</span>
				</div>
				<label htmlFor={ textareaId } className="screen-reader-text">
					{ __( 'Add an optional note', 'hey-woo' ) }
				</label>
				<textarea
					id={ textareaId }
					className="hey-woo-feedback__textarea"
					rows={ 2 }
					placeholder={ __( 'Add an optional note (what worked, what missed)…', 'hey-woo' ) }
					value={ comment }
					onChange={ ( event ) => setComment( event.target.value ) }
					maxLength={ COMMENT_MAX_LENGTH }
					disabled={ pending }
				/>
				{ error && <p className="hey-woo-feedback__error">{ error }</p> }
				<div className="hey-woo-feedback__buttons">
					<Button
						variant="primary"
						size="compact"
						onClick={ handleSendComment }
						disabled={ pending || '' === comment.trim() }
					>
						{ __( 'Send', 'hey-woo' ) }
					</Button>
					<Button
						variant="tertiary"
						size="compact"
						onClick={ handleSkip }
						disabled={ pending }
					>
						{ __( 'Skip', 'hey-woo' ) }
					</Button>
				</div>
			</div>
		);
	}

	return (
		<div className="hey-woo-feedback hey-woo-feedback--idle">
			<span className="hey-woo-feedback__prompt">
				{ __( 'Was this helpful?', 'hey-woo' ) }
			</span>
			<button
				type="button"
				className="hey-woo-feedback__thumb hey-woo-feedback__thumb--up"
				onClick={ () => handleThumb( 'up' ) }
				disabled={ pending }
				aria-label={ __( 'Mark as helpful', 'hey-woo' ) }
			>
				<Icon icon={ thumbsUp } size={ 18 } />
			</button>
			<button
				type="button"
				className="hey-woo-feedback__thumb hey-woo-feedback__thumb--down"
				onClick={ () => handleThumb( 'down' ) }
				disabled={ pending }
				aria-label={ __( 'Mark as not helpful', 'hey-woo' ) }
			>
				<Icon icon={ thumbsDown } size={ 18 } />
			</button>
			{ error && <p className="hey-woo-feedback__error">{ error }</p> }
		</div>
	);
}
