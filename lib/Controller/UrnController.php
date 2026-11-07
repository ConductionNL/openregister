<?php

/**
 * OpenRegister Urn Controller
 *
 * REST endpoints for URN resolution. Surfaces three operations:
 *   - GET /api/urn/resolve?urn=...  → URL for the URN
 *   - GET /api/urn/lookup?url=...   → URN for the URL
 *   - POST /api/urn/bulk            → batch resolve { urns: [...] }
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * URN resolution controller.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/urn-resource-addressing/spec.md
 */
class UrnController extends Controller {
	/**
	 * Error body for a URN that does not parse.
	 */
	private const MALFORMED_URN_ERROR = 'malformed URN; expected urn:' . UrnService::DEFAULT_NID . ':<instance>:<register>:<schema>:<uuid>';

	/**
	 * Maximum number of URNs accepted by one bulk() request.
	 *
	 * SECURITY: bulk() is reachable by every authenticated user (`@NoAdminRequired`),
	 * so this cap is mandatory. Each URN triggers a parse → register find → schema
	 * find → object find chain (~4 DB round-trips per URN), so an uncapped input list
	 * (e.g. 100k URNs ≈ 400k round-trips) is a direct DoS lever. Do not relax this cap
	 * without adding a per-user rate limit upstream.
	 */
	private const MAX_BULK_URNS = 1000;

	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param UrnService $urnService The URN resolution service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly UrnService $urnService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Resolve a URN to its canonical API URL.
	 *
	 * Returns 200 `{urn, url, instance, register, schema, uuid}` on success;
	 * 400 when the URN doesn't parse; 404 when the URN parses but the
	 * referenced register/schema doesn't exist on this instance.
	 *
	 * @param string|null $urn The URN to resolve.
	 *
	 * @return JSONResponse JSON response with the resolution result or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No object body: URN-to-URL address translation returning a canonical URL and parsed addressing parts, not object content.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-9
	 */
	public function resolve(?string $urn = null): JSONResponse {
		if ($urn === null || $urn === '') {
			return new JSONResponse(['error' => 'urn parameter is required'], Http::STATUS_BAD_REQUEST);
		}

		$parts = $this->urnService->parse($urn);
		if ($parts === null) {
			return new JSONResponse(['error' => self::MALFORMED_URN_ERROR], Http::STATUS_BAD_REQUEST);
		}

		return $this->resolvedResponse(urn: $urn, parts: $parts);
	}//end resolve()

	/**
	 * Build the response for a URN that parsed: 200 with its URL and parts, or 404.
	 *
	 * @param string $urn The parsed URN.
	 * @param array<string, mixed> $parts The addressing parts returned by UrnService::parse().
	 *
	 * @return JSONResponse The resolution result or a 404 when the URN does not resolve here.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-9
	 */
	private function resolvedResponse(string $urn, array $parts): JSONResponse {
		$url = $this->urnService->resolveUrl($urn);
		if ($url === null) {
			return new JSONResponse(['error' => 'URN does not resolve on this instance', 'urn' => $urn, 'parts' => $parts], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([
			'urn' => $urn,
			'url' => $url,
			'instance' => $parts['instance'],
			'register' => $parts['register'],
			'schema' => $parts['schema'],
			'uuid' => $parts['uuid'],
		]);
	}//end resolvedResponse()

	/**
	 * Reverse: derive the URN that addresses an OpenRegister object URL.
	 *
	 * @param string|null $url The URL to reverse-resolve.
	 *
	 * @return JSONResponse JSON response with the URN or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No object body: URL-to-URN address translation returning addressing parts, not object content.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-9
	 */
	public function lookup(?string $url = null): JSONResponse {
		if ($url === null || $url === '') {
			return new JSONResponse(['error' => 'url parameter is required'], Http::STATUS_BAD_REQUEST);
		}

		$urn = $this->urnService->urnFromUrl($url);
		if ($urn === null) {
			return new JSONResponse(['error' => 'URL is not an OpenRegister object reference', 'url' => $url], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['url' => $url, 'urn' => $urn]);
	}//end lookup()

	/**
	 * Batch URN resolution.
	 *
	 * Accepts JSON body `{urns: ["urn:nl-or:...", ...]}`. Returns a map
	 * of `urn → url-or-null`, preserving the input list order via the
	 * map keys.
	 *
	 * @param array|null $urns The list of URNs to resolve.
	 *
	 * @return JSONResponse JSON response with the bulk resolution result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt No object body: batch URN address translation (input capped at 1000 for DoS safety);
	 *   returns addressing parts, not object content.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-9
	 */
	public function bulk(?array $urns = null): JSONResponse {
		if (is_array($urns) === false || count($urns) === 0) {
			return new JSONResponse(['error' => 'urns array is required'], Http::STATUS_BAD_REQUEST);
		}

		// SECURITY: mandatory DoS cap, see MAX_BULK_URNS before relaxing it.
		if (count($urns) > self::MAX_BULK_URNS) {
			return new JSONResponse(['error' => 'Too many URNs (max 1000 per request)', 'count' => count($urns)], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$resolved = $this->urnService->resolveBulk($urns);

		return new JSONResponse(['count' => count($resolved), 'resolved' => $resolved]);
	}//end bulk()
}//end class
