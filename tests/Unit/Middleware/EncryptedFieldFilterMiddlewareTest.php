<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Exception\EncryptedFieldSortException;
use OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware
 * @covers \OCA\OpenRegister\Exception\EncryptedFieldFilterException
 * @covers \OCA\OpenRegister\Exception\EncryptedFieldSortException
 */
class EncryptedFieldFilterMiddlewareTest extends TestCase {
	private function after(Exception $exception): JSONResponse {
		$middleware = new EncryptedFieldFilterMiddleware($this->createMock(LoggerInterface::class));

		return $middleware->afterException($this->createMock(Controller::class), 'index', $exception);
	}//end after()

	public function testARefusedFilterIsABadRequest(): void {
		$response = $this->after(exception: new EncryptedFieldFilterException(property: 'bsn'));

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'encrypted-field-not-filterable',
				'property' => 'bsn',
				'message' => 'Property "bsn" is encrypted at rest and cannot be filtered, searched or faceted server-side.',
			],
			$response->getData()
		);
	}//end testARefusedFilterIsABadRequest()

	public function testARefusedSortIsABadRequest(): void {
		$response = $this->after(exception: new EncryptedFieldSortException(property: 'bsn'));

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'encrypted-field-not-sortable',
				'property' => 'bsn',
				'message' => 'Property "bsn" is encrypted at rest and cannot be sorted on.',
			],
			$response->getData()
		);
	}//end testARefusedSortIsABadRequest()

	public function testAnyOtherExceptionPassesThrough(): void {
		$this->expectException(RuntimeException::class);

		$this->after(exception: new RuntimeException('other'));
	}//end testAnyOtherExceptionPassesThrough()
}//end class
