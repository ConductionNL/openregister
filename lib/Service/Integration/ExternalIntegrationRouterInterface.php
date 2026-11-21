<?php

/**
 * ExternalIntegrationRouterInterface: the public surface of ExternalIntegrationRouter, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Exception\ProviderUnavailableException;

/**
 * Every public method of ExternalIntegrationRouter. Callers depend on this interface, not on the class.
 */
interface ExternalIntegrationRouterInterface {

	/**
	 * Dispatch a CRUD call against the provider's OpenConnector source.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 *                                      MUST declare storage='external'
	 *                                      and a non-null
	 *                                      getOpenConnectorSource().
	 * @param UpstreamRequest     $request  The HTTP method ('GET' / 'POST' / 'PUT' / 'PATCH' /
	 *                                      'DELETE'), the path relative to the source's base URL,
	 *                                      and the call options: query (query params), body
	 *                                      (scalar or array body), headers (extra request headers).
	 *
	 * @return array<string,mixed> The decoded response body.
	 *
	 * @throws ProviderUnavailableException When OpenConnector or the
	 *                                      upstream service is
	 *                                      unavailable. Use
	 *                                      `getCause()` /
	 *                                      `getDetails()` to pick
	 *                                      the right user message.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
	 */
	public function call(IntegrationProvider $provider, UpstreamRequest $request): array;

	/**
	 * Dispatch a call like {@see call()} but additionally surface the
	 * upstream response metadata (HTTP status, round-trip duration in
	 * milliseconds, and the response headers) alongside the decoded body.
	 *
	 * This is a general, foundation-safe superset of {@see call()}: any
	 * external leaf that needs to relay audit metadata to its consuming app
	 * (e.g. the BRP/HaalCentraal leaf, whose consumer persists the
	 * Wet-BRP-required `X-Correlation-ID` + response duration into its
	 * `brpLookupVerzoek` audit record) can route through this method instead
	 * of `call()`. `call()` is intentionally left untouched so existing
	 * leaves keep their lean body-only contract.
	 *
	 * The returned envelope is:
	 *   - `body` : the decoded upstream response body (identical to what
	 *              `call()` returns)
	 *   - `meta` : `{ status, durationMs, correlationId, headers }` — the
	 *              upstream HTTP status, the OpenConnector-measured round-trip
	 *              duration in milliseconds, the first `X-Correlation-ID`
	 *              response header (case-insensitive; null when absent), and a
	 *              flattened copy of the response headers.
	 *
	 * No request/response body or BSN is read into `meta` — only transport
	 * metadata. The same failure classification as `call()` applies.
	 *
	 * @param IntegrationProvider $provider The provider making the call.
	 * @param UpstreamRequest     $request  The HTTP method, the path relative to the source base URL,
	 *                                      and the call options (query/body/headers).
	 *
	 * @return array{body: array<string,mixed>, meta: array{status: int, durationMs: int, correlationId: ?string, headers: array<string,string>}}
	 *
	 * @throws ProviderUnavailableException When OpenConnector or the upstream
	 *                                      service is unavailable.
	 *
	 * @spec openspec/changes/integration-brp-audit-metadata/tasks.md
	 */
	public function callWithMeta(IntegrationProvider $provider, UpstreamRequest $request): array;

	/**
	 * The `configuration` array of the provider's connector source, so a
	 * provider can ask only what its source allows.
	 *
	 * @param IntegrationProvider $provider The provider whose source to read.
	 *
	 * @return array<string,mixed> The source's `configuration` (possibly empty).
	 *
	 * @throws ProviderUnavailableException When the connector or the source is missing.
	 *
	 * @spec openspec/specs/integration-person-lookup/spec.md#requirement-a-call-may-be-recorded-without-its-bodies
	 */
	public function sourceConfiguration(IntegrationProvider $provider): array;

	/**
	 * Cheap "is the connector reachable at all" check.
	 *
	 * Used by `IntegrationProvider::health()` implementations on
	 * external providers when admin UI / OCS capabilities request
	 * status. Never throws — returns a descriptor instead.
	 *
	 * @param IntegrationProvider $provider Provider to check.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
	 */
	public function probe(IntegrationProvider $provider): array;
}//end interface
