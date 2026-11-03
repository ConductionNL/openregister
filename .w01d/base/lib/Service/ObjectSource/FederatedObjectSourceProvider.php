<?php

/**
 * FederatedObjectSourceProvider — serves a shadow schema's objects LIVE from a
 * remote OpenRegister instance over the federation serving endpoint.
 *
 * When an incoming federated share is accepted, a local shadow schema is bound
 * to this provider (`x-openregister-object-source.provider = "federated"`) with
 * config `{ remoteUrl, shareToken }`. `find/findAll/count` then proxy to the
 * remote's `/apps/openregister/api/federation/{token}/objects...` — the remote
 * scopes the response to exactly what the share grants — and project each
 * returned record as a non-persisted local ObjectEntity. Strictly read-only
 * here; write-through for read-write shares is handled elsewhere.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by a remote OpenRegister instance.
 */
class FederatedObjectSourceProvider implements ObjectSourceProvider {
	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory for remote reads.
	 * @param LoggerInterface $logger Logger for remote-read failures.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 */
	public function getId(): string {
		return 'federated';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Always available — a broken/offline peer degrades to an empty result.
	 *
	 * @return bool Always true.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The local shadow register.
	 * @param Schema $schema The local shadow schema.
	 * @param string $id The remote object id/uuid.
	 * @param array<string, mixed> $config Object-source config `{remoteUrl, shareToken}`.
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent/unreachable.
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		$base = $this->baseUrl(config: $config);
		if ($base === null) {
			return null;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($base . '/objects/' . rawurlencode($id), ['timeout' => 15]);
			$body = json_decode((string)$response->getBody(), true);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:federated] find failed: ' . $e->getMessage());
			return null;
		}

		if (is_array($body) === false || isset($body['error']) === true) {
			return null;
		}

		return $this->toObjectEntity(register: $register, schema: $schema, record: $body);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The local shadow register.
	 * @param Schema $schema The local shadow schema.
	 * @param array<string, mixed> $query Query (limit/offset/search honoured).
	 * @param array<string, mixed> $config Object-source config `{remoteUrl, shareToken}`.
	 *
	 * @return ObjectEntity[] The matching virtual objects (possibly empty).
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$base = $this->baseUrl(config: $config);
		if ($base === null) {
			return [];
		}

		$params = [
			'_limit' => (string)($query['limit'] ?? 50),
			'_offset' => (string)($query['offset'] ?? 0),
		];
		$search = ($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? null);
		if ($search !== null && $search !== '') {
			$params['_search'] = (string)$search;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($base . '/objects', ['timeout' => 15, 'query' => $params]);
			$body = json_decode((string)$response->getBody(), true);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:federated] findAll failed: ' . $e->getMessage());
			return [];
		}

		$results = [];
		foreach (($body['results'] ?? []) as $record) {
			if (is_array($record) === true) {
				$results[] = $this->toObjectEntity(register: $register, schema: $schema, record: $record);
			}
		}

		return $results;
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register $register The local shadow register.
	 * @param Schema $schema The local shadow schema.
	 * @param array<string, mixed> $query Query (search honoured).
	 * @param array<string, mixed> $config Object-source config `{remoteUrl, shareToken}`.
	 *
	 * @return int The number of matching virtual objects.
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
	}//end count()

	/**
	 * Build the remote federation base URL from the source config.
	 *
	 * @param array<string, mixed> $config Object-source config `{remoteUrl, shareToken}`.
	 *
	 * @return string|null The base `.../api/federation/{token}` URL, or null when misconfigured.
	 */
	private function baseUrl(array $config): ?string {
		$remoteUrl = trim((string)($config['remoteUrl'] ?? ''));
		$token = trim((string)($config['shareToken'] ?? ''));

		if ($remoteUrl === '' || $token === '') {
			$this->logger->warning('[ObjectSource:federated] missing remoteUrl/shareToken in source config');
			return null;
		}

		return rtrim($remoteUrl, '/') . '/apps/openregister/api/federation/' . rawurlencode($token);
	}//end baseUrl()

	/**
	 * Project a remote rendered record onto a non-persisted local ObjectEntity.
	 *
	 * @param Register $register The local shadow register.
	 * @param Schema $schema The local shadow schema.
	 * @param array<string, mixed> $record The remote rendered object (data + `@self`).
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 */
	private function toObjectEntity(Register $register, Schema $schema, array $record): ObjectEntity {
		$self = ($record['@self'] ?? []);
		$uuid = (string)($self['id'] ?? $record['id'] ?? $record['uuid'] ?? '');

		$data = $record;
		unset($data['@self']);

		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject($data);

		if (isset($self['uri']) === true) {
			$entity->setUri((string)$self['uri']);
		}

		return $entity;
	}//end toObjectEntity()
}//end class
