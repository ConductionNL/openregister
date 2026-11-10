<?php

/**
 * OpenRegister Language Middleware
 *
 * Middleware that intercepts all requests to resolve the request's
 * preferred language. Honours, in priority order:
 *   1. `?_lang=<bcp47>`   query parameter (canonical override)
 *   2. `?language=<bcp47>` query parameter (alias)
 *   3. `Accept-Language` header (RFC 9110)
 *   4. Register default (resolved at render time)
 *   5. Hardcoded `'nl'` fallback
 *
 * Also reads the write-side `X-Translation-Target-Language` header on
 * POST/PUT/PATCH and stashes it on the LanguageService for the
 * TranslationHandler to consume during body normalisation.
 *
 * Always emits `Content-Language` + `X-Content-Language-Fallback` on
 * responses; emits `X-Source-Language` when the response carries object
 * content for which a dominant source language can be derived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\LanguageServiceInterface;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Middleware that resolves the request's preferred language and the
 * write-side target language, emitting language headers on responses.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */
class LanguageMiddleware extends Middleware {

	/**
	 * Basic BCP-47 syntax check used to discard malformed query overrides.
	 *
	 * Mirrors the pattern declared in
	 * `specs/i18n-api-language-negotiation/spec.md`. Lax by design — we
	 * never 400 on a malformed tag; we just fall through.
	 *
	 * @var string
	 */
	private const BCP47_PATTERN = '/^[a-z]{2,3}(-[a-zA-Z0-9]{2,8})*$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request.
	 * @param LanguageServiceInterface $languageService The request-scoped language service.
	 * @param TranslationMapperInterface $translationMapper Mapper for source-language lookups (response side).
	 * @param LoggerInterface $logger Logger for invalid-tag warnings.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly LanguageServiceInterface $languageService,
		private readonly TranslationMapperInterface $translationMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the preferred language and the write-side target language
	 * from the incoming request.
	 *
	 * @param mixed $controller The controller instance.
	 * @param string $methodName The method name being called.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	public function beforeController($controller, $methodName): void {
		// Query-parameter overrides take precedence over Accept-Language.
		$resolvedFromQuery = $this->resolveFromQueryParams();
		if ($resolvedFromQuery !== null) {
			$this->setPreferredLanguage(language: $resolvedFromQuery, source: 'query');
		}

		$this->applyAcceptLanguage(queryResolved: $resolvedFromQuery !== null);
		if ($this->request->getParam('_translations') === 'all') {
			$this->languageService->setReturnAllTranslations(true);
		}

		$this->applyTargetLanguage();
	}//end beforeController()

	/**
	 * Record the preferred language and where it came from.
	 *
	 * @param string $language The language code.
	 * @param string $source   Where it came from: `query` or `header`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	private function setPreferredLanguage(string $language, string $source): void {
		$this->languageService->setPreferredLanguage($language);
		$this->languageService->setRequestedLanguageSource($source);
	}//end setPreferredLanguage()

	/**
	 * Read the Accept-Language header into the accepted languages, and into the
	 * preferred language when no query parameter already chose one.
	 *
	 * @param bool $queryResolved Whether a query parameter already set the preferred language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	private function applyAcceptLanguage(bool $queryResolved): void {
		$acceptLanguage = $this->request->getHeader('Accept-Language');
		if ($acceptLanguage === '') {
			return;
		}

		$acceptedLanguages = $this->languageService::parseAcceptLanguageHeader($acceptLanguage);
		$this->languageService->setAcceptedLanguages($acceptedLanguages);

		if (empty($acceptedLanguages) === false && $queryResolved === false) {
			$preferred = strtolower(explode('-', $acceptedLanguages[0])[0]);
			$this->setPreferredLanguage(language: $preferred, source: 'header');
		}
	}//end applyAcceptLanguage()

	/**
	 * Set the write-side target language from X-Translation-Target-Language on a
	 * POST, PUT or PATCH; an invalid value is logged and ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-1
	 */
	private function applyTargetLanguage(): void {
		$method       = strtoupper((string)$this->request->getMethod());
		$targetHeader = $this->request->getHeader('X-Translation-Target-Language');
		if (in_array($method, ['POST', 'PUT', 'PATCH'], true) === false || $targetHeader === '') {
			return;
		}

		$targetTrim = trim($targetHeader);
		if (preg_match(self::BCP47_PATTERN, $targetTrim) === 1) {
			$this->languageService->setTargetLanguage($targetTrim);
			return;
		}

		$this->logger->warning(sprintf('[LanguageMiddleware] Invalid X-Translation-Target-Language "%s" — ignoring.', $targetTrim));
	}//end applyTargetLanguage()

	/**
	 * Emit language response headers.
	 *
	 * @param mixed $controller The controller instance.
	 * @param string $methodName The method name that was called.
	 * @param Response $response The response object.
	 *
	 * @return Response The modified response with language headers.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		$language = $this->languageService->getPreferredLanguage();
		$response->addHeader('Content-Language', $language);

		if ($this->languageService->isFallbackUsed() === true) {
			$response->addHeader('X-Content-Language-Fallback', 'true');
		}

		$this->addSourceLanguage(response: $response);

		return $response;
	}//end afterController()

	/**
	 * Add the X-Source-Language header when the request targets one object.
	 *
	 * I18n-source-of-truth: surface the dominant source language for the
	 * response payload. We can only derive this when the request targets a
	 * single object (the path carries a uuid). Listing / multi-object
	 * endpoints skip the header. A failing lookup is logged and skipped.
	 *
	 * @param Response $response The response object.
	 *
	 * @return void
	 */
	private function addSourceLanguage(Response $response): void {
		try {
			$sourceLanguage = $this->resolveSourceLanguageForResponse();
			if ($sourceLanguage !== null) {
				$response->addHeader('X-Source-Language', $sourceLanguage);
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'[LanguageMiddleware] X-Source-Language derivation failed: ' . $e->getMessage()
			);
		}
	}//end addSourceLanguage()

	/**
	 * Resolve a language from `?_lang=` or `?language=`, in that order.
	 *
	 * Returns null when neither is set, or when neither value passes
	 * basic BCP-47 syntax validation. Invalid tags log a warning and
	 * cause the lookup to fall through to the next priority level —
	 * we never 400 on a malformed language tag.
	 *
	 * @return string|null The resolved tag, or null when no valid query override is present.
	 */
	private function resolveFromQueryParams(): ?string {
		foreach (['_lang', 'language'] as $name) {
			$tag = $this->validQueryTag(name: $name);
			if ($tag !== null) {
				return $tag;
			}
		}

		return null;
	}//end resolveFromQueryParams()

	/**
	 * The trimmed value of one language query parameter, when it is a valid tag.
	 *
	 * An invalid tag logs a warning and yields null, so the lookup falls
	 * through to the next priority level.
	 *
	 * @param string $name The query parameter.
	 *
	 * @return string|null The tag, or null when absent, not a string or malformed.
	 */
	private function validQueryTag(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$trimmed = trim($value);
		if (preg_match(self::BCP47_PATTERN, $trimmed) === 1) {
			return $trimmed;
		}

		$this->logger->warning(sprintf("[LanguageMiddleware] Invalid ?%s value '%s' — falling through", $name, $trimmed));
		return null;
	}//end validQueryTag()

	/**
	 * Attempt to resolve the canonical source-language for the response.
	 *
	 * Uses the request path's `uuid` segment when present to look up the
	 * dominant source language across the object's translation rows.
	 *
	 * @return string|null Dominant source language, or null when not resolvable.
	 */
	private function resolveSourceLanguageForResponse(): ?string {
		// The canonical object read/write routes bind the identifier to the
		// `{id}` path segment (objects#show / #update / #patch); only a few
		// secondary routes name it `{uuid}`. Read `id` first and fall back to
		// `uuid` so the header is derived on the primary object endpoints —
		// previously only `uuid` was read, so X-Source-Language was never
		// emitted on the main object route (i18n-source-of-truth).
		$uuid = $this->requestedObjectId();
		if ($uuid === null || preg_match('/^[a-fA-F0-9\-]{8,}$/', $uuid) !== 1) {
			return null;
		}

		return $this->translationMapper->getDominantSourceLanguage($uuid);
	}//end resolveSourceLanguageForResponse()

	/**
	 * The object identifier the request path names: `id` first, then `uuid`.
	 *
	 * @return string|null The identifier, or null when neither is a non-empty string.
	 */
	private function requestedObjectId(): ?string {
		foreach (['id', 'uuid'] as $name) {
			$value = $this->request->getParam($name);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end requestedObjectId()
}//end class
