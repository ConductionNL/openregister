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

	use CountsThroughFindAll;
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
		$peer = $this->peer(binding: new SourcedObjectBinding(register: $register, schema: $schema), config: $config);

		return $this->findRecord(peer: $peer, id: $id);
	}//end find()

	/**
	 * Read one record from the peer.
	 *
	 * @param FederatedPeer $peer The peer and the local binding.
	 * @param string        $id   The remote object id.
	 *
	 * @return ObjectEntity|null The record, or null when unconfigured or unreachable.
	 */
	private function findRecord(FederatedPeer $peer, string $id): ?ObjectEntity {
		$base = $peer->baseUrl;
		if ($base === null) {
			return null;
		}

		try {
			$body = $this->getJson(url: $base . '/objects/' . rawurlencode($id), options: ['timeout' => 15]);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:federated] find failed: ' . $e->getMessage());
			return null;
		}

		return $this->recordEntity(binding: $peer->binding, body: $body);
	}//end findRecord()

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
		$peer = $this->peer(binding: new SourcedObjectBinding(register: $register, schema: $schema), config: $config);

		return $this->listRecords(peer: $peer, query: $query);
	}//end findAll()

	/**
	 * Read one page of records from the peer.
	 *
	 * @param FederatedPeer        $peer  The peer and the local binding.
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 *
	 * @return ObjectEntity[] The records, or an empty list when unconfigured or unreachable.
	 */
	private function listRecords(FederatedPeer $peer, array $query): array {
		$base = $peer->baseUrl;
		if ($base === null) {
			return [];
		}
		$options = ['timeout' => 15, 'query' => $this->listParams(query: $query)];
		try {
			$body = $this->getJson(url: $base . '/objects', options: $options);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:federated] findAll failed: ' . $e->getMessage());
			return [];
		}//end try

		return $this->listEntities(binding: $peer->binding, body: $body);
	}//end listRecords()

	/**
	 * The peer a schema's object-source config names.
	 *
	 * @param SourcedObjectBinding $binding The local register and schema.
	 * @param array<string, mixed> $config  The object-source config (`remoteUrl`, `shareToken`).
	 *
	 * @return FederatedPeer
	 */
	private function peer(SourcedObjectBinding $binding, array $config): FederatedPeer {
		return new FederatedPeer(binding: $binding, baseUrl: $this->baseUrl(config: $config));
	}//end peer()

	/**
	 * GET a remote URL and decode its JSON body.
	 *
	 * @param string $url The URL.
	 * @param array<string, mixed> $options The client options.
	 *
	 * @return mixed The decoded body (null when it is not JSON).
	 *
	 * @throws Throwable When the request fails.
	 */
	private function getJson(string $url, array $options): mixed {
		$response = $this->clientService->newClient()->get($url, $options);
		return json_decode((string)$response->getBody(), true);
	}//end getJson()

	/**
	 * The remote list query: paging plus the first non-empty search spelling.
	 *
	 * @param array<string, mixed> $query The findAll() query.
	 *
	 * @return array<string, string> The query parameters.
	 */
	private function listParams(array $query): array {
		$params = [
			'_limit' => (string)($query['limit'] ?? 50),
			'_offset' => (string)($query['offset'] ?? 0),
		];
		$search = $this->searchTerm(query: $query);
		if ($search !== null && $search !== '') {
			$params['_search'] = (string)$search;
		}

		return $params;
	}//end listParams()

	/**
	 * The search term: `filters.search`, else `_search`, else `search`.
	 *
	 * @param array<string, mixed> $query The findAll() query.
	 *
	 * @return mixed The term, or null.
	 */
	private function searchTerm(array $query): mixed {
		return ($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? null);
	}//end searchTerm()

	/**
	 * The virtual object of a single-record body; null for an error or non-record body.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param mixed $body The decoded body.
	 *
	 * @return ObjectEntity|null The virtual object.
	 */
	private function recordEntity(SourcedObjectBinding $binding, mixed $body): ?ObjectEntity {
		if (is_array($body) === false || isset($body['error']) === true) {
			return null;
		}

		return $this->toObjectEntity(binding: $binding, record: $body);
	}//end recordEntity()

	/**
	 * The virtual objects of a list body's array `results`.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param mixed $body The decoded body.
	 *
	 * @return ObjectEntity[] The virtual objects.
	 */
	private function listEntities(SourcedObjectBinding $binding, mixed $body): array {
		$results = [];
		foreach (($body['results'] ?? []) as $record) {
			if (is_array($record) === true) {
				$results[] = $this->toObjectEntity(binding: $binding, record: $record);
			}
		}

		return $results;
	}//end listEntities()

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
	 * @param SourcedObjectBinding $binding The local shadow register and schema.
	 * @param array<string, mixed> $record The remote rendered object (data + `@self`).
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 */
	private function toObjectEntity(SourcedObjectBinding $binding, array $record): ObjectEntity {
		$self = ($record['@self'] ?? []);
		$data = $record;
		unset($data['@self']);

		$entity = $binding->toEntity(uuid: $this->recordUuid(record: $record), data: $data);
		if (isset($self['uri']) === true) {
			$entity->setUri((string)$self['uri']);
		}

		return $entity;
	}//end toObjectEntity()

	/**
	 * The uuid of a remote record: `@self.id`, else `id`, else `uuid`.
	 *
	 * @param array<string, mixed> $record The remote record.
	 *
	 * @return string The uuid ('' when absent).
	 */
	private function recordUuid(array $record): string {
		$self = ($record['@self'] ?? []);
		return (string)($self['id'] ?? $record['id'] ?? $record['uuid'] ?? '');
	}//end recordUuid()
}//end class
