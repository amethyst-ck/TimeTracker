<?php

namespace MediaWiki\Extension\TimeTracker;

/**
 * Serializes and parses the {{Time entry|…}} / {{Running timer|…}} template
 * calls the extension writes to wiki pages, one field per line.
 */
class EntryWikitext {

	/** Assemble a {{$template|key=value…}} call from an ordered field map. */
	public function build( string $template, array $fields ): string {
		$out = '{{' . $template . "\n";
		foreach ( $fields as $key => $value ) {
			$out .= '|' . $key . '=' . $this->escape( (string)$value ) . "\n";
		}
		return $out . '}}';
	}

	/**
	 * The unescaped value of one field, or '' if absent. [ \t]* (not \s*) around
	 * "=" so an empty value can't swallow the newline into the next line.
	 */
	public function field( string $text, string $name ): string {
		if ( preg_match( '/\|[ \t]*' . preg_quote( $name, '/' ) . '[ \t]*=[ \t]*([^\n]*)/', $text, $m ) ) {
			return $this->unescape( trim( $m[1] ) );
		}
		return '';
	}

	/**
	 * Guard pipes/braces from breaking the call; fold newlines to <br>. Encode &
	 * and < first so a literal '&#123;'/'&#125;'/'<br>' a user types can't be
	 * reconstructed and wrongly decoded — keeping escape()/unescape() a bijection.
	 * strtr replaces in a single pass, so the '&' in a replacement isn't re-encoded.
	 */
	public function escape( string $value ): string {
		return strtr( $value, [
			'&' => '&amp;', '<' => '&lt;',
			'|' => '{{!}}', '{' => '&#123;', '}' => '&#125;', "\n" => '<br>',
		] );
	}

	public function unescape( string $value ): string {
		return strtr( $value, [
			'{{!}}' => '|', '&#123;' => '{', '&#125;' => '}', '<br>' => "\n",
			'&lt;' => '<', '&amp;' => '&',
		] );
	}

	/**
	 * Restore newlines in a note read back from the SMW store rather than raw page
	 * wikitext. MediaWiki decodes the entity escapes ({{!}}, &#123;, &lt;, &amp;)
	 * before SMW captures the value, so only the newline↔<br> folding survives —
	 * reversing the others here would wrongly decode text the user typed literally.
	 */
	public function unfoldNewlines( string $value ): string {
		return str_replace( '<br>', "\n", $value );
	}

	/** Normalize line endings, keeping internal newlines (multi-line notes). */
	public function normalizeNote( string $note ): string {
		return trim( str_replace( [ "\r\n", "\r" ], "\n", $note ) );
	}
}
