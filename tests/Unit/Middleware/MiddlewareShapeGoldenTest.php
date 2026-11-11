<?php

/**
 * Golden test for the error-mapping, validation, version and language
 * middlewares: pins their responses, headers and log lines so a refactor of
 * their shape can be shown to leave them unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use Exception;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCA\OpenRegister\Middleware\Exception\ApiVersionRefusedException;
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Middleware\ObjectSourceErrorMiddleware;
use OCA\OpenRegister\Middleware\OasValidationFailureException;
use OCA\OpenRegister\Middleware\OasValidationMiddleware;
use OCA\OpenRegister\Middleware\UnknownMetadataFieldMiddleware;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Oas\ProblemDescription;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceException;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Responses, headers and log lines of the shape-only middlewares.
 */
final class MiddlewareShapeGoldenTest extends TestCase {

	public function testAWriteFailureRendersItsStatusAndLogsTheDriverError(): void {
		$controller = $this->createMock(Controller::class);
		$exception = new DbalWriteException('Duplicate key', 409);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ObjectSourceErrorMiddleware] external database write failed (409) in ' . $controller::class . '::create: Duplicate key',
			$this->callback(fn (array $context): bool => $context['exception'] === $exception && isset($context['file'], $context['line']))
		);
		$response = (new ObjectSourceErrorMiddleware($logger))->afterException($controller, 'create', $exception);
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'Duplicate key'], $response->getData());
	}

	public function testAReadFailureRendersItsStatusAndLogsTheDriverError(): void {
		$controller = $this->createMock(Controller::class);
		$exception = new DbalObjectSourceException('Unreachable', 503);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ObjectSourceErrorMiddleware] external database read failed (503) in ' . $controller::class . '::index: Unreachable',
			$this->callback(fn (array $context): bool => $context['exception'] === $exception && isset($context['file'], $context['line']))
		);
		$response = (new ObjectSourceErrorMiddleware($logger))->afterException($controller, 'index', $exception);
		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'Unreachable'], $response->getData());
	}

	public function testAnUnknownMetadataFieldIsA400NamingTheField(): void {
		$controller = $this->createMock(Controller::class);
		$exception = new UnknownMetadataFieldException('colour', ['_owner']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[UnknownMetadataFieldMiddleware] rejected unknown @self field "colour" in ' . $controller::class . '::index',
			$this->callback(fn (array $context): bool => isset($context['file'], $context['line']))
		);
		$response = (new UnknownMetadataFieldMiddleware($logger))->afterException($controller, 'index', $exception);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['error' => 'unknown-metadata-field', 'field' => 'colour', 'message' => $exception->getMessage()],
			$response->getData()
		);
	}

	public function testUnknownMetadataRethrowsAnythingElse(): void {
		$exception = new Exception('other');
		$this->expectExceptionObject($exception);
		(new UnknownMetadataFieldMiddleware($this->createMock(LoggerInterface::class)))
			->afterException($this->createMock(Controller::class), 'index', $exception);
	}

	/**
	 * An OAS middleware over a request.
	 *
	 * @param string               $verb      HTTP method.
	 * @param string|null          $validate  The `_validate` parameter.
	 * @param OasRequestValidator  $validator Validator double.
	 * @param LoggerInterface|null $logger    Logger double.
	 *
	 * @return OasValidationMiddleware
	 */
	private function oas(string $verb, ?string $validate, OasRequestValidator $validator, ?LoggerInterface $logger=null): OasValidationMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($verb);
		$request->method('getParam')->willReturnMap([['_validate', null, $validate]]);
		$request->method('getParams')->willReturn(['a' => 1]);
		$request->method('getRequestUri')->willReturn('/api/x');
		$problems = $this->createMock(ProblemDetailsBuilder::class);
		$problems->method('validationFailed')->willReturnCallback(
			fn (array $errors, ProblemDescription $description = new ProblemDescription()): array => ['errors' => $errors, 'detail' => $description->detail, 'instance' => $description->instance]
		);
		return new OasValidationMiddleware($request, $validator, $problems, $logger);
	}

	/**
	 * Requests the middleware never validates, and whether it looks for a schema.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: bool}>
	 */
	public static function oasRequests(): array {
		return [
			'get opted in' => ['GET', 'true', false],
			'post not opted in' => ['POST', null, false],
			'post opted out' => ['POST', 'no', false],
			'post opted in' => ['POST', 'true', true],
			'patch opted in upper' => ['patch', 'YES', true],
			'put opted in with 1' => ['PUT', '1', true],
		];
	}

	/**
	 * @dataProvider oasRequests
	 */
	public function testOasLooksForASchemaOnlyForOptedInWrites(string $verb, ?string $validate, bool $looks): void {
		$validator = $this->createMock(OasRequestValidator::class);
		$validator->expects($this->never())->method('validate');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($looks === true ? $this->once() : $this->never())->method('debug')
			->with($this->stringStartsWith('[OasValidationMiddleware] no per-operation schema resolver wired yet; skipping validation for '));
		$this->oas($verb, $validate, $validator, $logger)->beforeController($this->createMock(Controller::class), 'create');
	}

	public function testOasRendersAFailureAs422ProblemDetails(): void {
		$response = $this->oas('POST', 'true', $this->createMock(OasRequestValidator::class))
			->afterException(null, 'create', new OasValidationFailureException(['/a' => 'bad']));
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['errors' => ['/a' => 'bad'], 'detail' => 'Request body failed OAS schema validation', 'instance' => '/api/x'],
			$response->getData()
		);
		$this->assertSame(ProblemDetailsBuilder::CONTENT_TYPE, $response->getHeaders()['Content-Type']);
	}

	public function testOasRethrowsAnythingElse(): void {
		$exception = new RuntimeException('other');
		$this->expectExceptionObject($exception);
		$this->oas('POST', 'true', $this->createMock(OasRequestValidator::class))->afterException(null, 'create', $exception);
	}

	public function testAWithdrawnRefusalNamesItsSuccessor(): void {
		$version = new ApiVersion(id: '1', lifecycle: new ApiVersionLifecycle(status: ApiVersion::STATUS_WITHDRAWN, successor: '2'));
		$response = ApiVersionRefusedException::withdrawn($version, ['2'])->toResponse();
		$this->assertSame(410, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'API version 1 has been withdrawn. Use version 2.',
				'requestedVersion' => '1',
				'servedVersions' => ['2'],
				'successorVersion' => '2',
			],
			$response->getData()
		);
		$this->assertSame('</apps/openregister/api/versions/2/oas>; rel="successor-version"', $response->getHeaders()['Link']);
	}

	public function testAnUnknownRefusalHasNoSuccessor(): void {
		$response = ApiVersionRefusedException::unknown('9', ['1', '2'])->toResponse();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'API version "9" is not served by this instance. Served versions: 1, 2.',
				'requestedVersion' => '9',
				'servedVersions' => ['1', '2'],
			],
			$response->getData()
		);
		$this->assertArrayNotHasKey('Link', $response->getHeaders());
	}

	/**
	 * A language middleware over the given request parameters.
	 *
	 * @param array<string, mixed>       $params Request parameters.
	 * @param TranslationMapperInterface $mapper Mapper double.
	 * @param LoggerInterface|null       $logger Logger double.
	 *
	 * @return LanguageMiddleware
	 */
	private function language(array $params, TranslationMapperInterface $mapper, ?LoggerInterface $logger=null): LanguageMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default);
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');
		return new LanguageMiddleware($request, new LanguageService(), $mapper, $logger ?? $this->createMock(LoggerInterface::class));
	}

	/**
	 * Path parameters and the uuid the source language is looked up for (null: none).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function sourceLanguageRequests(): array {
		$uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		return [
			'id' => [['id' => $uuid], $uuid],
			'id wins over uuid' => [['id' => $uuid, 'uuid' => '11111111-2222'], $uuid],
			'uuid fallback' => [['uuid' => $uuid], $uuid],
			'empty id falls back' => [['id' => '', 'uuid' => $uuid], $uuid],
			'non-string id falls back' => [['id' => 5, 'uuid' => $uuid], $uuid],
			'not a uuid' => [['id' => 'my-slug!'], null],
			'too short' => [['id' => 'abc'], null],
			'nothing' => [[], null],
		];
	}

	/**
	 * @dataProvider sourceLanguageRequests
	 */
	public function testTheSourceLanguageHeaderIsDerivedForOneObject(array $params, ?string $uuid): void {
		$mapper = $this->createMock(TranslationMapperInterface::class);
		if ($uuid === null) {
			$mapper->expects($this->never())->method('getDominantSourceLanguage');
		} else {
			$mapper->expects($this->once())->method('getDominantSourceLanguage')->with($uuid)->willReturn('nl');
		}

		$response = $this->language($params, $mapper)->afterController(null, 'show', new Response());
		$headers = $response->getHeaders();
		$this->assertSame($uuid !== null, isset($headers['X-Source-Language']));
		$this->assertArrayHasKey('Content-Language', $headers);
	}

	public function testANullSourceLanguageAddsNoHeader(): void {
		$mapper = $this->createMock(TranslationMapperInterface::class);
		$mapper->method('getDominantSourceLanguage')->willReturn(null);
		$response = $this->language(['id' => 'aaaaaaaa-bbbb'], $mapper)->afterController(null, 'show', new Response());
		$this->assertArrayNotHasKey('X-Source-Language', $response->getHeaders());
	}

	public function testAFailingSourceLookupIsLoggedAndSkipped(): void {
		$mapper = $this->createMock(TranslationMapperInterface::class);
		$mapper->method('getDominantSourceLanguage')->willThrowException(new RuntimeException('db'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('[LanguageMiddleware] X-Source-Language derivation failed: db');
		$response = $this->language(['id' => 'aaaaaaaa-bbbb'], $mapper, $logger)->afterController(null, 'show', new Response());
		$this->assertArrayNotHasKey('X-Source-Language', $response->getHeaders());
	}

	/**
	 * Query overrides and the preferred language they lead to, plus how many warnings.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: int}>
	 */
	public static function queryOverrides(): array {
		return [
			'lang' => [['_lang' => ' de '], 'de', 0],
			'language alias' => [['language' => 'fr-BE'], 'fr-BE', 0],
			'invalid lang falls to alias' => [['_lang' => 'NOT VALID', 'language' => 'nl'], 'nl', 1],
			'both invalid' => [['_lang' => '1', 'language' => 'x'], 'nl', 2],
			'non-string skipped' => [['_lang' => ['de'], 'language' => 'it'], 'it', 0],
		];
	}

	/**
	 * @dataProvider queryOverrides
	 */
	public function testQueryOverridesPickTheFirstValidTag(array $params, string $preferred, int $warnings): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default);
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');
		$service = new LanguageService();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly($warnings))->method('warning')->with($this->stringStartsWith('[LanguageMiddleware] Invalid ?'));
		(new LanguageMiddleware($request, $service, $this->createMock(TranslationMapperInterface::class), $logger))->beforeController(null, 'index');
		$this->assertSame($preferred, $service->getPreferredLanguage());
	}
}
