<?php

/**
 * ConnectorEntityCallsTrait: get, link, update and unlink one external entity
 * through the connector source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;

/**
 * Entity calls shared by the connector-backed providers that link external entities.
 *
 * The using provider provides `$this->router` (ExternalIntegrationRouterInterface),
 * `normalizeRow(array $row): array` and `normalizeList(array $response): array`.
 */
trait ConnectorEntityCallsTrait {

	/**
	 * List the external entities the connector source returns for a context query.
	 *
	 * @param array<string,mixed> $query The `{register, schema, object, …filters}` query.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listFromSource(array $query): array {
		$response = $this->router->call(
			provider: $this,
			request: new UpstreamRequest(
				method: 'GET',
				path: '',
				options: ['query' => $query, 'headers' => $this->requestHeaders()]
			)
		);

		return $this->normalizeList(response: $response);
	}//end listFromSource()

	/**
	 * Fetch one linked external entity.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId External entity id.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function get(ObjectRef $ref, string $entityId): array {
		$query = $this->contextQuery(ref: $ref, filters: []);

		return $this->entityRow(request: new UpstreamRequest(method: 'GET', path: rawurlencode($entityId), options: ['query' => $query]));
	}//end get()

	/**
	 * Link (or create) an external entity against an OR object.
	 *
	 * @param ObjectRef              $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $payload  Reference or new-entity fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function create(ObjectRef $ref, array $payload): array {
		$body = $this->contextQuery(ref: $ref, filters: $payload);

		return $this->entityRow(request: new UpstreamRequest(method: 'POST', path: '', options: ['body' => $body]));
	}//end create()

	/**
	 * Update a linked entity pairing.
	 *
	 * @param ObjectRef              $ref The owning object: register, schema and uuid.
	 * @param string              $entityId External entity id.
	 * @param array<string,mixed> $payload  Fields to update.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function update(ObjectRef $ref, string $entityId, array $payload): array {
		$body = $this->contextQuery(ref: $ref, filters: $payload);

		return $this->entityRow(request: new UpstreamRequest(method: 'PUT', path: rawurlencode($entityId), options: ['body' => $body]));
	}//end update()

	/**
	 * Unlink an external entity. The entity itself stays in the external system.
	 *
	 * @param ObjectRef $ref The owning object: register, schema and uuid.
	 * @param string $entityId External entity id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function delete(ObjectRef $ref, string $entityId): void {
		$query = $this->contextQuery(ref: $ref, filters: []);
		$this->callEntity(request: new UpstreamRequest(method: 'DELETE', path: rawurlencode($entityId), options: ['query' => $query]));
	}//end delete()

	/**
	 * One entity call, its answer normalised to a row.
	 *
	 * @param UpstreamRequest $request The method, the entity path and the query or body.
	 *
	 * @return array<string,mixed>
	 */
	private function entityRow(UpstreamRequest $request): array {
		return $this->normalizeRow(row: $this->callEntity(request: $request));
	}//end entityRow()

	/**
	 * Make one entity call with the standard headers: JSON responses, and a JSON body on writes.
	 *
	 * @param UpstreamRequest $request The method, the entity path and the query or body.
	 *
	 * @return array<string,mixed> The decoded response.
	 */
	private function callEntity(UpstreamRequest $request): array {
		$options = $request->options;
		$options['headers'] = $this->requestHeaders(withBody: array_key_exists('body', $options));

		return $this->router->call(provider: $this, request: new UpstreamRequest(method: $request->method, path: $request->path, options: $options));
	}//end callEntity()

	/**
	 * The standard `{register, schema, object, …filters}` context, used as the
	 * query of reads and as the body of writes.
	 *
	 * @param ObjectRef           $ref     The owning object.
	 * @param array<string,mixed> $filters Caller filters or payload merged in.
	 *
	 * @return array<string,mixed>
	 */
	private function contextQuery(ObjectRef $ref, array $filters): array {
		return array_merge(
			$filters,
			['register' => $ref->register, 'schema' => $ref->schema, 'object' => $ref->id]
		);
	}//end contextQuery()

	/**
	 * Headers every call carries: JSON responses, and a JSON body on writes.
	 *
	 * @param bool $withBody Whether the request carries a JSON body.
	 *
	 * @return array<string,string>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Body-vs-no-body is the
	 *     natural toggle for HTTP request headers; a two-method split would
	 *     duplicate the static Accept header.
	 */
	private function requestHeaders(bool $withBody=false): array {
		$headers = ['Accept' => 'application/json'];
		if ($withBody === true) {
			$headers['Content-Type'] = 'application/json';
		}

		return $headers;
	}//end requestHeaders()
}//end trait
