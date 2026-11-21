<?php

/**
 * Unit test for GdprRequestParams.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTime;
use OCA\OpenRegister\Controller\Support\GdprRequestParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Optional string, date and bounded integer parameters of the AVG register endpoints.
 *
 * @covers \OCA\OpenRegister\Controller\Support\GdprRequestParams
 */
class GdprRequestParamsTest extends TestCase {
	/**
	 * A reader over a request that answers from the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return GdprRequestParams The reader.
	 */
	private function paramsOf(array $params): GdprRequestParams {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		return new GdprRequestParams(request: $request);
	}

	public function testNonEmptyCastsValuesAndDropsAbsentOrEmpty(): void {
		$params = $this->paramsOf(['a' => 'x', 'n' => 7, 'e' => '']);

		$this->assertSame('x', $params->nonEmpty('a'));
		$this->assertSame('7', $params->nonEmpty('n'));
		$this->assertNull($params->nonEmpty('e'));
		$this->assertNull($params->nonEmpty('missing'));
	}

	public function testNonEmptyKeepsWhitespaceAsGiven(): void {
		$this->assertSame('  ', $this->paramsOf(['s' => '  '])->nonEmpty('s'));
	}

	public function testTrimmedTrimsStringsAndDropsNonStringsAndBlanks(): void {
		$params = $this->paramsOf(['a' => '  hello ', 'n' => 5, 'b' => "  \t"]);

		$this->assertSame('hello', $params->trimmed('a'));
		$this->assertNull($params->trimmed('n'));
		$this->assertNull($params->trimmed('b'));
		$this->assertNull($params->trimmed('missing'));
	}

	public function testCastTrimmedCastsNumbersInsteadOfDroppingThem(): void {
		$params = $this->paramsOf(['n' => 42, 's' => ' x ', 'b' => '   ']);

		$this->assertSame('42', $params->castTrimmed('n'));
		$this->assertSame('x', $params->castTrimmed('s'));
		$this->assertNull($params->castTrimmed('b'));
		$this->assertNull($params->castTrimmed('missing'));
	}

	public function testDateParsesAValidDate(): void {
		$date = $this->paramsOf([])->date('2026-03-04');

		$this->assertInstanceOf(DateTime::class, $date);
		$this->assertSame('2026-03-04', $date->format('Y-m-d'));
	}

	public function testDateIsNullForAbsentOrUnparseableValues(): void {
		$params = $this->paramsOf([]);

		$this->assertNull($params->date(null));
		$this->assertNull($params->date('not a date at all'));
	}

	public function testBoundedIntUsesTheDefaultForAbsentNonNumericOrNegative(): void {
		$params = $this->paramsOf(['x' => 'abc', 'neg' => '-3']);

		$this->assertSame(10, $params->boundedInt('missing', 10, 100));
		$this->assertSame(10, $params->boundedInt('x', 10, 100));
		$this->assertSame(10, $params->boundedInt('neg', 10, 100));
	}

	public function testBoundedIntClampsToTheMaximum(): void {
		$params = $this->paramsOf(['big' => '500', 'ok' => '40', 'zero' => 0]);

		$this->assertSame(100, $params->boundedInt('big', 10, 100));
		$this->assertSame(40, $params->boundedInt('ok', 10, 100));
		$this->assertSame(0, $params->boundedInt('zero', 10, 100));
	}

	public function testPayloadReturnsEveryParameter(): void {
		$this->assertSame(['a' => 1, 'b' => 'two'], $this->paramsOf(['a' => 1, 'b' => 'two'])->payload());
	}
}
