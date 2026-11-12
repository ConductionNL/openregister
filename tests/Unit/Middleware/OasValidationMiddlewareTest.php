<?php

/**
 * OasValidationMiddlewareTest — which writes are validated, and how a failure is rendered.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Middleware\OasValidationFailureException;
use OCA\OpenRegister\Middleware\OasValidationMiddleware;
use OCA\OpenRegister\Service\Oas\OasRequestValidatorInterface;
use OCA\OpenRegister\Service\Oas\ProblemDescription;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilderInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Middleware\OasValidationMiddleware
 * @uses   \OCA\OpenRegister\Middleware\OasValidationFailureException
 */
class OasValidationMiddlewareTest extends TestCase {

	/**
	 * A request with the given verb and `_validate` parameter.
	 *
	 * @param string      $verb     HTTP verb.
	 * @param string|null $validate The `_validate` value.
	 *
	 * @return IRequest
	 */
	private function request(string $verb, ?string $validate): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($key === '_validate' ? $validate : null)
		);
		$request->method('getRequestUri')->willReturn('/api/objects/1');
		return $request;
	}

	public function testAnOptedInWriteConsultsTheSchemaResolver(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with($this->stringContains('skipping validation for stdClass::create'), $this->anything());
		$validator = $this->createMock(OasRequestValidatorInterface::class);
		$validator->expects($this->never())->method('validate');

		$middleware = new OasValidationMiddleware(
			$this->request('post', 'YES'),
			$validator,
			$this->createMock(ProblemDetailsBuilderInterface::class),
			$logger
		);
		$middleware->beforeController(new stdClass(), 'create');
	}

	public function testANotOptedInWriteSkipsTheResolver(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');

		$middleware = new OasValidationMiddleware(
			$this->request('POST', 'false'),
			$this->createMock(OasRequestValidatorInterface::class),
			$this->createMock(ProblemDetailsBuilderInterface::class),
			$logger
		);
		$middleware->beforeController(new stdClass(), 'create');
	}

	public function testAReadIsNeverValidatedEvenWhenAsked(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');

		$middleware = new OasValidationMiddleware(
			$this->request('GET', 'true'),
			$this->createMock(OasRequestValidatorInterface::class),
			$this->createMock(ProblemDetailsBuilderInterface::class),
			$logger
		);
		$middleware->beforeController(new stdClass(), 'index');
	}

	public function testAValidationFailureBecomesA422ProblemResponse(): void {
		$errors = [['pointer' => '/title', 'message' => 'required']];
		$problems = $this->createMock(ProblemDetailsBuilderInterface::class);
		$problems->expects($this->once())->method('validationFailed')
			->with($errors, new ProblemDescription(detail: 'Request body failed OAS schema validation', instance: '/api/objects/1'))
			->willReturn(['title' => 'Validation failed']);

		$middleware = new OasValidationMiddleware(
			$this->request('POST', 'true'),
			$this->createMock(OasRequestValidatorInterface::class),
			$problems
		);
		$response = $middleware->afterException(new stdClass(), 'create', new OasValidationFailureException($errors));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['title' => 'Validation failed'], $response->getData());
		$this->assertSame('application/problem+json', $response->getHeaders()['Content-Type']);
	}

	public function testAnyOtherExceptionIsRethrownUntouched(): void {
		$middleware = new OasValidationMiddleware(
			$this->request('POST', 'true'),
			$this->createMock(OasRequestValidatorInterface::class),
			$this->createMock(ProblemDetailsBuilderInterface::class)
		);
		$original = new RuntimeException('boom');

		try {
			$middleware->afterException(new stdClass(), 'create', $original);
			$this->fail('expected rethrow');
		} catch (RuntimeException $e) {
			$this->assertSame($original, $e);
		}
	}
}
