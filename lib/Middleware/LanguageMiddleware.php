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

use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\LanguageService;
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
	 * @param LanguageService $languageService The request-scoped language service.
	 * @param TranslationMapper $translationMapper Mapper for source-language lookups (response side).
	 * @param LoggerInterface $logger Logger for invalid-tag warnings.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly LanguageService $languageService,
		private readonly TranslationMapper $translationMapper,
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
		// 1. Query-parameter overrides take precedence over Accept-Language.
		$resolvedFromQuery = $this->resolveFromQueryParams();
		if ($resolvedFromQuery !== null) {
			$this->languageService->setPreferredLanguage($resolvedFromQuery);
			$this->languageService->setRequestedLanguageSource('query');
		}

		// 2. Accept-Language header is the next priority.
		$acceptLanguage = $this->request->getHeader('Accept-Language');
		if ($acceptLanguage !== '' && $acceptLanguage !== null) {
			$acceptedLanguages = LanguageService::parseAcceptLanguageHeader($acceptLanguage);
			$this->languageService->setAcceptedLanguages($acceptedLanguages);

			if (empty($acceptedLanguages) === false && $resolvedFromQuery === null) {
				$preferred = strtolower(explode('-', $acceptedLanguages[0])[0]);
				$this->languageService->setPreferredLanguage($preferred);
				$this->languageService->setRequestedLanguageSource('header');
			}
		}

		// 3. _translations=all (existing).
		$translations = $this->request->getParam('_translations');
		if ($translations === 'all') {
			$this->languageService->setReturnAllTranslations(true);
		}

		// 4. Write-side X-Translation-Target-Language on mutating verbs.
		$method = strtoupper((string)$this->request->getMethod());
		if (in_array($method, ['POST', 'PUT', 'PATCH'], true) === true) {
			$targetHeader = $this->request->getHeader('X-Translation-Target-Language');
			if ($targetHeader !== '' && $targetHeader !== null) {
				$targetTrim = trim($targetHeader);
				if (preg_match(self::BCP47_PATTERN, $targetTrim) === 1) {
					$this->languageService->setTargetLanguage($targetTrim);
				} else {
					$this->logger->warning(
						sprintf(
							'[LanguageMiddleware] Invalid X-Translation-Target-Language "%s" — ignoring.',
							$targetTrim
						)
					);
				}
			}
		}
	}//end beforeController()

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

		// I18n-source-of-truth: surface the dominant source language for
		// the response payload. We can only derive this when the request
		// targets a single object (the path carries a uuid). Listing /
		// multi-object endpoints skip the header.
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

		return $response;
	}//end afterController()

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
			$value = $this->request->getParam($name);
			if ($value === null || $value === '') {
				continue;
			}

			if (is_string($value) === false) {
				continue;
			}

			$trimmed = trim($value);
			if (preg_match(self::BCP47_PATTERN, $trimmed) !== 1) {
				$this->logger->warning(
					sprintf(
						"[LanguageMiddleware] Invalid ?%s value '%s' — falling through",
						$name,
						$trimmed
					)
				);
				continue;
			}

			return $trimmed;
		}//end foreach

		return null;
	}//end resolveFromQueryParams()

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
		$uuid = $this->request->getParam('id');
		if (is_string($uuid) === false || $uuid === '') {
			$uuid = $this->request->getParam('uuid');
		}

		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		if (preg_match('/^[a-fA-F0-9\-]{8,}$/', $uuid) !== 1) {
			return null;
		}

		return $this->translationMapper->getDominantSourceLanguage($uuid);
	}//end resolveSourceLanguageForResponse()
}//end class
