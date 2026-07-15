<?php

namespace MediaWiki\Extension\TimeTracker\Tests\Unit;

use MediaWiki\Extension\TimeTracker\EntryWikitext;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\TimeTracker\EntryWikitext
 */
class EntryWikitextTest extends MediaWikiUnitTestCase {

	private EntryWikitext $wikitext;

	protected function setUp(): void {
		parent::setUp();
		$this->wikitext = new EntryWikitext();
	}

	/**
	 * @dataProvider provideRoundTrip
	 */
	public function testEscapeUnescapeRoundTrip( string $value ) {
		$this->assertSame( $value, $this->wikitext->unescape( $this->wikitext->escape( $value ) ) );
	}

	public static function provideRoundTrip(): array {
		return [
			'plain' => [ 'hello world' ],
			'pipe' => [ 'a|b|c' ],
			'braces' => [ 'a {template} b' ],
			'newlines' => [ "line one\nline two\nline three" ],
			'everything' => [ "pipe | brace { } \n newline" ],
			'empty' => [ '' ],
			// Regression: literal decode tokens in user input must survive a
			// round-trip, not be reconstructed and wrongly decoded on read.
			'literal br' => [ 'Fixed the <br> tag rendering' ],
			'literal brace entities' => [ 'code &#123;x&#125;' ],
			'literal amp entity' => [ '&amp; already encoded' ],
			'ampersand and lt' => [ 'a & b < c' ],
		];
	}

	public function testEscapeGuardsTemplateBreakers() {
		$this->assertSame( 'a{{!}}b', $this->wikitext->escape( 'a|b' ) );
		$this->assertSame( '&#123;x&#125;', $this->wikitext->escape( '{x}' ) );
		$this->assertSame( 'one<br>two', $this->wikitext->escape( "one\ntwo" ) );
	}

	public function testBuildProducesTemplateCall() {
		$out = $this->wikitext->build( 'Time entry', [ 'day' => '2026-07-11', 'duration' => '2.5' ] );
		$this->assertStringStartsWith( "{{Time entry\n", $out );
		$this->assertStringContainsString( "|day=2026-07-11\n", $out );
		$this->assertStringContainsString( "|duration=2.5\n", $out );
		$this->assertStringEndsWith( '}}', $out );
	}

	public function testFieldReadsBackBuiltValues() {
		$out = $this->wikitext->build( 'Time entry', [
			'customer' => 'Customer 12345',
			'notes' => "first line\nsecond line",
		] );
		$this->assertSame( 'Customer 12345', $this->wikitext->field( $out, 'customer' ) );
		// A multi-line note is stored on one line as <br> and comes back with newlines.
		$this->assertSame( "first line\nsecond line", $this->wikitext->field( $out, 'notes' ) );
	}

	public function testFieldMissingReturnsEmpty() {
		$out = $this->wikitext->build( 'Time entry', [ 'day' => '2026-07-11' ] );
		$this->assertSame( '', $this->wikitext->field( $out, 'notes' ) );
	}

	public function testEmptyFieldDoesNotSwallowNextLine() {
		// Regression: an empty value must not capture the following line's "}}".
		$out = $this->wikitext->build( 'Time entry', [ 'notes' => '', 'duration' => '1' ] );
		$this->assertSame( '', $this->wikitext->field( $out, 'notes' ) );
		$this->assertSame( '1', $this->wikitext->field( $out, 'duration' ) );
	}

	public function testNormalizeNoteFoldsLineEndings() {
		$this->assertSame( "a\nb", $this->wikitext->normalizeNote( "  a\r\nb  " ) );
	}

	public function testUnfoldNewlinesOnlyReversesBrFolding() {
		// SMW-sourced values are already entity-decoded; only <br> folding remains.
		$this->assertSame( "one\ntwo", $this->wikitext->unfoldNewlines( 'one<br>two' ) );
		// The other escape tokens must be left alone (they are literal user text).
		$this->assertSame( 'cost is &#123;x&#125; | 50% & up',
			$this->wikitext->unfoldNewlines( 'cost is &#123;x&#125; | 50% & up' ) );
	}
}
