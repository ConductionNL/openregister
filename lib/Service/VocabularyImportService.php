<?php

/**
 * VocabularyImportService — idempotent, URI-keyed SKOS import into the
 * OpenRegister `vocabulary` register (skos-concept-registers).
 *
 * Accepts a lightweight SKOS/JSON-LD document (a `@graph` of `skos:ConceptScheme`
 * / `skos:Concept` nodes, predicate keys either bare, `prefix:local`, or a full
 * IRI — a pragmatic subset of JSON-LD, not a general RDF framework: design.md
 * D4 scopes v1 to JSON-LD + CSV, Turtle/RDF-XML as follow-up) or a CSV
 * value-list export, and upserts a `conceptScheme` object plus its `concept`
 * objects, keyed on the SKOS source `uri` (the durable identity — never the
 * OpenRegister UUID, which is only ever a resolution target). Re-importing the
 * identical source is a no-op; changed labels/definitions update in place;
 * concepts present in the register but absent from the re-imported source are
 * flagged `deprecated: true`, never deleted, so long-lived leaf references
 * (e.g. a Woo publication's informatiecategorie) never dangle (design.md D3).
 * `broader`/`narrower` are maintained in both directions regardless of which
 * direction the source asserts (design.md D2, ADR-062 canonical relation
 * dialect: `type:string`/array + `format:uuid` + `$ref:<schemaKey>`).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Idempotent SKOS JSON-LD / CSV importer for the vocabulary register.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
class VocabularyImportService {
	/**
	 * Slug of the vocabulary register.
	 *
	 * @var string
	 */
	public const REGISTER = 'vocabulary';

	/**
	 * Slug of the conceptScheme schema.
	 *
	 * @var string
	 */
	public const SCHEMA_SCHEME = 'conceptScheme';

	/**
	 * Slug of the concept schema.
	 *
	 * @var string
	 */
	public const SCHEMA_CONCEPT = 'concept';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object CRUD (real API only — findAll/find/saveObject).
	 * @param LoggerInterface $logger Logger for import diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Import a concept scheme + its concepts from a SKOS/JSON-LD document.
	 *
	 * @param array<string,mixed> $jsonLd Decoded JSON-LD document: either a
	 *                                    `{"@graph": [...]}` envelope or a
	 *                                    single top-level node with `@id`.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 *
	 * @throws InvalidArgumentException When the document has no ConceptScheme node.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function importJsonLd(array $jsonLd): array {
		$graph = $jsonLd['@graph'] ?? null;
		if (is_array($graph) === false && isset($jsonLd['@id']) === true) {
			$graph = [$jsonLd];
		}

		if (is_array($graph) === false) {
			throw new InvalidArgumentException(
				'JSON-LD document must contain an "@graph" array or a single top-level node with "@id".'
			);
		}

		$schemeNode = null;
		$draftNodes = [];
		foreach ($graph as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$type = $this->localName(iriOrPrefixed: (string)($node['@type'] ?? ''));
			if ($type === 'ConceptScheme' && $schemeNode === null) {
				$schemeNode = $node;
			} elseif ($type === 'Concept') {
				$draftNodes[] = $node;
			}
		}

		if ($schemeNode === null || is_string($schemeNode['@id'] ?? null) === false) {
			throw new InvalidArgumentException('JSON-LD document has no skos:ConceptScheme node with an "@id".');
		}

		return $this->importParsedGraph(schemeNode: $schemeNode, draftNodes: $draftNodes);
	}//end importJsonLd()

	/**
	 * Import a concept scheme + its concepts from a CSV value-list export.
	 *
	 * Expected columns: `uri` (required), `notation`, `prefLabel_<lang>` (one
	 * column per BCP-47 language, at least `prefLabel_nl`), `definition`,
	 * `broaderUri` (pipe-`|`-separated list of broader concept uris).
	 *
	 * @param string $csvPath Path to the CSV file.
	 * @param array<string,string> $schemeMeta Scheme metadata: `uri` (required),
	 *                                         `title`, `publisher`, `version`, `source`.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 *
	 * @throws InvalidArgumentException When the file is missing/empty or scheme uri is absent.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function importCsvValueList(string $csvPath, array $schemeMeta): array {
		$schemeUri = $schemeMeta['uri'] ?? null;
		if (is_string($schemeUri) === false || trim($schemeUri) === '') {
			throw new InvalidArgumentException('CSV import requires a non-empty schemeMeta["uri"].');
		}

		if (is_file($csvPath) === false) {
			throw new InvalidArgumentException(sprintf('CSV value-list file not found: %s', $csvPath));
		}

		$handle = fopen($csvPath, 'rb');
		if ($handle === false) {
			throw new InvalidArgumentException(sprintf('Unable to open CSV value-list file: %s', $csvPath));
		}

		$header = fgetcsv(stream: $handle, length: null, separator: ',', enclosure: '"', escape: '');
		if ($header === false) {
			fclose($handle);
			throw new InvalidArgumentException(sprintf('CSV value-list file is empty: %s', $csvPath));
		}

		$header = array_map(static fn (string $col): string => trim($col), $header);

		$schemeNode = [
			'@id' => $schemeUri,
			'dct:title' => ($schemeMeta['title'] ?? $schemeUri),
			'dct:publisher' => ($schemeMeta['publisher'] ?? ''),
			'owl:versionInfo' => ($schemeMeta['version'] ?? '1.0'),
			'dct:source' => ($schemeMeta['source'] ?? $schemeUri),
		];

		$draftNodes = [];
		while (($row = fgetcsv(stream: $handle, length: null, separator: ',', enclosure: '"', escape: '')) !== false) {
			if (count($row) === 1 && trim((string)$row[0]) === '') {
				continue;
			}

			$rowAssoc = array_combine($header, array_pad($row, count($header), null));
			$uri = trim((string)($rowAssoc['uri'] ?? ''));
			if ($uri === '') {
				continue;
			}

			$prefLabel = [];
			foreach ($rowAssoc as $column => $value) {
				if (str_starts_with($column, 'prefLabel_') === true && trim((string)$value) !== '') {
					$long = substr($column, strlen('prefLabel_'));
					$prefLabel[$long] = trim((string)$value);
				}
			}

			$broaderIds = [];
			if (trim((string)($rowAssoc['broaderUri'] ?? '')) !== '') {
				foreach (explode('|', (string)$rowAssoc['broaderUri']) as $broaderUri) {
					$broaderIds[] = ['@id' => trim($broaderUri)];
				}
			}

			$draftNodes[] = [
				'@id' => $uri,
				'skos:notation' => ($rowAssoc['notation'] ?? null),
				'skos:definition' => ($rowAssoc['definition'] ?? null),
				'skos:prefLabel' => $prefLabel,
				'skos:broader' => $broaderIds,
			];
		}//end while

		fclose($handle);

		return $this->importParsedGraph(schemeNode: $schemeNode, draftNodes: $draftNodes);
	}//end importCsvValueList()

	/**
	 * Shared upsert pipeline for an already-parsed (scheme node, concept nodes) pair.
	 *
	 * @param array<string,mixed> $schemeNode The parsed scheme node.
	 * @param array<int,array<string,mixed>> $draftNodes The parsed concept nodes.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 */
	private function importParsedGraph(array $schemeNode, array $draftNodes): array {
		$schemeUuid = $this->upsertScheme(node: $schemeNode);
		$schemeUri = (string)$schemeNode['@id'];

		$created = 0;
		$updated = 0;
		$unchanged = 0;
		$seenUris = [];
		$uuidByUri = [];

		foreach ($draftNodes as $node) {
			$uri = (string)($node['@id'] ?? '');
			if ($uri === '') {
				continue;
			}

			$result = $this->upsertDraft(node: $node, schemeUuid: $schemeUuid);
			$seenUris[] = $uri;
			$uuidByUri[$uri] = $result['uuid'];
			match ($result['status']) {
				'created' => $created++,
				'updated' => $updated++,
				default => $unchanged++,
			};
		}

		$this->applyRelations(draftNodes: $draftNodes, uuidByUri: $uuidByUri);
		$deprecated = $this->deprecateMissing(schemeUuid: $schemeUuid, seenUris: $seenUris);

		$this->logger->info(
			message: '[VocabularyImportService] import complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'scheme' => $schemeUri,
				'created' => $created,
				'updated' => $updated,
				'unchanged' => $unchanged,
				'deprecated' => $deprecated,
			]
		);

		return [
			'scheme' => $schemeUri,
			'created' => $created,
			'updated' => $updated,
			'unchanged' => $unchanged,
			'deprecated' => $deprecated,
		];
	}//end importParsedGraph()

	/**
	 * Upsert the conceptScheme object for a parsed scheme node.
	 *
	 * @param array<string,mixed> $node The parsed scheme node.
	 *
	 * @return string The conceptScheme object's OpenRegister uuid.
	 */
	private function upsertScheme(array $node): string {
		$uri = (string)$node['@id'];

		$version = $this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'versionInfo'));
		if ($version === null) {
			$version = $this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'version'));
		}

		if ($version === null) {
			$version = '1.0';
		}

		$data = [
			'uri' => $uri,
			'title' => ($this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'title')) ?? $uri),
			'publisher' => ($this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'publisher')) ?? ''),
			'version' => $version,
			'source' => ($this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'source')) ?? $uri),
		];

		$description = $this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'description'));
		if ($description !== null) {
			$data['description'] = $description;
		}

		$existing = $this->findByUri(schema: self::SCHEMA_SCHEME, uri: $uri);
		if ($existing === null) {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_SCHEME
			);
			return (string)$saved->getUuid();
		}

		$existingData = ($existing->getObject() ?? []);
		if ($this->dataChanged(existingData: $existingData, newData: $data) === false) {
			return (string)$existing->getUuid();
		}

		// The saveObject call is PUT-semantic (omitted properties are cleared)
		// — carry every existing field forward, only the compared keys are overridden.
		$merged = array_merge($existingData, $data);
		$this->objectService->saveObject(
			object: $merged,
			register: self::REGISTER,
			schema: self::SCHEMA_SCHEME,
			uuid: $existing->getUuid()
		);

		return (string)$existing->getUuid();
	}//end upsertScheme()

	/**
	 * Upsert a single concept object for a parsed concept node.
	 *
	 * @param array<string,mixed> $node The parsed concept node.
	 * @param string $schemeUuid The owning conceptScheme's uuid.
	 *
	 * @return array{status: string, uuid: string} status is created|updated|unchanged.
	 *
	 * @throws InvalidArgumentException When the concept has no Dutch prefLabel.
	 */
	private function upsertDraft(array $node, string $schemeUuid): array {
		$uri = (string)$node['@id'];

		$prefLabel = $this->extractLongMap(value: $this->findPredicate(node: $node, localName: 'prefLabel'));
		if (isset($prefLabel['nl']) === false || trim($prefLabel['nl']) === '') {
			throw new InvalidArgumentException(
				sprintf('Concept "%s" has no Dutch (nl) prefLabel; refusing to import an invalid concept.', $uri)
			);
		}

		$data = [
			'uri' => $uri,
			'prefLabel' => $prefLabel,
			'inScheme' => $schemeUuid,
			'deprecated' => false,
		];

		$altLabel = $this->extractLongMap(value: $this->findPredicate(node: $node, localName: 'altLabel'));
		if (empty($altLabel) === false) {
			$data['altLabel'] = $altLabel;
		}

		$definition = $this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'definition'));
		if ($definition !== null) {
			$data['definition'] = $definition;
		}

		$notation = $this->extractLiteral(value: $this->findPredicate(node: $node, localName: 'notation'));
		if ($notation !== null) {
			$data['notation'] = $notation;
		}

		$existing = $this->findByUri(schema: self::SCHEMA_CONCEPT, uri: $uri);
		if ($existing === null) {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_CONCEPT
			);
			return ['status' => 'created', 'uuid' => (string)$saved->getUuid()];
		}

		$existingData = ($existing->getObject() ?? []);
		if ($this->dataChanged(existingData: $existingData, newData: $data) === false) {
			return ['status' => 'unchanged', 'uuid' => (string)$existing->getUuid()];
		}

		$merged = array_merge($existingData, $data);
		$this->objectService->saveObject(
			object: $merged,
			register: self::REGISTER,
			schema: self::SCHEMA_CONCEPT,
			uuid: $existing->getUuid()
		);

		return ['status' => 'updated', 'uuid' => (string)$existing->getUuid()];
	}//end upsertConcept()

	/**
	 * Recompute broader/narrower/related on every re-imported concept, both
	 * directions, from the source's asserted broader/narrower/related edges.
	 *
	 * Only concepts present in this import's `$uuidByUri` are touched — a
	 * concept absent from the re-imported source keeps its last-known edges
	 * (it is deprecated, not edited) per design.md D3.
	 *
	 * @param array<int,array<string,mixed>> $draftNodes The parsed concept nodes.
	 * @param array<string,string> $uuidByUri uri => uuid map for this import.
	 *
	 * @return void
	 */
	private function applyRelations(array $draftNodes, array $uuidByUri): void {
		$broaderOf = [];
		$narrowerOf = [];
		$relatedOf = [];

		// Seed every re-imported concept with an empty edge set so an edge
		// removed from the source is cleared on re-import, not left stale.
		foreach ($uuidByUri as $uuid) {
			$broaderOf[$uuid] = ($broaderOf[$uuid] ?? []);
			$narrowerOf[$uuid] = ($narrowerOf[$uuid] ?? []);
			$relatedOf[$uuid] = ($relatedOf[$uuid] ?? []);
		}

		foreach ($draftNodes as $node) {
			$uri = (string)($node['@id'] ?? '');
			$uuid = ($uuidByUri[$uri] ?? null);
			if ($uuid === null) {
				continue;
			}

			$this->collectEdges(node: $node, predicate: 'broader', uuid: $uuid, uuidByUri: $uuidByUri, forward: $broaderOf, reverse: $narrowerOf);
			$this->collectEdges(node: $node, predicate: 'narrower', uuid: $uuid, uuidByUri: $uuidByUri, forward: $narrowerOf, reverse: $broaderOf);
			// Related is symmetric: the same map serves as both forward and reverse.
			$this->collectEdges(node: $node, predicate: 'related', uuid: $uuid, uuidByUri: $uuidByUri, forward: $relatedOf, reverse: $relatedOf);
		}//end foreach

		foreach ($uuidByUri as $uuid) {
			$this->applyRelationFieldsToDraft(
				uuid: $uuid,
				broader: array_values(array_unique($broaderOf[$uuid] ?? [])),
				narrower: array_values(array_unique($narrowerOf[$uuid] ?? [])),
				related: array_values(array_unique($relatedOf[$uuid] ?? []))
			);
		}
	}//end applyRelations()

	/**
	 * Resolve one predicate's asserted edges on `$node` to uuids and record
	 * both the forward edge (`$uuid` -> target) and the reverse edge (target
	 * -> `$uuid`) so a relation asserted in only one direction by the source
	 * is still readable from either concept.
	 *
	 * @param array<string,mixed> $node The parsed concept node.
	 * @param string $predicate The relation predicate local name (broader|narrower|related).
	 * @param string $uuid The concept's own uuid.
	 * @param array<string,string> $uuidByUri uri => uuid map for this import.
	 * @param array<string,array<string>> $forward Accumulator for `$uuid` -> targets (by reference).
	 * @param array<string,array<string>> $reverse Accumulator for target -> `$uuid` (by reference; same
	 *                                             array as `$forward` for a symmetric predicate like
	 *                                             `related`).
	 *
	 * @return void
	 */
	private function collectEdges(array $node, string $predicate, string $uuid, array $uuidByUri, array &$forward, array &$reverse): void {
		foreach ($this->extractIdRefs(value: $this->findPredicate(node: $node, localName: $predicate)) as $targetUri) {
			$targetUuid = ($uuidByUri[$targetUri] ?? null);
			if ($targetUuid === null) {
				continue;
			}

			$forward[$uuid][] = $targetUuid;
			$reverse[$targetUuid][] = $uuid;
		}
	}//end collectEdges()

	/**
	 * Write a concept's broader/narrower/related fields, skipping the save
	 * when the resolved edge set already matches (idempotent re-import).
	 *
	 * @param string $uuid The concept's uuid.
	 * @param array<string> $broader Resolved broader uuids.
	 * @param array<string> $narrower Resolved narrower uuids.
	 * @param array<string> $related Resolved related uuids.
	 *
	 * @return void
	 */
	private function applyRelationFieldsToDraft(string $uuid, array $broader, array $narrower, array $related): void {
		$existing = $this->objectService->find(id: $uuid, register: self::REGISTER, schema: self::SCHEMA_CONCEPT);
		if ($existing === null) {
			return;
		}

		$existingData = ($existing->getObject() ?? []);

		$existingBroader = $this->normalizeUuidList(value: ($existingData['broader'] ?? []));
		$existingNarrower = $this->normalizeUuidList(value: ($existingData['narrower'] ?? []));
		$existingRelated = $this->normalizeUuidList(value: ($existingData['related'] ?? []));

		sort($broader);
		sort($narrower);
		sort($related);
		sort($existingBroader);
		sort($existingNarrower);
		sort($existingRelated);

		if ($broader === $existingBroader && $narrower === $existingNarrower && $related === $existingRelated) {
			return;
		}

		$merged = $existingData;
		$merged['broader'] = $broader;
		$merged['narrower'] = $narrower;
		$merged['related'] = $related;

		$this->objectService->saveObject(
			object: $merged,
			register: self::REGISTER,
			schema: self::SCHEMA_CONCEPT,
			uuid: $uuid
		);
	}//end applyRelationFieldsToConcept()

	/**
	 * Mark concepts of a scheme absent from `$seenUris` as deprecated. Never deletes.
	 *
	 * @param string $schemeUuid The conceptScheme's uuid.
	 * @param array<string> $seenUris URIs present in the just-completed import.
	 *
	 * @return int Count of concepts newly marked deprecated.
	 */
	private function deprecateMissing(string $schemeUuid, array $seenUris): int {
		$count = 0;
		$limit = 200;
		$offset = 0;

		do {
			$results = $this->objectService->findAll(
				config: [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_CONCEPT,
					'filters' => ['inScheme' => $schemeUuid],
					'limit' => $limit,
					'offset' => $offset,
				]
			);

			// Counted once per page, not re-counted by the loop condition.
			// $results is replaced wholesale on every iteration and never
			// mutated below, so this is the same value the condition read.
			$pageSize = count($results);

			foreach ($results as $entity) {
				$data = ($entity->getObject() ?? []);
				$uri = ($data['uri'] ?? null);
				if ($uri === null
					|| in_array($uri, $seenUris, true) === true
					|| ($data['deprecated'] ?? false) === true
				) {
					continue;
				}

				$data['deprecated'] = true;
				$this->objectService->saveObject(
					object: $data,
					register: self::REGISTER,
					schema: self::SCHEMA_CONCEPT,
					uuid: $entity->getUuid()
				);
				$count++;
			}

			$offset += $limit;
		} while ($pageSize === $limit);

		return $count;
	}//end deprecateMissing()

	/**
	 * Find a conceptScheme/concept object by its durable source uri.
	 *
	 * @param string $schema Schema slug (self::SCHEMA_SCHEME or self::SCHEMA_CONCEPT).
	 * @param string $uri The source uri to look up.
	 *
	 * @return ObjectEntity|null The matching object, or null when absent.
	 */
	private function findByUri(string $schema, string $uri): ?ObjectEntity {
		$results = $this->objectService->findAll(
			config: [
				'register' => self::REGISTER,
				'schema' => $schema,
				'filters' => ['uri' => $uri],
				'limit' => 1,
			]
		);

		return ($results[0] ?? null);
	}//end findByUri()

	/**
	 * Whether any of the compared keys in `$newData` differ from `$existingData`.
	 *
	 * @param array<string,mixed> $existingData The stored object data.
	 * @param array<string,mixed> $newData The newly computed data (subset of keys to compare).
	 *
	 * @return bool True when at least one compared key differs.
	 */
	private function dataChanged(array $existingData, array $newData): bool {
		foreach ($newData as $key => $value) {
			if (($existingData[$key] ?? null) !== $value) {
				return true;
			}
		}

		return false;
	}//end dataChanged()

	/**
	 * Normalise a stored relation field value to a flat array of uuid strings.
	 *
	 * @param mixed $value The stored `broader`/`narrower`/`related` field value.
	 *
	 * @return array<string>
	 */
	private function normalizeUuidList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(static fn ($item): string => (string)$item, $value),
				static fn (string $item): bool => $item !== ''
			)
		);
	}//end normalizeUuidList()

	/**
	 * Find the first value of a JSON-LD node whose key's local name (the
	 * part after the last `#`, `/` or `:`) matches `$localName`.
	 *
	 * @param array<string,mixed> $node The JSON-LD node.
	 * @param string $localName The predicate local name to find (e.g. "prefLabel").
	 *
	 * @return mixed The matched value, or null when absent.
	 */
	private function findPredicate(array $node, string $localName): mixed {
		foreach ($node as $key => $value) {
			if (str_starts_with($key, '@') === true) {
				continue;
			}

			if ($this->localName(iriOrPrefixed: $key) === $localName) {
				return $value;
			}
		}

		return null;
	}//end findPredicate()

	/**
	 * The local name of an IRI or prefixed name — the substring after the
	 * rightmost `#`, `/` or `:`.
	 *
	 * @param string $iriOrPrefixed A full IRI, a `prefix:local` name, or a bare local name.
	 *
	 * @return string The local name.
	 */
	private function localName(string $iriOrPrefixed): string {
		$pos = false;
		foreach (['#', '/', ':'] as $separator) {
			$candidate = strrpos($iriOrPrefixed, $separator);
			if ($candidate !== false && ($pos === false || $candidate > $pos)) {
				$pos = $candidate;
			}
		}

		if ($pos === false) {
			return $iriOrPrefixed;
		}

		return substr($iriOrPrefixed, $pos + 1);
	}//end localName()

	/**
	 * Extract a single string literal from a JSON-LD predicate value.
	 *
	 * Accepts a bare string, a `{"@value": "..."}` literal node, or an array
	 * of either (the first non-empty match wins).
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return string|null The extracted literal, or null when absent/empty.
	 */
	private function extractLiteral(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_string($value) === true) {
			$trimmed = trim($value);
			if ($trimmed === '') {
				return null;
			}

			return $trimmed;
		}

		if (is_array($value) === true) {
			if (isset($value['@value']) === true) {
				return (string)$value['@value'];
			}

			foreach ($value as $item) {
				$literal = $this->extractLiteral(value: $item);
				if ($literal !== null) {
					return $literal;
				}
			}
		}

		return null;
	}//end extractLiteral()

	/**
	 * Extract a BCP-47-keyed language map from a JSON-LD predicate value.
	 *
	 * Accepts an array of `{"@value": "...", "@language": "xx"}` literal
	 * nodes (canonical JSON-LD), a bare string (assumed `nl`), or an assoc
	 * shorthand `{"nl": "...", "en": "..."}`.
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return array<string,string> BCP-47 tag => label.
	 */
	private function extractLongMap(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_string($value) === true) {
			$trimmed = trim($value);
			if ($trimmed === '') {
				return [];
			}

			return ['nl' => $trimmed];
		}

		if (is_array($value) === false) {
			return [];
		}

		if (isset($value['@value']) === true) {
			$long = (string)($value['@language'] ?? 'nl');
			return [$long => (string)$value['@value']];
		}

		$map = [];
		if (array_is_list($value) === true) {
			foreach ($value as $item) {
				if (is_array($item) === true && isset($item['@value']) === true) {
					$long = (string)($item['@language'] ?? 'nl');
					$map[$long] = (string)$item['@value'];
				} elseif (is_string($item) === true && trim($item) !== '') {
					$map['nl'] = $item;
				}
			}

			return $map;
		}

		foreach ($value as $long => $label) {
			if (is_string($label) === true && trim($label) !== '') {
				$map[(string)$long] = $label;
			}
		}

		return $map;
	}//end extractLangMap()

	/**
	 * Extract a list of referenced uris from a JSON-LD predicate value.
	 *
	 * Accepts a bare uri string, a `{"@id": "..."}` reference node, or an
	 * array of either.
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return array<string> The referenced uris.
	 */
	private function extractIdRefs(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_string($value) === true) {
			$trimmed = trim($value);
			if ($trimmed === '') {
				return [];
			}

			return [$trimmed];
		}

		if (is_array($value) === false) {
			return [];
		}

		if (isset($value['@id']) === true) {
			return [(string)$value['@id']];
		}

		$out = [];
		foreach ($value as $item) {
			if (is_array($item) === true && isset($item['@id']) === true) {
				$out[] = (string)$item['@id'];
			} elseif (is_string($item) === true && trim($item) !== '') {
				$out[] = $item;
			}
		}

		return $out;
	}//end extractIdRefs()
}//end class
