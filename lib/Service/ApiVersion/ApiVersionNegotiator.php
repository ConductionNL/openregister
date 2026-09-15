<?php

/**
 * Reads a request for the contract version its sender speaks.
 *
 * WHY THERE ARE THREE WAYS TO SAY IT, AND WHY THEY ARE ORDERED.
 *
 *  1. `API-Version: 2`. The one we document, because it is the one a
 *     generated client, a curl line and a proxy rule can all set without
 *     understanding media types.
 *  2. `Accept: application/json; version=2`. The standards-correct spelling,
 *     accepted because integrators who already do content negotiation should
 *     not have to learn a second mechanism for this instance.
 *  3. `/api/v2/...` in the path. Accepted because this app already serves
 *     `/api/mcp/v1/...` that way, and a path that says `v1` while a header
 *     says nothing must not silently resolve to something else.
 *
 * The header wins over Accept and Accept over the path, so a caller that sets
 * two of them gets the most specific one it set rather than an answer that
 * depends on parse order.
 *
 * 🔴 AN UNPARSEABLE VERSION IS NOT THE DEFAULT. `API-Version: two` resolves to
 * the requested id `two`, which nothing declares, which the middleware refuses
 * with 400. Reading it as "named nothing" would hand a typo the current
 * contract and let a client believe for a year that it was pinned.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

use OCP\IRequest;

/**
 * Resolves the version a request speaks, against the declared catalogue.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 */
class ApiVersionNegotiator {

	/**
	 * The request header a consumer sets to name the contract it speaks.
	 *
	 * @var string
	 */
	public const REQUEST_HEADER = 'API-Version';

	/**
	 * The response header naming the contract that answered.
	 *
	 * Sent on every answer, including one the caller did not ask a version
	 * for, because a consumer that can read which contract served it can
	 * notice a default moving under it before its parser does.
	 *
	 * @var string
	 */
	public const RESPONSE_HEADER = 'API-Version';

	/**
	 * Constructor.
	 *
	 * @param ApiVersionCatalogue $catalogue The declared versions.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApiVersionCatalogue $catalogue,
	) {

	}//end __construct()

	/**
	 * Resolve the version a request speaks.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return ApiVersionNegotiation What was asked for, and what answers.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function negotiate(IRequest $request): ApiVersionNegotiation {
		$named = $this->readRequestedIdentifier(request: $request);
		if ($named === null) {
			return new ApiVersionNegotiation(
				requestedId: null,
				version: $this->catalogue->current(),
				source: ApiVersionNegotiation::SOURCE_DEFAULT,
			);
		}

		[$identifier, $source] = $named;

		return new ApiVersionNegotiation(
			requestedId: $identifier,
			version: $this->catalogue->get(identifier: $identifier),
			source: $source,
		);

	}//end negotiate()

	/**
	 * The identifiers this instance would accept, for a refusal message.
	 *
	 * @return array<int, string> The served identifiers, lowest first.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function acceptableIdentifiers(): array {
		return array_keys($this->catalogue->served());

	}//end acceptableIdentifiers()

	/**
	 * Read the version identifier a request names, and where it named it.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return array{0: string, 1: string}|null The identifier and its source, or null.
	 */
	private function readRequestedIdentifier(IRequest $request): ?array {
		$header = trim((string)$request->getHeader(self::REQUEST_HEADER));
		if ($header !== '') {
			return [$this->stripPrefix(value: $header), ApiVersionNegotiation::SOURCE_HEADER];
		}

		$fromAccept = $this->readAcceptParameter(request: $request);
		if ($fromAccept !== null) {
			return [$fromAccept, ApiVersionNegotiation::SOURCE_ACCEPT];
		}

		$fromPath = $this->readPathSegment(request: $request);
		if ($fromPath !== null) {
			return [$fromPath, ApiVersionNegotiation::SOURCE_PATH];
		}

		return null;

	}//end readRequestedIdentifier()

	/**
	 * Read a `version=` parameter off the Accept header.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return string|null The identifier, or null when Accept names none.
	 */
	private function readAcceptParameter(IRequest $request): ?string {
		$accept = trim((string)$request->getHeader('Accept'));
		if ($accept === '') {
			return null;
		}

		$matched = [];
		if (preg_match('/;\s*version\s*=\s*"?([^;,"\s]+)"?/i', $accept, $matched) !== 1) {
			return null;
		}

		return $this->stripPrefix(value: $matched[1]);

	}//end readAcceptParameter()

	/**
	 * Read a `/v2/` segment out of the request path.
	 *
	 * Only a segment immediately after `/api/` or after `/api/<family>/`
	 * counts, so an object whose own path happens to contain `v2` is never
	 * mistaken for a version declaration.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return string|null The identifier, or null when the path names none.
	 */
	private function readPathSegment(IRequest $request): ?string {
		$path = $request->getPathInfo();
		if (is_string($path) === false || $path === '') {
			return null;
		}

		$matched = [];
		if (preg_match('#/api/(?:[a-z0-9-]+/)?v([0-9]{1,3})(?:/|$)#i', $path, $matched) !== 1) {
			return null;
		}

		return $matched[1];

	}//end readPathSegment()

	/**
	 * Drop a leading `v` so `v2` and `2` name the same contract.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The identifier as declared.
	 */
	private function stripPrefix(string $value): string {
		$trimmed = trim($value);
		if (preg_match('/^v([0-9]{1,3})$/i', $trimmed, $matched) === 1) {
			return $matched[1];
		}

		return $trimmed;

	}//end stripPrefix()
}//end class
