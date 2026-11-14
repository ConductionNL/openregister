<?php

/**
 * OpenConnectorHttpProvider — serves a sourced schema's objects from a named
 * OpenConnector source, through the integration router, with a declared
 * response mapping.
 *
 * A schema declares `x-openregister-object-source` with
 * `provider: "openconnector-http"` and a config such as
 * `{"source": "bag", "find": "/adressen/{key}", "findAll": "/adressen",
 *   "results": "_embedded.adressen", "query": ["postcode"],
 *   "mapping": "bag-adres-to-object", "id": "identificatie"}`.
 * BAG, BRK and WOZ differ in URL and shape, not in mechanism, so a fourth
 * register is a schema file, not code. Read-only; a record is never stored.
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
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Service\MappingService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider over a configured OpenConnector source.
 *
 * Every failure (no source configured, OpenConnector absent, the source
 * unreachable or refusing, a mapping that cannot be read) degrades to "no
 * record" here and is logged; the leaf's degrade contract
 * ({@see \OCA\OpenRegister\Service\Integration\ExternalRegisterDegrade}) is
 * what tells those apart for a person.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
class OpenConnectorHttpProvider implements ObjectSourceProvider {
	use CountsThroughFindAll;

	/**
	 * The provider id a schema's object-source block names.
	 *
	 * @var string
	 */
	public const ID = 'openconnector-http';

	/**
	 * Constructor.
	 *
	 * @param ExternalIntegrationRouterInterface $router Calls the OpenConnector source.
	 * @param MappingMapperInterface    $mappingMapper  Loads the declared mapping.
	 * @param MappingService            $mappingService Runs the declared mapping.
	 * @param LoggerInterface           $logger         Logs degraded reads.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function __construct(
		private readonly ExternalIntegrationRouterInterface $router,
		private readonly MappingMapperInterface $mappingMapper,
		private readonly MappingService $mappingService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Always available: a schema without a configured source degrades per read.
	 *
	 * @return bool Always true.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * @param SourcedTable $source The register, the sourced schema and its object-source `config` block.
	 * @param string       $id     The key the source finds the record by.
	 *
	 * @return ObjectEntity|null The mapped record, or null when absent or unavailable.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function find(SourcedTable $source, string $id): ?ObjectEntity {
		$config   = $source->config;
		$template = trim((string)($config['find'] ?? ''));
		if (trim($id) === '' || $template === '') {
			return null;
		}

		$body = $this->get(config: $config, path: str_replace('{key}', rawurlencode($id), $template), query: []);
		if (is_array($body) === false || $body === []) {
			return null;
		}

		$record = $this->mapped(config: $config, raw: $body);
		if ($record === null) {
			return null;
		}

		return $source->binding()->toEntity(uuid: $this->recordId(config: $config, record: $record, fallback: $id), data: $record);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * Only the query parameters the config declares under `query` are passed on.
	 *
	 * @param SourcedTable         $source The register, the sourced schema and its object-source `config` block.
	 * @param array<string, mixed> $query  Query (filters/limit/offset).
	 *
	 * @return ObjectEntity[] The mapped records (possibly empty).
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function findAll(SourcedTable $source, array $query = []): array {
		$config = $source->config;
		$path   = trim((string)($config['findAll'] ?? ''));
		if ($path === '') {
			return [];
		}

		$body = $this->get(config: $config, path: $path, query: self::declaredQuery(config: $config, query: $query));
		if (is_array($body) === false) {
			return [];
		}

		$entities = [];
		$binding  = $source->binding();
		foreach (self::rows(body: $body, path: (string)($config['results'] ?? 'results')) as $index => $row) {
			$record = $this->mapped(config: $config, raw: $row);
			if ($record !== null) {
				$entities[] = $binding->toEntity(uuid: $this->recordId(config: $config, record: $record, fallback: (string)$index), data: $record);
			}
		}

		return $entities;
	}//end findAll()

	/**
	 * The declared query parameters, taken from the query's filters.
	 *
	 * @param array<string, mixed> $config The object-source config.
	 * @param array<string, mixed> $query  The findAll() query.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public static function declaredQuery(array $config, array $query): array {
		$filters = (array)($query['filters'] ?? []);
		$params  = [];
		foreach ((array)($config['query'] ?? []) as $name) {
			$value = ($filters[$name] ?? $query[$name] ?? null);
			if (is_string($name) === true && is_scalar($value) === true && (string)$value !== '') {
				$params[$name] = (string)$value;
			}
		}

		return $params;
	}//end declaredQuery()

	/**
	 * The rows of a list body at a dot path; a body that is itself a list is the rows.
	 *
	 * @param array<mixed> $body The decoded body.
	 * @param string       $path The dot path, e.g. `_embedded.adressen`.
	 *
	 * @return array<int|string, array<string, mixed>>
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public static function rows(array $body, string $path): array {
		$node = $body;
		if (array_is_list($body) === false) {
			foreach (explode('.', $path) as $segment) {
				if (is_array($node) === false || array_key_exists($segment, $node) === false) {
					return [];
				}

				$node = $node[$segment];
			}
		}

		if (is_array($node) === false) {
			return [];
		}

		return array_filter($node, 'is_array');
	}//end rows()

	/**
	 * GET a path at the configured source; null when the read fails.
	 *
	 * @param array<string, mixed>  $config The object-source config.
	 * @param string                $path   The path relative to the source.
	 * @param array<string, string> $query  The query parameters.
	 *
	 * @return array<mixed>|null
	 */
	private function get(array $config, string $path, array $query): ?array {
		$sourceId = trim((string)($config['source'] ?? ''));
		if ($sourceId === '') {
			$this->logger->info('[ObjectSource:openconnector-http] no source configured for a sourced schema');
			return null;
		}

		try {
			return $this->router->call(
				provider: new ConfiguredSourceUpstream(sourceId: $sourceId),
				request: new UpstreamRequest(method: 'GET', path: $path, options: ['query' => $query])
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:openconnector-http] read failed: ' . $e->getMessage(), ['source' => $sourceId]);
			return null;
		}
	}//end get()

	/**
	 * A raw record through the declared mapping; the raw record when none is declared.
	 *
	 * A declared mapping that cannot be loaded or run yields null, never the
	 * unmapped record, so a schema never shows fields in a shape it did not declare.
	 *
	 * @param array<string, mixed> $config The object-source config.
	 * @param array<mixed>         $raw    The raw record.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mapped(array $config, array $raw): ?array {
		$reference = ($config['mapping'] ?? null);
		if ($reference === null || $reference === '') {
			return $raw;
		}

		try {
			$mapping = $this->mappingMapper->find(id: $reference);
			return $this->mappingService->executeMapping(mapping: $mapping, input: $raw);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:openconnector-http] mapping failed: ' . $e->getMessage(), ['mapping' => $reference]);
			return null;
		}
	}//end mapped()

	/**
	 * The record's id: the declared `id` field of the mapped record, else the fallback.
	 *
	 * @param array<string, mixed> $config   The object-source config.
	 * @param array<string, mixed> $record   The mapped record.
	 * @param string               $fallback The key it was read by, or its list position.
	 *
	 * @return string
	 */
	private function recordId(array $config, array $record, string $fallback): string {
		$field = (string)($config['id'] ?? 'id');
		$value = ($record[$field] ?? null);
		if (is_scalar($value) === true && (string)$value !== '') {
			return (string)$value;
		}

		return $fallback;
	}//end recordId()
}//end class
