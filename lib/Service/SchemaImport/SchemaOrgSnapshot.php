<?php

/**
 * OpenRegister Schema Import — SchemaOrgSnapshot loader.
 *
 * Loads and indexes a bundled `schemaorg-current-https` JSON-LD release file
 * into a queryable model: classes (with subClassOf), properties (with
 * domainIncludes / rangeIncludes), and labels/comments. Pure parsing — the
 * file path is injected so unit tests can point at fixtures. Lazy + cached so
 * repeated lookups within a request parse once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * Indexed view over a bundled Schema.org vocabulary release.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class SchemaOrgSnapshot {

	/**
	 * The Schema.org namespace IRI prefix.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'https://schema.org/';

	/**
	 * Classes indexed by bare name: name => ['iri','label','comment','parent'].
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $classes = null;

	/**
	 * Properties indexed by bare name: name => ['iri','label','comment','domains','ranges'].
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $properties = null;

	/**
	 * Constructor.
	 *
	 * @param string $releaseFile The absolute path to the JSON-LD release file.
	 * @param string $version The snapshot version identifier.
	 */
	public function __construct(
		private readonly string $releaseFile,
		private readonly string $version,
	) {
	}//end __construct()

	/**
	 * The snapshot version identifier.
	 *
	 * @return string The version.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function version(): string {
		return $this->version;
	}//end version()

	/**
	 * Resolve a class (type) by IRI or bare name.
	 *
	 * @param string $reference The class IRI or bare name.
	 *
	 * @return array<string, mixed>|null The class record, or null when unknown.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function findClass(string $reference): ?array {
		$this->ensureParsed();
		$name = $this->bareName(reference: $reference);
		return ($this->classes[$name] ?? null);
	}//end findClass()

	/**
	 * The direct properties whose domain includes the given class.
	 *
	 * @param string $className The bare class name.
	 *
	 * @return array<string, array<string, mixed>> Property records keyed by bare name.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function directPropertiesOf(string $className): array {
		$this->ensureParsed();

		$result = [];
		foreach ($this->properties as $name => $property) {
			if (in_array($className, $property['domains'], true) === true) {
				$result[$name] = $property;
			}
		}

		return $result;
	}//end directPropertiesOf()

	/**
	 * The properties of a class plus all its ancestors' properties.
	 *
	 * @param string $className The bare class name.
	 *
	 * @return array<string, array<string, mixed>> Property records keyed by bare name.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function propertiesWithAncestors(string $className): array {
		$this->ensureParsed();

		$result = [];
		foreach ($this->ancestryOf(className: $className) as $ancestor) {
			foreach ($this->directPropertiesOf(className: $ancestor) as $name => $property) {
				if (isset($result[$name]) === false) {
					$result[$name] = $property;
				}
			}
		}

		return $result;
	}//end propertiesWithAncestors()

	/**
	 * The class chain from the given class up to its root ancestor.
	 *
	 * @param string $className The bare class name.
	 *
	 * @return array<int, string> Bare class names, the class itself first.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function ancestryOf(string $className): array {
		$this->ensureParsed();

		$chain = [];
		$current = $className;
		$guard = 0;
		while ($current !== null && isset($this->classes[$current]) === true && $guard < 50) {
			$chain[] = $current;
			$current = $this->parentOf(className: $current);
			$guard++;
		}

		return $chain;
	}//end ancestryOf()

	/**
	 * The bare name of a parsed class's parent, or null for a root class.
	 *
	 * @param string $className A class present in the parsed snapshot.
	 *
	 * @return string|null The parent's bare name.
	 */
	private function parentOf(string $className): ?string {
		$parent = $this->classes[$className]['parent'];
		if ($parent !== null) {
			return $this->bareName(reference: $parent);
		}

		return null;
	}//end parentOf()

	/**
	 * Search classes by a case-insensitive name/comment substring.
	 *
	 * @param string $query A search term; empty returns a bounded sample.
	 * @param int $limit Maximum number of results.
	 *
	 * @return array<int, array<string, mixed>> Matching class records.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function searchClasses(string $query, int $limit = 50): array {
		$this->ensureParsed();

		$needle = strtolower(trim($query));
		$results = [];
		foreach ($this->classes as $name => $class) {
			if ($this->classMatches(texts: [(string)$name, (string)($class['comment'] ?? '')], needle: $needle) === true) {
				$results[] = $class;
			}

			if (count($results) >= $limit) {
				break;
			}
		}

		return $results;
	}//end searchClasses()

	/**
	 * Whether a class matches a lowercased search needle (empty matches all).
	 *
	 * @param array<int, string> $texts  The bare class name and its comment.
	 * @param string             $needle The lowercased, trimmed needle.
	 *
	 * @return bool True when the name or comment contains the needle.
	 */
	private function classMatches(array $texts, string $needle): bool {
		if ($needle === '') {
			return true;
		}

		foreach ($texts as $text) {
			if (str_contains(strtolower($text), $needle) === true) {
				return true;
			}
		}

		return false;
	}//end classMatches()

	/**
	 * The bare term name from an IRI or `schema:`-prefixed term.
	 *
	 * @param string $reference The IRI / prefixed term / bare name.
	 *
	 * @return string The bare name.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function bareName(string $reference): string {
		$reference = trim($reference);
		if (str_starts_with($reference, self::NAMESPACE) === true) {
			return substr($reference, strlen(self::NAMESPACE));
		}

		if (str_starts_with($reference, 'http://schema.org/') === true) {
			return substr($reference, strlen('http://schema.org/'));
		}

		if (str_starts_with($reference, 'schema:') === true) {
			return substr($reference, strlen('schema:'));
		}

		return $reference;
	}//end bareName()

	/**
	 * The canonical class IRI for a bare class name.
	 *
	 * @param string $className The bare class name.
	 *
	 * @return string The class IRI.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function classIri(string $className): string {
		return self::NAMESPACE . $className;
	}//end classIri()

	/**
	 * Parse + index the release file once.
	 *
	 * @return void
	 *
	 * @throws SchemaImportException When the file is missing or unparseable.
	 */
	private function ensureParsed(): void {
		if ($this->classes !== null && $this->properties !== null) {
			return;
		}

		$graph = $this->decodeReleaseGraph();

		$this->classes = [];
		$this->properties = [];

		foreach ($graph as $node) {
			$this->indexNode(node: $node);
		}
	}//end ensureParsed()

	/**
	 * Read and decode the release file, returning its `@graph`.
	 *
	 * @return mixed The `@graph` value.
	 *
	 * @throws SchemaImportException When the file is missing or unparseable.
	 */
	private function decodeReleaseGraph(): mixed {
		$decoded = json_decode($this->releaseContents(), associative: true);
		if (is_array($decoded) === false || isset($decoded['@graph']) === false) {
			throw new SchemaImportException(
				message: 'Schema.org snapshot is not a valid JSON-LD release file.',
				httpStatus: 500
			);
		}

		return $decoded['@graph'];
	}//end decodeReleaseGraph()

	/**
	 * The release file's raw contents.
	 *
	 * @return string The file's contents.
	 *
	 * @throws SchemaImportException When the release file is missing.
	 */
	private function releaseContents(): string {
		if (is_file($this->releaseFile) === false) {
			throw new SchemaImportException(
				message: 'Schema.org snapshot file is missing: ' . $this->releaseFile,
				httpStatus: 500
			);
		}

		return (string)file_get_contents($this->releaseFile);
	}//end releaseContents()

	/**
	 * Index one `@graph` node as a class or property; other nodes are skipped.
	 *
	 * @param mixed $node The JSON-LD node.
	 *
	 * @return void
	 */
	private function indexNode(mixed $node): void {
		if (is_array($node) === false || isset($node['@id']) === false) {
			return;
		}

		$type = $this->normaliseTypes(type: $node['@type'] ?? null);
		if (in_array('rdfs:Class', $type, true) === true) {
			$this->indexClass(node: $node);
			return;
		}

		if (in_array('rdf:Property', $type, true) === true) {
			$this->indexProperty(node: $node);
		}
	}//end indexNode()

	/**
	 * Index a class node.
	 *
	 * @param array<string, mixed> $node The JSON-LD class node.
	 *
	 * @return void
	 */
	private function indexClass(array $node): void {
		$iri = (string)$node['@id'];
		$name = $this->bareName(reference: $iri);
		$parent = null;
		if (isset($node['rdfs:subClassOf']['@id']) === true) {
			$parent = (string)$node['rdfs:subClassOf']['@id'];
		}

		$this->classes[$name] = [
			'iri' => $iri,
			'label' => (string)($node['rdfs:label'] ?? $name),
			'comment' => (string)($node['rdfs:comment'] ?? ''),
			'parent' => $parent,
		];
	}//end indexClass()

	/**
	 * Index a property node.
	 *
	 * @param array<string, mixed> $node The JSON-LD property node.
	 *
	 * @return void
	 */
	private function indexProperty(array $node): void {
		$iri = (string)$node['@id'];
		$name = $this->bareName(reference: $iri);

		$this->properties[$name] = [
			'iri' => $iri,
			'label' => (string)($node['rdfs:label'] ?? $name),
			'comment' => (string)($node['rdfs:comment'] ?? ''),
			'domains' => $this->idList(value: $node['schema:domainIncludes'] ?? null),
			'ranges' => $this->idList(value: $node['schema:rangeIncludes'] ?? null),
		];
	}//end indexProperty()

	/**
	 * Extract bare names from a single `{"@id":...}` or a list of them.
	 *
	 * @param mixed $value The domainIncludes / rangeIncludes value.
	 *
	 * @return array<int, string> Bare names.
	 */
	private function idList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		// Single { "@id": ... }.
		if (isset($value['@id']) === true) {
			return [$this->bareName(reference: (string)$value['@id'])];
		}

		return $this->entryIds(entries: $value);
	}//end idList()

	/**
	 * Extract bare names from a list of `{"@id":...}` entries, skipping others.
	 *
	 * @param array<int|string, mixed> $entries The list entries.
	 *
	 * @return array<int, string> Bare names.
	 */
	private function entryIds(array $entries): array {
		$names = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === true && isset($entry['@id']) === true) {
				$names[] = $this->bareName(reference: (string)$entry['@id']);
			}
		}

		return $names;
	}//end entryIds()

	/**
	 * Normalise a JSON-LD `@type` to a list of strings.
	 *
	 * @param mixed $type The `@type` value.
	 *
	 * @return array<int, string> The type strings.
	 */
	private function normaliseTypes(mixed $type): array {
		if (is_string($type) === true) {
			return [$type];
		}

		if (is_array($type) === true) {
			return array_values(array_filter($type, 'is_string'));
		}

		return [];
	}//end normaliseTypes()
}//end class
