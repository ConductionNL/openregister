<?php

/**
 * Golden test for the recursive-descent half of SearchTermParser.
 *
 * Pins the exact tree and the exact refusal (reason and position) for every
 * branch of parseOr / parseAnd / parseNot / parsePrimary / parseGroup, so the
 * refactor that carries the tokens and the term in one value object can be
 * proven to change nothing.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for parse trees and parse refusals.
 */
class SearchTermParserCursorGoldenTest extends TestCase {

	/**
	 * Render a node tree as a compact, order-preserving string.
	 *
	 * @param SearchTermNode $node The node.
	 *
	 * @return string The rendering.
	 */
	private function render(SearchTermNode $node): string {
		if ($node->children === []) {
			$leading = '';
			if ($node->leadingWildcard === true) {
				$leading = '*';
			}

			$trailing = '';
			if ($node->trailingWildcard === true) {
				$trailing = '*';
			}

			return $node->type . ':' . $leading . $node->value . $trailing;
		}

		$children = array_map(fn (SearchTermNode $child): string => $this->render(node: $child), $node->children);

		return $node->type . '(' . implode(',', $children) . ')';
	}//end render()

	/**
	 * Terms that parse, with the tree they parse to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function treeProvider(): array {
		return [
			'single word'         => ['"x"', 'term:x'],
			'implicit and'        => ['a b*', 'and(term:a,term:b*)'],
			'explicit and'        => ['a AND b', 'and(term:a,term:b)'],
			'or'                  => ['a OR b OR *c', 'or(term:a,term:b,term:*c)'],
			'precedence'          => ['a b OR c', 'or(and(term:a,term:b),term:c)'],
			'not'                 => ['NOT a', 'not(term:a)'],
			'double not'          => ['NOT NOT a', 'not(not(term:a))'],
			'not in conjunction'  => ['a NOT b', 'and(term:a,not(term:b))'],
			'group'               => ['(a OR b) c', 'and(or(term:a,term:b),term:c)'],
			'nested group'        => ['((a)) OR "b c"', 'or(term:a,term:b c)'],
			'not group'           => ['NOT (a b)', 'not(and(term:a,term:b))'],
			'and then group'      => ['a AND (b OR c)', 'and(term:a,or(term:b,term:c))'],
		];
	}//end treeProvider()

	/**
	 * Every accepted shape parses to the pinned tree.
	 *
	 * @param string $term     The search term.
	 * @param string $expected The rendered tree.
	 *
	 * @return void
	 */
	#[DataProvider('treeProvider')]
	public function testParsesToThePinnedTree(string $term, string $expected): void {
		$this->assertSame($expected, $this->render(node: (new SearchTermParser())->parse(term: $term)));
	}//end testParsesToThePinnedTree()

	/**
	 * Terms that are refused, with the reason and the 1-based position.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function refusalProvider(): array {
		return [
			'empty'                 => ['   ', 'The search term is empty', 1],
			'stray close'           => ['a ) b', 'Unexpected closing bracket', 3],
			'leading close'         => [')', 'Unexpected closing bracket', 1],
			'or without right'      => ['a OR', 'OR needs a term after it', 3],
			'or before close'       => ['(a OR )', 'OR needs a term after it', 4],
			'and without right'     => ['a AND OR b', 'AND needs a term after it', 3],
			'not without operand'   => ['a NOT', 'NOT needs a term after it', 3],
			'leading or'            => ['OR a', 'OR needs a term before it', 1],
			'leading and'           => ['AND a', 'AND needs a term before it', 1],
			'empty group'           => ['a ( )', 'Empty group opened', 3],
			'unbalanced open'       => ['(a b', 'Unbalanced opening bracket', 1],
			'nested unbalanced'     => ['x ((a)', 'Unbalanced opening bracket', 3],
			'open at end'           => ['a (', 'The search term ends where a word was expected', 4],
		];
	}//end refusalProvider()

	/**
	 * Every refusal keeps its pinned reason, position and term.
	 *
	 * @param string $term     The search term.
	 * @param string $reason   The expected reason.
	 * @param int    $position The expected position.
	 *
	 * @return void
	 */
	#[DataProvider('refusalProvider')]
	public function testRefusesWithThePinnedReasonAndPosition(string $term, string $reason, int $position): void {
		try {
			(new SearchTermParser())->parse(term: $term);
		} catch (SearchTermSyntaxException $exception) {
			$this->assertSame(
				[$reason, $position, $term],
				[$exception->getReason(), $exception->getPosition(), $exception->getTerm()]
			);
			return;
		}

		$this->fail('Expected a refusal for ' . $term);
	}//end testRefusesWithThePinnedReasonAndPosition()
}//end class
