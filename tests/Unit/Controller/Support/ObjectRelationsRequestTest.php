<?php

/**
 * Unit test for ObjectRelationsRequest.
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

use OCA\OpenRegister\Controller\Support\ObjectRelationsRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Depth, language, string, CSV and list parameters of the relations routes.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ObjectRelationsRequest
 */
class ObjectRelationsRequestTest extends TestCase {
	/**
	 * A request reader answering from the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ObjectRelationsRequest The reader.
	 */
	private function requestOf(array $params): ObjectRelationsRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default=null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);

		return new ObjectRelationsRequest(request: $request);
	}

	public function testDepthDefaultsToOne(): void {
		$this->assertSame(1, $this->requestOf([])->depth());
	}

	public function testDepthReadsNumbersAndFloorsAtOne(): void {
		$this->assertSame(3, $this->requestOf(['depth' => '3'])->depth());
		$this->assertSame(1, $this->requestOf(['depth' => '0'])->depth());
		$this->assertSame(1, $this->requestOf(['depth' => '-4'])->depth());
		$this->assertSame(1, $this->requestOf(['depth' => 'deep'])->depth());
	}

	public function testOptionalDepthIsNullWithoutAParameter(): void {
		$this->assertNull($this->requestOf([])->optionalDepth());
		$this->assertNull($this->requestOf(['depth' => '  '])->optionalDepth());
		$this->assertSame(2, $this->requestOf(['depth' => '2'])->optionalDepth());
	}

	public function testLanguageDefaultsToDutchAndTrimsAnExplicitOne(): void {
		$this->assertSame('nl', $this->requestOf([])->language());
		$this->assertSame('nl', $this->requestOf(['language' => '   '])->language());
		$this->assertSame('en', $this->requestOf(['language' => ' en '])->language());
	}

	public function testStringParamTrimsAndDropsBlanksAndNonStrings(): void {
		$request = $this->requestOf(['a' => ' x ', 'b' => ' ', 'n' => 5]);

		$this->assertSame('x', $request->stringParam('a'));
		$this->assertNull($request->stringParam('b'));
		$this->assertNull($request->stringParam('n'));
		$this->assertNull($request->stringParam('missing'));
	}

	public function testCsvParamSplitsTrimsAndDropsEmptyFields(): void {
		$this->assertSame(['a', 'b', 'c'], $this->requestOf(['f' => ' a, b,, c ,'])->csvParam('f'));
		$this->assertSame([], $this->requestOf([])->csvParam('f'));
	}

	public function testListParamAcceptsArraysAndCommaStrings(): void {
		$this->assertSame(['x', 'y'], $this->requestOf(['l' => [' x', '', 'y ']])->listParam('l'));
		$this->assertSame(['1', '2'], $this->requestOf(['l' => '1, ,2'])->listParam('l'));
		$this->assertSame(['7'], $this->requestOf(['l' => 7])->listParam('l'));
		$this->assertNull($this->requestOf([])->listParam('l'));
	}

	public function testIdOfReadsNumericReferencesOnly(): void {
		$request = $this->requestOf([]);

		$this->assertSame(12, $request->idOf('12'));
		$this->assertNull($request->idOf('abc-uuid'));
		$this->assertNull($request->idOf(null));
	}
}
