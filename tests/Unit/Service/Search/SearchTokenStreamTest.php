<?php

/**
 * SearchTokenStream reads tokens by cursor and builds refusals that carry the typed term.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Exception\SearchTermSyntaxException;
use OCA\OpenRegister\Service\Search\SearchTokenStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Cursor reads inside and past the end of the stream, and the refusal it builds.
 */
#[CoversClass(SearchTokenStream::class)]
#[UsesClass(SearchTermSyntaxException::class)]
class SearchTokenStreamTest extends TestCase {

	private SearchTokenStream $stream;

	protected function setUp(): void {
		$this->stream = new SearchTokenStream(
			tokens: [
				['type' => 'word', 'value' => 'kat', 'position' => 1],
				['type' => 'and', 'value' => 'AND', 'position' => 5],
				['value' => 'untyped', 'position' => 9],
			],
			term: 'kat AND untyped'
		);
	}//end setUp()

	public function testTokenAtReturnsTheTokenAtTheCursor(): void {
		$this->assertSame(['type' => 'and', 'value' => 'AND', 'position' => 5], $this->stream->tokenAt(cursor: 1));
	}//end testTokenAtReturnsTheTokenAtTheCursor()

	public function testTokenAtPastTheEndIsNull(): void {
		$this->assertNull($this->stream->tokenAt(cursor: 3));
		$this->assertNull($this->stream->tokenAt(cursor: -1));
	}//end testTokenAtPastTheEndIsNull()

	public function testTypeAtReadsTheTokenType(): void {
		$this->assertSame('word', $this->stream->typeAt(cursor: 0));
		$this->assertSame('and', $this->stream->typeAt(cursor: 1));
	}//end testTypeAtReadsTheTokenType()

	public function testTypeAtIsNullForAnUntypedTokenAndPastTheEnd(): void {
		$this->assertNull($this->stream->typeAt(cursor: 2));
		$this->assertNull($this->stream->typeAt(cursor: 7));
	}//end testTypeAtIsNullForAnUntypedTokenAndPastTheEnd()

	public function testRefuseBuildsAnExceptionCarryingReasonPositionAndTerm(): void {
		$refusal = $this->stream->refuse(reason: 'Unexpected operator', position: 5);

		$this->assertInstanceOf(SearchTermSyntaxException::class, $refusal);
		$this->assertSame('Unexpected operator', $refusal->getReason());
		$this->assertSame(5, $refusal->getPosition());
		$this->assertSame('kat AND untyped', $refusal->getTerm());
		$this->assertSame('Unexpected operator at position 5.', $refusal->getMessage());
	}//end testRefuseBuildsAnExceptionCarryingReasonPositionAndTerm()
}//end class
