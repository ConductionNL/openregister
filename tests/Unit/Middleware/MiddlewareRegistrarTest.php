<?php

/**
 * The middleware registrar registers the middlewares in the order Nextcloud runs them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\MiddlewareRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class MiddlewareRegistrarTest extends TestCase {

	/**
	 * register() registers every middleware, in this order, and no service.
	 *
	 * @return void
	 */
	public function testRegistersTheMiddlewaresInOrder(): void {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);
		$context->expects($this->never())->method('registerService');

		MiddlewareRegistrar::register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Middleware\LanguageMiddleware',
				'OCA\OpenRegister\Middleware\TenantQuotaMiddleware',
				'OCA\OpenRegister\Middleware\OasValidationMiddleware',
				'OCA\OpenRegister\Middleware\RevealAuditMiddleware',
				'OCA\OpenRegister\Middleware\RateLimitMiddleware',
				'OCA\OpenRegister\Middleware\PublicApiCorsMiddleware',
				'OCA\OpenRegister\Middleware\ChatCompatMiddleware',
				'OCA\OpenRegister\Middleware\ObjectSourceErrorMiddleware',
				'OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware',
				'OCA\OpenRegister\Middleware\UnknownMetadataFieldMiddleware',
				'OCA\OpenRegister\Middleware\RelatedRowFilterMiddleware',
				'OCA\OpenRegister\Middleware\MaintenanceModeMiddleware',
				'OCA\OpenRegister\Middleware\ApiVersionMiddleware',
				'OCA\OpenRegister\Middleware\ApiCallerMiddleware',
			],
			actual: $registered
		);
	}//end testRegistersTheMiddlewaresInOrder()
}//end class
