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
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent SKOS JSON-LD / CSV importer for the vocabulary register.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
class VocabularyImportService implements VocabularyImporterInterface {
	/**
	 * Reads predicate values out of JSON-LD nodes.
	 *
	 * @var SkosNodeReader
	 */
	private readonly SkosNodeReader $reader;

	/**
	 * Reads CSV value lists into nodes.
	 *
	 * @var VocabularyCsvReader
	 */
	private readonly VocabularyCsvReader $csvReader;

	/**
	 * Uri-keyed store for conceptScheme objects.
	 *
	 * @var VocabularyObjectStore
	 */
	private readonly VocabularyObjectStore $schemes;

	/**
	 * Uri-keyed store for concept objects.
	 *
	 * @var VocabularyObjectStore
	 */
	private readonly VocabularyObjectStore $concepts;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService OR object CRUD (real API only — findAll/find/saveObject).
	 * @param LoggerInterface $logger Logger for import diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		$this->reader = new SkosNodeReader();
		$this->csvReader = new VocabularyCsvReader();
		$this->schemes = new VocabularyObjectStore($objectService, self::SCHEMA_SCHEME);
		$this->concepts = new VocabularyObjectStore($objectService, self::SCHEMA_CONCEPT);
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
		$nodes = $this->partitionGraph(graph: $this->graphOf(jsonLd: $jsonLd));
		$schemeNode = $nodes['scheme'];
		if ($schemeNode === null || is_string($schemeNode['@id'] ?? null) === false) {
			throw new InvalidArgumentException('JSON-LD document has no skos:ConceptScheme node with an "@id".');
		}

		return $this->importParsedGraph(schemeNode: $schemeNode, conceptNodes: $nodes['concepts']);
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
		$schemeNode = $this->csvReader->schemeNode(schemeMeta: $schemeMeta);
		$conceptNodes = $this->csvReader->conceptNodes(csvPath: $csvPath);

		return $this->importParsedGraph(schemeNode: $schemeNode, conceptNodes: $conceptNodes);
	}//end importCsvValueList()

	/**
	 * The node list of a JSON-LD document: its `@graph`, or the document itself
	 * when it is a single top-level node with `@id`.
	 *
	 * @param array<string,mixed> $jsonLd Decoded JSON-LD document.
	 *
	 * @return array<mixed>
	 *
	 * @throws InvalidArgumentException When the document has neither.
	 */
	private function graphOf(array $jsonLd): array {
		$graph = $jsonLd['@graph'] ?? null;
		if (is_array($graph) === false && isset($jsonLd['@id']) === true) {
			$graph = [$jsonLd];
		}

		if (is_array($graph) === false) {
			throw new InvalidArgumentException(
				'JSON-LD document must contain an "@graph" array or a single top-level node with "@id".'
			);
		}

		return $graph;
	}//end graphOf()

	/**
	 * Split a graph into its first ConceptScheme node and its Concept nodes.
	 *
	 * @param array<mixed> $graph The graph nodes.
	 *
	 * @return array{scheme: array<string,mixed>|null, concepts: array<int,array<string,mixed>>}
	 */
	private function partitionGraph(array $graph): array {
		$nodes = ['scheme' => null, 'concepts' => []];
		foreach ($graph as $node) {
			$type = $this->nodeType(node: $node);
			if ($type === 'ConceptScheme' && $nodes['scheme'] === null) {
				$nodes['scheme'] = $node;
				continue;
			}

			if ($type === 'Concept') {
				$nodes['concepts'][] = $node;
			}
		}

		return $nodes;
	}//end partitionGraph()

	/**
	 * The local name of a node's `@type`; empty for a non-node.
	 *
	 * @param mixed $node The graph entry.
	 *
	 * @return string
	 */
	private function nodeType(mixed $node): string {
		if (is_array($node) === false) {
			return '';
		}

		return $this->reader->localName(iriOrPrefixed: (string)($node['@type'] ?? ''));
	}//end nodeType()

	/**
	 * Shared upsert pipeline for an already-parsed (scheme node, concept nodes) pair.
	 *
	 * @param array<string,mixed> $schemeNode The parsed scheme node.
	 * @param array<int,array<string,mixed>> $conceptNodes The parsed concept nodes.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 */
	private function importParsedGraph(array $schemeNode, array $conceptNodes): array {
		$schemeUuid = $this->schemes->upsertByUri(data: $this->schemeData(node: $schemeNode))['uuid'];
		$tally = $this->upsertConcepts(conceptNodes: $conceptNodes, schemeUuid: $schemeUuid);
		$this->applyRelations(conceptNodes: $conceptNodes, uuidByUri: $tally->uuidByUri);
		$deprecated = $this->concepts->deprecateMissing(schemeUuid: $schemeUuid, seenUris: $tally->seenUris);
		$report = $tally->report(schemeUri: (string)$schemeNode['@id'], deprecated: $deprecated);

		$this->logger->info(
			message: '[VocabularyImportService] import complete',
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $report)
		);

		return $report;
	}//end importParsedGraph()

	/**
	 * Upsert every concept node with an `@id`, tallying the outcomes.
	 *
	 * @param array<int,array<string,mixed>> $conceptNodes The parsed concept nodes.
	 * @param string $schemeUuid The owning conceptScheme's uuid.
	 *
	 * @return VocabularyImportTally
	 */
	private function upsertConcepts(array $conceptNodes, string $schemeUuid): VocabularyImportTally {
		$tally = new VocabularyImportTally();
		foreach ($conceptNodes as $node) {
			$uri = (string)($node['@id'] ?? '');
			if ($uri !== '') {
				$tally->record(uri: $uri, result: $this->upsertConcept(node: $node, schemeUuid: $schemeUuid));
			}
		}

		return $tally;
	}//end upsertConcepts()

	/**
	 * The conceptScheme object data for a parsed scheme node.
	 *
	 * @param array<string,mixed> $node The parsed scheme node.
	 *
	 * @return array<string,mixed>
	 */
	private function schemeData(array $node): array {
		$uri = (string)$node['@id'];
		$data = [
			'uri' => $uri,
			'title' => ($this->literalOf(node: $node, localName: 'title') ?? $uri),
			'publisher' => ($this->literalOf(node: $node, localName: 'publisher') ?? ''),
			'version' => ($this->literalOf(node: $node, localName: 'versionInfo') ?? $this->literalOf(node: $node, localName: 'version') ?? '1.0'),
			'source' => ($this->literalOf(node: $node, localName: 'source') ?? $uri),
		];

		return $this->withOptional(data: $data, optional: ['description' => $this->literalOf(node: $node, localName: 'description')]);
	}//end schemeData()

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
	private function upsertConcept(array $node, string $schemeUuid): array {
		$data = [
			'uri' => (string)$node['@id'],
			'prefLabel' => $this->dutchPrefLabel(node: $node),
			'inScheme' => $schemeUuid,
			'deprecated' => false,
		];
		$optional = [
			'altLabel' => $this->reader->langMap(value: $this->reader->findPredicate(node: $node, localName: 'altLabel')),
			'definition' => $this->literalOf(node: $node, localName: 'definition'),
			'notation' => $this->literalOf(node: $node, localName: 'notation'),
		];
		return $this->concepts->upsertByUri(data: $this->withOptional(data: $data, optional: $optional));
	}//end upsertConcept()

	/**
	 * A concept node's prefLabel language map, which must carry a Dutch label.
	 *
	 * @param array<string,mixed> $node The parsed concept node.
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException When the concept has no Dutch prefLabel.
	 */
	private function dutchPrefLabel(array $node): array {
		$prefLabel = $this->reader->langMap(value: $this->reader->findPredicate(node: $node, localName: 'prefLabel'));
		if (isset($prefLabel['nl']) === false || trim($prefLabel['nl']) === '') {
			throw new InvalidArgumentException(
				sprintf('Concept "%s" has no Dutch (nl) prefLabel; refusing to import an invalid concept.', (string)$node['@id'])
			);
		}

		return $prefLabel;
	}//end dutchPrefLabel()

	/**
	 * Add each optional field that has a value (not null, not an empty map).
	 *
	 * @param array<string,mixed> $data The required fields.
	 * @param array<string,mixed> $optional Optional field => value, in output order.
	 *
	 * @return array<string,mixed>
	 */
	private function withOptional(array $data, array $optional): array {
		foreach ($optional as $key => $value) {
			if ($value !== null && $value !== []) {
				$data[$key] = $value;
			}
		}

		return $data;
	}//end withOptional()

	/**
	 * The string literal of a node's predicate.
	 *
	 * @param array<string,mixed> $node The JSON-LD node.
	 * @param string $localName The predicate local name.
	 *
	 * @return string|null
	 */
	private function literalOf(array $node, string $localName): ?string {
		return $this->reader->literal(value: $this->reader->findPredicate(node: $node, localName: $localName));
	}//end literalOf()

	/**
	 * Recompute broader/narrower/related on every re-imported concept, both
	 * directions, from the source's asserted broader/narrower/related edges.
	 *
	 * Only concepts present in this import's `$uuidByUri` are touched — a
	 * concept absent from the re-imported source keeps its last-known edges
	 * (it is deprecated, not edited) per design.md D3.
	 *
	 * @param array<int,array<string,mixed>> $conceptNodes The parsed concept nodes.
	 * @param array<string,string> $uuidByUri uri => uuid map for this import.
	 *
	 * @return void
	 */
	private function applyRelations(array $conceptNodes, array $uuidByUri): void {
		$edges = new VocabularyConceptEdges($uuidByUri);
		foreach ($conceptNodes as $node) {
			$uuid = ($uuidByUri[(string)($node['@id'] ?? '')] ?? null);
			if ($uuid !== null) {
				$edges->add(uuid: $uuid, targetsByPredicate: $this->assertedTargets(node: $node));
			}
		}

		foreach ($uuidByUri as $uuid) {
			$this->applyRelationFieldsToDraft(uuid: $uuid, relations: $edges->relationsOf(uuid: $uuid));
		}
	}//end applyRelations()

	/**
	 * The target uris a concept node asserts, per relation predicate.
	 *
	 * @param array<string,mixed> $node The parsed concept node.
	 *
	 * @return array<string,array<string>>
	 */
	private function assertedTargets(array $node): array {
		$targets = [];
		foreach (array_keys(VocabularyConceptEdges::INVERSE) as $predicate) {
			$targets[$predicate] = $this->reader->idRefs(value: $this->reader->findPredicate(node: $node, localName: $predicate));
		}

		return $targets;
	}//end assertedTargets()

	/**
	 * Write a concept's broader/narrower/related fields, skipping the save
	 * when the resolved edge set already matches (idempotent re-import).
	 *
	 * @param string $uuid The concept's uuid.
	 * @param array<string,array<string>> $relations Resolved broader/narrower/related uuids.
	 *
	 * @return void
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function applyRelationFieldsToDraft(string $uuid, array $relations): void {
		$existing = WriteCause::asLookup(fn () => $this->objectService->find(id: $uuid, options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA_CONCEPT)));
		if ($existing === null) {
			return;
		}

		$existingData = ($existing->getObject() ?? []);
		$relations = array_map(fn (array $uuids): array => $this->sorted(values: $uuids), $relations);
		if ($this->relationsMatch(existingData: $existingData, relations: $relations) === true) {
			return;
		}

		$this->concepts->save(data: array_replace($existingData, $relations), uuid: $uuid);
	}//end applyRelationFieldsToDraft()

	/**
	 * Whether every stored relation field already holds exactly `$relations`.
	 *
	 * @param array<string,mixed> $existingData The stored concept data.
	 * @param array<string,array<string>> $relations Sorted relation field => uuids.
	 *
	 * @return bool
	 */
	private function relationsMatch(array $existingData, array $relations): bool {
		foreach ($relations as $field => $uuids) {
			if ($this->sorted(values: $this->normalizeUuidList(value: ($existingData[$field] ?? []))) !== $uuids) {
				return false;
			}
		}

		return true;
	}//end relationsMatch()

	/**
	 * A sorted copy of a list.
	 *
	 * @param array<string> $values The list.
	 *
	 * @return array<string>
	 */
	private function sorted(array $values): array {
		sort($values);
		return $values;
	}//end sorted()

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
}//end class
