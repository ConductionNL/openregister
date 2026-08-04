<?php

/**
 * LanguageMiddleware tests for the i18n-api-language-negotiation contract.
 *
 * Verifies query-parameter precedence over Accept-Language, the
 * `?language=` alias, invalid-tag fall-through, the default fallback,
 * and the write-side `X-Translation-Target-Language` stash.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware;

use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Service\LanguageService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies the resolution precedence + write-side header consumption.
 */
class LanguageMiddlewareTest extends TestCase
{

    /**
     * Build a middleware bound to a request mock that returns a fixed
     * set of params + headers.
     *
     * @param array<string, mixed> $params  Param map (param-name => value).
     * @param array<string, mixed> $headers Header map (header-name => value).
     * @param string               $method  HTTP method.
     */
    private function buildMiddleware(
        array $params,
        array $headers,
        string $method='GET'
    ): array {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $name, mixed $default=null) use ($params): mixed {
                return $params[$name] ?? $default;
            });
        $request->method('getHeader')
            ->willReturnCallback(function (string $name) use ($headers): string {
                return (string) ($headers[$name] ?? '');
            });
        $request->method('getMethod')->willReturn($method);

        $languageService   = new LanguageService();
        $translationMapper = $this->createMock(TranslationMapper::class);

        $middleware = new LanguageMiddleware(
            $request,
            $languageService,
            $translationMapper,
            new NullLogger()
        );

        return [$middleware, $languageService];
    }//end buildMiddleware()

    public function testQueryParameterOverridesAcceptLanguage(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: ['_lang' => 'fr'],
            headers: ['Accept-Language' => 'nl']
        );

        $middleware->beforeController($this, 'noop');

        $this->assertSame('fr', $languageService->getPreferredLanguage());
        $this->assertSame('query', $languageService->getRequestedLanguageSource());
    }//end testQueryParameterOverridesAcceptLanguage()

    public function testLanguageAliasResolvesIdenticallyToCanonical(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: ['language' => 'fr'],
            headers: []
        );

        $middleware->beforeController($this, 'noop');

        $this->assertSame('fr', $languageService->getPreferredLanguage());
        $this->assertSame('query', $languageService->getRequestedLanguageSource());
    }//end testLanguageAliasResolvesIdenticallyToCanonical()

    public function testCanonicalWinsOverAliasOnConflict(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: [
                '_lang'    => 'en',
                'language' => 'fr',
            ],
            headers: []
        );

        $middleware->beforeController($this, 'noop');

        $this->assertSame('en', $languageService->getPreferredLanguage());
    }//end testCanonicalWinsOverAliasOnConflict()

    public function testInvalidQueryFallsThroughToHeader(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: ['_lang' => 'GARBAGE_TAG'],
            headers: ['Accept-Language' => 'en']
        );

        $middleware->beforeController($this, 'noop');

        $this->assertSame('en', $languageService->getPreferredLanguage());
        $this->assertSame('header', $languageService->getRequestedLanguageSource());
    }//end testInvalidQueryFallsThroughToHeader()

    public function testDefaultFallbackWhenNothingSet(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: [],
            headers: []
        );

        $middleware->beforeController($this, 'noop');

        // Defaults to register fallback ('nl' on construction).
        $this->assertSame('nl', $languageService->getPreferredLanguage());
        $this->assertSame('default', $languageService->getRequestedLanguageSource());
    }//end testDefaultFallbackWhenNothingSet()

    public function testTargetLanguageHeaderStashedOnPatch(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: [],
            headers: ['X-Translation-Target-Language' => 'en'],
            method: 'PATCH'
        );

        $middleware->beforeController($this, 'noop');

        $this->assertSame('en', $languageService->getTargetLanguage());
    }//end testTargetLanguageHeaderStashedOnPatch()

    public function testTargetLanguageHeaderIgnoredOnGet(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: [],
            headers: ['X-Translation-Target-Language' => 'en'],
            method: 'GET'
        );

        $middleware->beforeController($this, 'noop');

        $this->assertNull($languageService->getTargetLanguage());
    }//end testTargetLanguageHeaderIgnoredOnGet()

    public function testInvalidTargetLanguageHeaderIgnored(): void
    {
        [$middleware, $languageService] = $this->buildMiddleware(
            params: [],
            headers: ['X-Translation-Target-Language' => 'GARBAGE'],
            method: 'POST'
        );

        $middleware->beforeController($this, 'noop');

        $this->assertNull($languageService->getTargetLanguage());
    }//end testInvalidTargetLanguageHeaderIgnored()
}//end class
