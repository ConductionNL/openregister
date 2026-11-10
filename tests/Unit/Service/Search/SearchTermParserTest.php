<?php

/**
 * Tests for the boolean search-term parser.
 *
 * Two properties carry the whole change and both are pinned here.
 *
 * A term with no operator, bracket, quote or wildcard must NOT be parsed, so
 * `dakkapel geweigerd` keeps meaning the substring it has always meant rather
 * than silently becoming a conjunction.
 *
 * A term the parser cannot read must be refused with the position of the fault.
 * The alternative the codebase used to take — fall back to a literal string —
 * returns zero rows, which on screen is indistinguishable from a search that
 * legitimately found nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Exception\SearchTermSyntaxException;
use OCA\OpenRegister\Service\Search\SearchTermNode;
use OCA\OpenRegister\Service\Search\SearchTermParser;
use OCA\OpenRegister\Service\Search\SearchTermSqlCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Locks the grammar of `_search`.
 */
class SearchTermParserTest extends TestCase {

	private SearchTermParser $parser;

	private SearchTermSqlCompiler $compiler;

	protected function setUp(): void {
		$this->parser = new SearchTermParser();
		$this->compiler = new SearchTermSqlCompiler();
	}//end setUp()

	/**
	 * Compile a term to readable SQL using a stand-in leaf builder.
	 *
	 * @param string $term The search term.
	 *
	 * @return string The compiled expression.
	 */
	private function compile(string $term): string {
		return $this->compiler->compile(
			node: $this->parser->parse(term: $term),
			leafBuilder: static fn (string $pattern, string $literal): string => "col~'{$pattern}'"
		);
	}//end compile()

	/**
	 * Terms that must NOT engage the parser. This is the backwards-compatibility
	 * guarantee: every one of these keeps the substring match it had before.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function operatorFreeTerms(): array {
		return [
			'two plain words' => ['dakkapel geweigerd'],
			'lower-case and is a word, not an operator' => ['hond and kat'],
			'lower-case not is a word, not an operator' => ['not gevonden'],
			'a percent sign the user typed' => ['50% korting'],
			'an underscore the user typed' => ['snake_case'],
			'a single word' => ['vergunning'],
		];
	}//end operatorFreeTerms()

	/**
	 * A term with no operator keeps its current meaning: the parser declines it.
	 *
	 * @param string $term The search term.
	 *
	 * @dataProvider operatorFreeTerms
	 *
	 * @return void
	 */
	public function testAnOperatorFreeTermIsNotParsed(string $term): void {
		$this->assertFalse(
			$this->parser->needsParsing(term: $term),
			"'{$term}' must keep the substring match it had before this change."
		);
	}//end testAnOperatorFreeTermIsNotParsed()

	/**
	 * Terms that must engage the parser.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function parsedTerms(): array {
		return [
			'AND' => ['dakkapel AND geweigerd'],
			'OR' => ['dakkapel OR geweigerd'],
			'NOT' => ['dakkapel NOT geweigerd'],
			'brackets' => ['dakkapel (geweigerd)'],
			'a quoted phrase' => ['"dak kapel"'],
			'a trailing wildcard' => ['vergunning*'],
			'a leading wildcard' => ['*vergunning'],
		];
	}//end parsedTerms()

	/**
	 * @param string $term The search term.
	 *
	 * @dataProvider parsedTerms
	 *
	 * @return void
	 */
	public function testAnOperatorBearingTermIsParsed(string $term): void {
		$this->assertTrue($this->parser->needsParsing(term: $term));
	}//end testAnOperatorBearingTermIsParsed()

	/**
	 * The scenario from the spec: a caseworker excludes a word.
	 *
	 * @return void
	 */
	public function testAndNotExcludesTheSecondWord(): void {
		$this->assertSame(
			"((col~'%dakkapel%') AND (NOT (col~'%geweigerd%')))",
			$this->compile('dakkapel AND NOT geweigerd')
		);
	}//end testAndNotExcludesTheSecondWord()

	/**
	 * A trailing wildcard anchors the start, so `vergunning*` reaches
	 * `vergunningaanvraag` without reaching `bouwvergunning`.
	 *
	 * @return void
	 */
	public function testATrailingWildcardAnchorsTheStart(): void {
		$this->assertSame("(col~'vergunning%')", $this->compile('vergunning*'));
	}//end testATrailingWildcardAnchorsTheStart()

	/**
	 * A leading wildcard anchors the end.
	 *
	 * @return void
	 */
	public function testALeadingWildcardAnchorsTheEnd(): void {
		$this->assertSame("(col~'%vergunning')", $this->compile('*vergunning'));
	}//end testALeadingWildcardAnchorsTheEnd()

	/**
	 * A wildcarded word with no star at all keeps the two-sided substring match,
	 * which is why an operator-free term is unchanged by this feature.
	 *
	 * @return void
	 */
	public function testAWordWithNoWildcardKeepsTheTwoSidedMatch(): void {
		$this->assertSame(
			"((col~'%dakkapel%') AND (col~'%geweigerd%'))",
			$this->compile('dakkapel AND geweigerd')
		);
	}//end testAWordWithNoWildcardKeepsTheTwoSidedMatch()

	/**
	 * Brackets bind tighter than the surrounding AND.
	 *
	 * @return void
	 */
	public function testBracketsGroup(): void {
		$this->assertSame(
			"((col~'%dakkapel%') AND ((col~'%geweigerd%') OR (col~'%verleend%')))",
			$this->compile('dakkapel AND (geweigerd OR verleend)')
		);
	}//end testBracketsGroup()

	/**
	 * Two terms side by side inside a parsed term are a conjunction.
	 *
	 * @return void
	 */
	public function testAdjacentTermsAreAConjunction(): void {
		$this->assertSame(
			"((col~'%dakkapel%') AND (NOT (col~'%geweigerd%')))",
			$this->compile('dakkapel NOT geweigerd')
		);
	}//end testAdjacentTermsAreAConjunction()

	/**
	 * A quoted phrase is one term, spaces and all, and a `*` inside it is a
	 * literal asterisk.
	 *
	 * @return void
	 */
	public function testAQuotedPhraseIsOneTerm(): void {
		$this->assertSame("(col~'%dak kapel%')", $this->compile('"dak kapel"'));
	}//end testAQuotedPhraseIsOneTerm()

	/**
	 * A `%` or `_` the user typed must match itself once the term is parsed,
	 * rather than acting as a LIKE wildcard.
	 *
	 * @return void
	 */
	public function testLikeMetacharactersAreEscapedInsideAParsedTerm(): void {
		$this->assertSame("(col~'50\\%%')", $this->compile('50%*'));
		$this->assertSame("((col~'%snake\\_case%') OR (col~'%b%'))", $this->compile('snake_case OR b'));
	}//end testLikeMetacharactersAreEscapedInsideAParsedTerm()

	/**
	 * The four malformed terms the change's tasks name, each refused with a
	 * position rather than evaluated as a literal.
	 *
	 * @return array<string, array{0: string, 1: int}> Term and expected position.
	 */
	public static function malformedTerms(): array {
		return [
			'unbalanced opening bracket' => ['dakkapel AND (geweigerd', 14],
			'unexpected closing bracket' => ['dakkapel) AND geweigerd', 9],
			'dangling AND' => ['dakkapel AND', 10],
			'unterminated quote' => ['dakkapel AND "geweigerd', 14],
			'dangling OR' => ['dakkapel OR', 10],
			'leading operator' => ['AND geweigerd', 1],
			'empty group' => ['dakkapel AND ()', 14],
			'a wildcard with no term' => ['dakkapel AND *', 14],
		];
	}//end malformedTerms()

	/**
	 * @param string $term     The malformed term.
	 * @param int    $position The 1-based position of the fault.
	 *
	 * @dataProvider malformedTerms
	 *
	 * @return void
	 */
	public function testAMalformedTermIsRefusedWithItsPosition(string $term, int $position): void {
		$this->assertTrue(
			$this->parser->needsParsing(term: $term),
			'A malformed term must reach the parser, not the literal path.'
		);

		try {
			$this->parser->parse(term: $term);
		} catch (SearchTermSyntaxException $exception) {
			$this->assertSame($position, $exception->getPosition());
			$this->assertStringContainsString(
				'at position ' . $position,
				$exception->getMessage()
			);
			$this->assertSame($term, $exception->getTerm());
			return;
		}

		$this->fail("'{$term}' was accepted; a malformed term must be refused.");
	}//end testAMalformedTermIsRefusedWithItsPosition()

	/**
	 * A nested NOT collapses the way a reader expects.
	 *
	 * @return void
	 */
	public function testDoubleNegationNests(): void {
		$this->assertSame("(NOT (NOT (col~'%a%')))", $this->compile('NOT NOT a'));
	}//end testDoubleNegationNests()

	/**
	 * A single operand needs no wrapper node, so the compiled SQL stays flat.
	 *
	 * @return void
	 */
	public function testASingleOperandIsNotWrapped(): void {
		$node = SearchTermNode::all(children: [SearchTermNode::term(value: 'a')]);
		$this->assertSame(SearchTermNode::TYPE_TERM, $node->type);
	}//end testASingleOperandIsNotWrapped()
}//end class
