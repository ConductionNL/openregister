<?php

/**
 * What an MCP tool invocation writes into its audit record: an
 * order-independent digest of the arguments and a small outcome summary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationDigest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ToolInvocationDigest.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationDigest
 */
class ToolInvocationDigestTest extends TestCase {

	public function testTheDigestIsTheSha256OfTheCanonicalJson(): void {
		$this->assertSame(
			hash('sha256', '{"a":"é/x","b":1}'),
			ToolInvocationDigest::paramsDigest(['b' => 1, 'a' => 'é/x'])
		);
	}//end testTheDigestIsTheSha256OfTheCanonicalJson()

	public function testTheDigestDoesNotDependOnKeyOrderAtAnyDepth(): void {
		$one = ToolInvocationDigest::paramsDigest(['x' => ['b' => 2, 'a' => ['d' => 4, 'c' => 3]], 'y' => 1]);
		$two = ToolInvocationDigest::paramsDigest(['y' => 1, 'x' => ['a' => ['c' => 3, 'd' => 4], 'b' => 2]]);

		$this->assertSame($one, $two);
		$this->assertSame(hash('sha256', '{"x":{"a":{"c":3,"d":4},"b":2},"y":1}'), $one);
	}//end testTheDigestDoesNotDependOnKeyOrderAtAnyDepth()

	public function testDifferentValuesGiveDifferentDigests(): void {
		$this->assertNotSame(
			ToolInvocationDigest::paramsDigest(['a' => 1]),
			ToolInvocationDigest::paramsDigest(['a' => 2])
		);
	}//end testDifferentValuesGiveDifferentDigests()

	public function testUnencodableArgumentsDigestTheEmptyString(): void {
		$this->assertSame(hash('sha256', ''), ToolInvocationDigest::paramsDigest(['bad' => "\xB1\x31"]));
	}//end testUnencodableArgumentsDigestTheEmptyString()

	public function testTheIdSummaryCarriesAStringIdOnly(): void {
		$this->assertSame(['isError' => false, 'id' => 'abc'], ToolInvocationDigest::idSummary(['id' => 'abc', 'x' => 1]));
		$this->assertSame(['isError' => false, 'id' => null], ToolInvocationDigest::idSummary(['id' => 42]));
		$this->assertSame(['isError' => false, 'id' => null], ToolInvocationDigest::idSummary([]));
	}//end testTheIdSummaryCarriesAStringIdOnly()

	public function testTheResultIdOfNoResultIsNull(): void {
		$this->assertNull(ToolInvocationDigest::resultId(null));
		$this->assertSame('u-1', ToolInvocationDigest::resultId(['id' => 'u-1']));
	}//end testTheResultIdOfNoResultIsNull()

	public function testTheErrorSummaryNamesTheClassAndTruncatesTheMessage(): void {
		$summary = ToolInvocationDigest::errorSummary(new InvalidArgumentException(str_repeat('x', 500)));

		$this->assertSame(true, $summary['isError']);
		$this->assertSame(InvalidArgumentException::class, $summary['errorClass']);
		$this->assertSame(str_repeat('x', 300), $summary['message']);
	}//end testTheErrorSummaryNamesTheClassAndTruncatesTheMessage()

	public function testAShortErrorMessageIsKeptWhole(): void {
		$this->assertSame(
			['isError' => true, 'errorClass' => RuntimeException::class, 'message' => 'boom'],
			ToolInvocationDigest::errorSummary(new RuntimeException('boom'))
		);
	}//end testAShortErrorMessageIsKeptWhole()
}//end class
