<?php

/**
 * OpenRegister ConceptDeleteGuard
 *
 * Answers the two questions that stand between a code-list value and its
 * deletion: is it one the product itself defines, and is anything holding it.
 *
 * Both answers are refusals, and they are refusals of DELETE only. Retiring a
 * value is what the validity window is for, and it is always available: close
 * the window and the value stops being offered while every record that holds
 * it keeps reading correctly. Deleting it is the operation that cannot be
 * made safe, because a row that disappears takes every reference to it with
 * it, and a gemeente only discovers that seven years later in an audit.
 *
 * The in-use count is the useful half of the refusal. "Cannot delete" tells
 * someone nothing; "cannot delete, 1,284 objects hold it" tells them what to
 * do next, which is to retire it instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\VocabularyImportService;
use Throwable;

/**
 * Recognises a concept delete and answers what blocks it.
 */
class ConceptDeleteGuard implements ConceptDeleteGuardInterface {

	/**
	 * How many holding schemas the refusal names.
	 *
	 * @var integer
	 */
	public const BLOCKER_SAMPLE = 10;

	/**
	 * Constructor.
	 *
	 * @param ConceptLifecycle  $lifecycle Answers whether a value is system-defined.
	 * @param CodedSchemaTables $tables    Finds the schemas bound to a scheme, reads their declarations and counts their holders.
	 */
	public function __construct(
		private readonly ConceptLifecycle $lifecycle,
		private readonly CodedSchemaTables $tables,
	) {

	}//end __construct()

	/**
	 * Whether this object is a concept.
	 *
	 * @param Schema $schema The schema of the object being deleted.
	 *
	 * @return boolean True when the object is a concept.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isConcept(Schema $schema): bool {
		return ((string)$schema->getSlug() === VocabularyImportService::SCHEMA_CONCEPT);
	}//end isConcept()

	/**
	 * Whether the value is one the product defines and therefore may not be deleted.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return boolean True when the delete must be refused as system-defined.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isSystemDefined(array $concept): bool {
		return $this->lifecycle->isSystemDefined(concept: $concept);
	}//end isSystemDefined()

	/**
	 * Count the objects that hold this concept, across every schema bound to
	 * its scheme.
	 *
	 * Only schemas that DECLARE a coded property on the concept's scheme are
	 * counted, which is what keeps this a bounded question rather than a scan
	 * of every table on the instance.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param string $schemeUri The uri of the scheme the concept belongs to.
	 *
	 * @return array{count:int,holders:array<int,array{schema:string,property:string,count:int}>}
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function usage(array $concept, string $schemeUri): array {
		$holding = ConceptHolding::fromConcept(concept: $concept, schemeUri: $schemeUri);
		if ($holding->isAnonymous() === true) {
			return ['count' => 0, 'holders' => []];
		}

		try {
			$all = $this->tables->schemas->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $unreadable) {
			return ['count' => 0, 'holders' => []];
		}

		$total = 0;
		$holders = [];
		foreach ($all as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			foreach ($this->holdersInSchema(schema: $schema, holding: $holding) as $holder) {
				$total += $holder['count'];
				if (count($holders) < self::BLOCKER_SAMPLE) {
					$holders[] = $holder;
				}
			}
		}//end foreach

		return ['count' => $total, 'holders' => $holders];
	}//end usage()

	/**
	 * The holding properties of one schema, each with its object count.
	 *
	 * Extracted from {@see self::usage()} so the per-schema work — pick the
	 * properties bound to the scheme, pick the stored value for each, count
	 * its holders — reads as one block and the caller only accumulates.
	 *
	 * @param Schema         $schema  The schema to inspect.
	 * @param ConceptHolding $holding The concept, as the values that would hold it.
	 *
	 * @return array<int,array{schema:string,property:string,count:int}> The non-empty holders.
	 */
	private function holdersInSchema(Schema $schema, ConceptHolding $holding): array {
		$holders = [];
		$declarations = $this->tables->declarations->fromProperties(properties: ($schema->getProperties() ?? []));
		foreach ($declarations as $property => $declaration) {
			$stored = $this->storedValue(declaration: $declaration, holding: $holding);
			if ($stored === null) {
				continue;
			}

			$count = $this->countHolders(schema: $schema, filters: [$property => $stored]);
			if ($count !== 0) {
				$holders[] = ['schema' => (string)$schema->getSlug(), 'property' => $property, 'count' => $count];
			}
		}

		return $holders;
	}//end holdersInSchema()

	/**
	 * The value a property would hold the concept by, or null when it cannot hold it.
	 *
	 * A property bound to another scheme cannot hold it, and neither can a
	 * property storing a form the concept lacks (a notation-stored property
	 * for a concept without a notation).
	 *
	 * @param CodedPropertyDeclaration $declaration The property's coded declaration.
	 * @param ConceptHolding           $holding     The concept, as the values that would hold it.
	 *
	 * @return string|null The stored value to look for.
	 */
	private function storedValue(CodedPropertyDeclaration $declaration, ConceptHolding $holding): ?string {
		if ($declaration->scheme !== $holding->schemeUri) {
			return null;
		}

		$stored = $holding->uri;
		if ($declaration->store === 'notation') {
			$stored = $holding->notation;
		}

		if ($stored === '') {
			return null;
		}

		return $stored;
	}//end storedValue()

	/**
	 * The refusal, in words, naming the count.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param array{count:int,holders:array<int,array{schema:string,property:string,count:int}>} $usage The usage figures.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function inUseMessage(array $concept, array $usage): string {
		$label = trim((string)($concept['uri'] ?? 'this value'));

		$where = [];
		foreach (($usage['holders'] ?? []) as $holder) {
			$where[] = sprintf('%s.%s (%d)', $holder['schema'], $holder['property'], $holder['count']);
		}

		$message = sprintf(
			'The value "%s" is held by %d object(s) and cannot be deleted. Close its '
			.'validity window to retire it instead, which keeps those records readable.',
			$label,
			(int)($usage['count'] ?? 0)
		);

		if ($where !== []) {
			$message .= ' Held on: ' . implode(', ', $where) . '.';
		}

		return $message;
	}//end inUseMessage()

	/**
	 * The refusal, in words, for a value the product defines.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function systemDefinedMessage(array $concept): string {
		return sprintf(
			'The value "%s" is defined by the system and cannot be deleted. Close its validity window to retire it instead.',
			trim((string)($concept['uri'] ?? 'this value'))
		);
	}//end systemDefinedMessage()

	/**
	 * Count the objects of one schema whose property holds the value.
	 *
	 * @param Schema                $schema  The holding schema.
	 * @param array<string,string> $filters The coded property's name mapped to the stored value, as the mapper filters on it.
	 *
	 * @return integer The count, zero when the table cannot be read.
	 */
	private function countHolders(Schema $schema, array $filters): int {
		try {
			$count = $this->tables->objects->countAll(
				_filters: $filters,
				schema: $schema
			);
		} catch (Throwable $unreadable) {
			return 0;
		}

		return (int)$count;
	}//end countHolders()
}//end class
