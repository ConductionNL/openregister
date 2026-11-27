<?php

/**
 * PersonalTokenGrantMiddleware: binds the calling token's grant before the
 * controller, and answers a call over the grant's limit with 429 naming it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Exception\TokenRateLimitExceededException;
use OCA\OpenRegister\Middleware\PersonalTokenGrantMiddleware;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PersonalTokenGrantMiddleware::class)]
#[UsesClass(TokenRateLimitExceededException::class)]
#[UsesClass(AuthenticationException::class)]
final class PersonalTokenGrantMiddlewareTest extends TestCase {

	public function testItBindsTheCallingTokensGrantBeforeTheController(): void {
		$binder = $this->createMock(PersonalTokenGrantBinder::class);
		$binder->expects($this->once())->method('bind')->with($this->callback(
			static fn (int $now): bool => abs($now - time()) <= 5
		));

		(new PersonalTokenGrantMiddleware($binder))->beforeController(new \stdClass(), 'index');
	}//end testItBindsTheCallingTokensGrantBeforeTheController()

	public function testARefusalFromTheBinderStopsTheController(): void {
		$binder = $this->createMock(PersonalTokenGrantBinder::class);
		$binder->method('bind')->willThrowException(new TokenRateLimitExceededException(limit: 5, window: 60, retryAfter: 12));

		$this->expectException(TokenRateLimitExceededException::class);
		(new PersonalTokenGrantMiddleware($binder))->beforeController(new \stdClass(), 'index');
	}//end testARefusalFromTheBinderStopsTheController()

	public function testACallOverTheLimitIsAnswered429NamingTheLimit(): void {
		$middleware = new PersonalTokenGrantMiddleware($this->createMock(PersonalTokenGrantBinder::class));

		$response = $middleware->afterException(new \stdClass(), 'index', new TokenRateLimitExceededException(limit: 5, window: 60, retryAfter: 12));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(429, $response->getStatus());
		$this->assertSame('12', $response->getHeaders()['Retry-After']);
		$this->assertSame('Rate limit exceeded: this token may make 5 calls per 60 seconds', $response->getData()['error']);
		$this->assertSame(5, $response->getData()['details']['limit']);
	}//end testACallOverTheLimitIsAnswered429NamingTheLimit()

	public function testEveryOtherExceptionIsRethrownUntouched(): void {
		$middleware = new PersonalTokenGrantMiddleware($this->createMock(PersonalTokenGrantBinder::class));
		$other      = new RuntimeException('not ours');

		try {
			$middleware->afterException(new \stdClass(), 'index', $other);
			$this->fail('A foreign exception was swallowed');
		} catch (RuntimeException $rethrown) {
			$this->assertSame($other, $rethrown);
		}
	}//end testEveryOtherExceptionIsRethrownUntouched()
}//end class
