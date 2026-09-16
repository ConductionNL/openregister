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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\VocabularyImportService;
use Throwable;

/**
 * Recognises a concept delete and answers what blocks it.
 */
class ConceptDeleteGuard {

	/**
	 * How many holding schemas the refusal names.
	 *
	 * @var integer
	 */
	public const BLOCKER_SAMPLE = 10;

	/**
	 * Constructor.
	 *
	 * @param ConceptLifecycle $lifecycle Answers whether a value is system-defined.
	 * @param SchemaMapper $schemas Finds the schemas whose properties bind to a scheme.
	 * @param MagicMapper $objects Counts the objects holding a value.
	 */
	public function __construct(
		private readonly ConceptLifecycle $lifecycle,
		private readonly SchemaMapper $schemas,
		private readonly MagicMapper $objects,
		private readonly CodedPropertyDeclarationFactory $declarationFactory,
	) {

	}//end __construct()

	/**
	 * Whether this object is a concept.
	 *
	 * @param Schema $schema The schema of the object being deleted.
	 *
	 * @return boolean True when the object is a concept.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function usage(array $concept, string $schemeUri): array {
		$uri = trim((string)($concept['uri'] ?? ''));
		$notation = trim((string)($concept['notation'] ?? ''));
		if ($uri === '' && $notation === '') {
			return ['count' => 0, 'holders' => []];
		}

		try {
			$all = $this->schemas->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $unreadable) {
			return ['count' => 0, 'holders' => []];
		}

		$total = 0;
		$holders = [];
		foreach ($all as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			$declarations = $this->declarationFactory->fromProperties(properties: ($schema->getProperties() ?? []));
			foreach ($declarations as $property => $declaration) {
				if ($declaration->scheme !== $schemeUri) {
					continue;
				}

				$stored = $uri;
				if ($declaration->store === 'notation') {
					$stored = $notation;
				}

				if ($stored === '') {
					continue;
				}

				$count = $this->countHolders(schema: $schema, property: $property, value: $stored);
				if ($count === 0) {
					continue;
				}

				$total += $count;
				if (count($holders) < self::BLOCKER_SAMPLE) {
					$holders[] = [
						'schema' => (string)$schema->getSlug(),
						'property' => $property,
						'count' => $count,
					];
				}
			}//end foreach
		}//end foreach

		return ['count' => $total, 'holders' => $holders];
	}//end usage()

	/**
	 * The refusal, in words, naming the count.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param array{count:int,holders:array<int,array{schema:string,property:string,count:int}>} $usage The usage figures.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @param Schema $schema The holding schema.
	 * @param string $property The coded property's name.
	 * @param string $value The stored value.
	 *
	 * @return integer The count, zero when the table cannot be read.
	 */
	private function countHolders(Schema $schema, string $property, string $value): int {
		try {
			$count = $this->objects->countAll(
				_filters: [$property => $value],
				schema: $schema
			);
		} catch (Throwable $unreadable) {
			return 0;
		}

		return (int)$count;
	}//end countHolders()
}//end class
