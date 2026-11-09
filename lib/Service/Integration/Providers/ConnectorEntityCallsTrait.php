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
			method: 'GET',
			path: '',
			options: ['query' => $query, 'headers' => $this->requestHeaders()]
		);

		return $this->normalizeList(response: $response);
	}//end listFromSource()

	/**
	 * Fetch one linked external entity.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema   Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param string $entityId External entity id.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$query = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []);
		$response = $this->router->call(
			provider: $this,
			method: 'GET',
			path: rawurlencode($entityId),
			options: ['query' => $query, 'headers' => $this->requestHeaders()]
		);

		return $this->normalizeRow(row: $response);
	}//end get()

	/**
	 * Link (or create) an external entity against an OR object.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Object uuid.
	 * @param array<string,mixed> $payload  Reference or new-entity fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$body = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: $payload);
		$response = $this->router->call(
			provider: $this,
			method: 'POST',
			path: '',
			options: ['body' => $body, 'headers' => $this->requestHeaders(withBody: true)]
		);

		return $this->normalizeRow(row: $response);
	}//end create()

	/**
	 * Update a linked entity pairing.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Object uuid.
	 * @param string              $entityId External entity id.
	 * @param array<string,mixed> $payload  Fields to update.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		$body = $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: $payload);
		$response = $this->router->call(
			provider: $this,
			method: 'PUT',
			path: rawurlencode($entityId),
			options: ['body' => $body, 'headers' => $this->requestHeaders(withBody: true)]
		);

		return $this->normalizeRow(row: $response);
	}//end update()

	/**
	 * Unlink an external entity. The entity itself stays in the external system.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema   Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param string $entityId External entity id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-openproject/spec.md
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		$this->router->call(
			provider: $this,
			method: 'DELETE',
			path: rawurlencode($entityId),
			options: [
				'query' => $this->contextQuery(register: $register, schema: $schema, objectId: $objectId, filters: []),
				'headers' => $this->requestHeaders(),
			]
		);
	}//end delete()

	/**
	 * The standard `{register, schema, object, …filters}` context, used as the
	 * query of reads and as the body of writes.
	 *
	 * @param string              $register Register slug or numeric id.
	 * @param string              $schema   Schema slug or numeric id.
	 * @param string              $objectId Object uuid.
	 * @param array<string,mixed> $filters  Caller filters or payload merged in.
	 *
	 * @return array<string,mixed>
	 */
	private function contextQuery(string $register, string $schema, string $objectId, array $filters): array {
		return array_merge(
			$filters,
			['register' => $register, 'schema' => $schema, 'object' => $objectId]
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
