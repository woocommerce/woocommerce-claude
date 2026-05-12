/**
 * MarkdownContent — render assistant Markdown replies as safe HTML.
 *
 * WordPress core does not include a general Markdown parser. We use
 * markdown-it with raw HTML disabled, so model-authored HTML is escaped while
 * normal Markdown tables, links, headings, and emphasis render correctly.
 */
import MarkdownIt from 'markdown-it';

interface MarkdownContentProps {
	content: string;
}

const markdown = new MarkdownIt( {
	html: false,
	linkify: false,
	typographer: false,
	breaks: true,
} );

const defaultLinkOpen =
	markdown.renderer.rules.link_open ||
	function ( tokens, idx, options, env, self ) {
		return self.renderToken( tokens, idx, options );
	};

markdown.renderer.rules.link_open = function ( tokens, idx, options, env, self ) {
	const token = tokens[ idx ];
	token.attrSet( 'target', '_blank' );
	token.attrSet( 'rel', 'noreferrer noopener' );
	return defaultLinkOpen( tokens, idx, options, env, self );
};

export function MarkdownContent( { content }: MarkdownContentProps ) {
	return (
		<div
			className="hey-woo-bubble__content hey-woo-bubble__content--markdown"
			dangerouslySetInnerHTML={ { __html: markdown.render( content ) } }
		/>
	);
}
