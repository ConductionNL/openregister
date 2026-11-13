<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Search\Controller\GraphQLController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Search\Controller\GraphQLController
 */
class GraphQLControllerStatusTest extends TestCase {
	private function statusFor(array $result): int {
		$class = new ReflectionClass(GraphQLController::class);

		return $class->getMethod('statusOf')->invoke($class->newInstanceWithoutConstructor(), $result);
	}//end statusFor()

	private function error(string $code): array {
		return ['message' => 'x', 'extensions' => ['code' => $code]];
	}//end error()

	public function testDataIsOk(): void {
		$this->assertSame(200, $this->statusFor(result: ['data' => ['a' => 1]]));
	}//end testDataIsOk()

	public function testDataWithAPartialErrorIsOk(): void {
		$this->assertSame(200, $this->statusFor(result: ['data' => ['a' => null], 'errors' => [$this->error(code: 'FORBIDDEN')]]));
	}//end testDataWithAPartialErrorIsOk()

	public function testErrorsWithoutDataAreABadRequest(): void {
		$this->assertSame(400, $this->statusFor(result: ['errors' => [$this->error(code: 'GRAPHQL_PARSE_FAILED')]]));
	}//end testErrorsWithoutDataAreABadRequest()

	public function testARefusedFieldIsABadRequestEvenWithData(): void {
		$this->assertSame(400, $this->statusFor(result: ['data' => ['a' => null], 'errors' => [$this->error(code: 'BAD_REQUEST')]]));
	}//end testARefusedFieldIsABadRequestEvenWithData()

	public function testARateLimitIsTooManyRequests(): void {
		$this->assertSame(429, $this->statusFor(result: ['errors' => [$this->error(code: 'RATE_LIMITED')]]));
	}//end testARateLimitIsTooManyRequests()

	public function testANothingResultIsOk(): void {
		$this->assertSame(200, $this->statusFor(result: []));
	}//end testANothingResultIsOk()
}//end class
