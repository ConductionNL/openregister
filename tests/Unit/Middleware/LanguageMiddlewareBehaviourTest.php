<?php

/**
 * LanguageMiddlewareBehaviourTest — response headers and the all-translations switch.
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

use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Middleware\LanguageMiddleware
 */
class LanguageMiddlewareBehaviourTest extends TestCase {

	private LanguageServiceInterface&MockObject $language;

	private TranslationMapperInterface&MockObject $mapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->language = $this->createMock(LanguageServiceInterface::class);
		$this->mapper = $this->createMock(TranslationMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function middleware(array $params): LanguageMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
		);
		$request->method('getHeader')->willReturn('');
		$request->method('getMethod')->willReturn('GET');

		return new LanguageMiddleware($request, $this->language, $this->mapper, $this->logger);
	}

	public function testTranslationsAllSwitchesOnReturnAll(): void {
		$this->language->expects($this->once())->method('setReturnAllTranslations')->with(true);

		$this->middleware(['_translations' => 'all'])->beforeController(null, 'show');
	}

	public function testAnyOtherTranslationsValueLeavesReturnAllOff(): void {
		$this->language->expects($this->never())->method('setReturnAllTranslations');

		$this->middleware(['_translations' => 'nl'])->beforeController(null, 'show');
	}

	public function testResponseCarriesLanguageFallbackAndSourceHeaders(): void {
		$this->language->method('getPreferredLanguage')->willReturn('nl');
		$this->language->method('isFallbackUsed')->willReturn(true);
		$this->mapper->expects($this->once())->method('getDominantSourceLanguage')
			->with('abcdef12-3456')->willReturn('en');

		$headers = $this->middleware(['uuid' => 'abcdef12-3456'])->afterController(null, 'show', new Response())->getHeaders();

		$this->assertSame('nl', $headers['Content-Language']);
		$this->assertSame('true', $headers['X-Content-Language-Fallback']);
		$this->assertSame('en', $headers['X-Source-Language']);
	}

	public function testNoFallbackAndNoKnownSourceAddNoExtraHeaders(): void {
		$this->language->method('getPreferredLanguage')->willReturn('de');
		$this->language->method('isFallbackUsed')->willReturn(false);
		$this->mapper->method('getDominantSourceLanguage')->willReturn(null);

		$headers = $this->middleware(['id' => 'abcdef1234'])->afterController(null, 'show', new Response())->getHeaders();

		$this->assertSame('de', $headers['Content-Language']);
		$this->assertArrayNotHasKey('X-Content-Language-Fallback', $headers);
		$this->assertArrayNotHasKey('X-Source-Language', $headers);
	}

	public function testANonUuidIdNeverReachesTheMapper(): void {
		$this->language->method('getPreferredLanguage')->willReturn('en');
		$this->mapper->expects($this->never())->method('getDominantSourceLanguage');

		$headers = $this->middleware(['id' => 'my-slug'])->afterController(null, 'show', new Response())->getHeaders();

		$this->assertArrayNotHasKey('X-Source-Language', $headers);
	}

	public function testAMapperFailureIsLoggedAndTheResponseStillReturned(): void {
		$this->language->method('getPreferredLanguage')->willReturn('en');
		$this->mapper->method('getDominantSourceLanguage')->willThrowException(new RuntimeException('no table'));
		$this->logger->expects($this->once())->method('debug')
			->with('[LanguageMiddleware] X-Source-Language derivation failed: no table');
		$response = new Response();

		$this->assertSame($response, $this->middleware(['id' => 'abcdef1234'])->afterController(null, 'show', $response));
		$this->assertArrayNotHasKey('X-Source-Language', $response->getHeaders());
	}
}
