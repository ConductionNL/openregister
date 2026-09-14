<?php

/**
 * Did this write give a reader something new to see?
 *
 * WHY THIS CLASS EXISTS AT ALL. Without it, every write marks an object unread
 * for everybody, and the first nightly recalculation of a computed field marks
 * four hundred cases unread overnight. A badge that lights up for reasons the
 * reader cannot see is a badge people learn to ignore, which costs more than
 * having no badge.
 *
 * WHERE THE ANSWER COMES FROM. The schema, per ADR-031: `x-openregister-read-state`
 * declares which properties and which sub-resources count. A leaf app's
 * controller never decides this, because two leaf apps reading the same register
 * would then disagree about what "new" means.
 *
 * WHAT AN UNDECLARED SCHEMA GETS. A change to any property that is not computed.
 * That is the safe default in the direction that matters: a schema author who
 * has declared nothing gets a badge that is occasionally too eager, never one
 * that is silently dead. Computed properties are excluded even undeclared,
 * because they are the one class of change the system makes to itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;

/**
 * Reads `x-openregister-read-state` and answers one question about one write.
 */
class SubstantiveChangeEvaluator {

	/**
	 * The schema annotation this evaluator reads.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-read-state';

	/**
	 * The sub-resource every object has, whether or not a schema declares one.
	 *
	 * Files are OpenRegister's own sub-resource: the folder exists for every
	 * object, so a schema that declares nothing still gets a files badge.
	 *
	 * @var string
	 */
	public const FILES = 'files';

	/**
	 * Resolved annotations per schema id, for this request.
	 *
	 * A bulk write touches one schema hundreds of times; re-reading the schema
	 * row per object would turn one save into an N+1 on the write path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $annotationMemo = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the object's schema.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether one write gave other readers something new to see.
	 *
	 * A write with no previous state is a creation, which is substantive by
	 * definition: nobody has seen it, and there is no read state to invalidate
	 * anyway.
	 *
	 * @param ObjectEntity $new The object as it now stands.
	 * @param ObjectEntity|null $old The object as it stood before, when known.
	 *
	 * @return boolean True when the change should make the object unread again.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function isSubstantive(ObjectEntity $new, ?ObjectEntity $old = null): bool {
		if ($old === null) {
			return true;
		}

		$changed = $this->changedProperties(new: $new, old: $old);
		if ($changed === []) {
			return false;
		}

		$schema = $this->resolveSchema(object: $new);
		$declared = $this->declaredProperties(schema: $schema);

		if ($declared !== []) {
			// A schema that names its properties means exactly those: a change
			// to anything else is a technical touch.
			return array_intersect($changed, $declared) !== [];
		}

		$computed = $this->computedProperties(schema: $schema);

		return array_diff($changed, $computed) !== [];

	}//end isSubstantive()

	/**
	 * The sub-resources this object's schema declares, with how to count them.
	 *
	 * Always includes `files`, which OpenRegister owns for every object. A
	 * declaration may add array-valued properties whose entries carry a date,
	 * which is how a case's messages and notes become tab badges without
	 * OpenRegister knowing what a message is.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, array<string, string>> Sub-resource name to its descriptor.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function subResources(ObjectEntity $object): array {
		$resources = [self::FILES => ['kind' => self::FILES]];

		$annotation = $this->annotation(schema: $this->resolveSchema(object: $object));
		$declared = ($annotation['subResources'] ?? null);
		if (is_array($declared) === false) {
			return $resources;
		}

		foreach ($declared as $name => $descriptor) {
			if (is_string($name) === false || $name === '') {
				continue;
			}

			if (is_array($descriptor) === false) {
				continue;
			}

			$kind = (string)($descriptor['kind'] ?? 'property');
			if ($kind === self::FILES) {
				$resources[$name] = ['kind' => self::FILES];
				continue;
			}

			// A property sub-resource must name the property it counts and the
			// date field on each entry. Without both there is nothing to
			// compare against the seen moment, and a badge that cannot be
			// computed must be absent rather than zero.
			$property = (string)($descriptor['property'] ?? $name);
			$dateField = (string)($descriptor['dateField'] ?? '');
			if ($property === '' || $dateField === '') {
				continue;
			}

			$resources[$name] = ['kind' => 'property', 'property' => $property, 'dateField' => $dateField];
		}//end foreach

		return $resources;

	}//end subResources()

	/**
	 * The property names whose values differ between two versions.
	 *
	 * Compared on the decoded object body rather than a serialised string, so a
	 * key reordering is not a change.
	 *
	 * @param ObjectEntity $new The object as it now stands.
	 * @param ObjectEntity $old The object as it stood before.
	 *
	 * @return array<int, string> The changed property names.
	 */
	private function changedProperties(ObjectEntity $new, ObjectEntity $old): array {
		$newBody = $this->body(object: $new);
		$oldBody = $this->body(object: $old);

		$names = array_unique(array_merge(array_keys($newBody), array_keys($oldBody)));
		$changed = [];
		foreach ($names as $name) {
			if (is_string($name) === false) {
				continue;
			}

			if (($newBody[$name] ?? null) !== ($oldBody[$name] ?? null)) {
				$changed[] = $name;
			}
		}

		return $changed;

	}//end changedProperties()

	/**
	 * One object's decoded body.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The body, or an empty array.
	 */
	private function body(ObjectEntity $object): array {
		$body = $object->getObject();

		if (is_array($body) === true) {
			return $body;
		}

		return [];

	}//end body()

	/**
	 * The properties the schema declares as substantive.
	 *
	 * @param Schema|null $schema The object's schema, when it resolved.
	 *
	 * @return array<int, string> The declared property names.
	 */
	private function declaredProperties(?Schema $schema): array {
		$declared = ($this->annotation(schema: $schema)['properties'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn ($name): bool => is_string($name) === true && $name !== ''));

	}//end declaredProperties()

	/**
	 * The names of the schema's computed properties.
	 *
	 * A computed value is written by the system, so it is the one class of
	 * change that can never be news to a reader.
	 *
	 * @param Schema|null $schema The object's schema, when it resolved.
	 *
	 * @return array<int, string> The computed property names.
	 */
	private function computedProperties(?Schema $schema): array {
		if ($schema === null) {
			return [];
		}

		$computed = [];
		foreach ($schema->getProperties() as $name => $property) {
			if (is_string($name) === false || is_array($property) === false) {
				continue;
			}

			if (isset($property['computed']) === true && is_array($property['computed']) === true) {
				$computed[] = $name;
			}
		}

		return $computed;

	}//end computedProperties()

	/**
	 * The `x-openregister-read-state` block, memoised per schema.
	 *
	 * @param Schema|null $schema The object's schema, when it resolved.
	 *
	 * @return array<string, mixed> The block, or an empty array.
	 */
	private function annotation(?Schema $schema): array {
		if ($schema === null) {
			return [];
		}

		$key = (string)$schema->getId();
		if (array_key_exists($key, $this->annotationMemo) === true) {
			return $this->annotationMemo[$key];
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$block = ($configuration[self::ANNOTATION] ?? null);
		if (is_array($block) === false) {
			$block = [];
		}

		$this->annotationMemo[$key] = $block;

		return $block;

	}//end annotation()

	/**
	 * The object's schema, or null when it cannot be resolved.
	 *
	 * A failure here means the evaluator falls back to the undeclared default,
	 * which is the eager direction. An object whose schema has gone missing is
	 * a bigger problem than an over-eager badge.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema, or null.
	 */
	private function resolveSchema(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || (string)$schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((string)$schemaId);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[SubstantiveChangeEvaluator] schema %s did not resolve: %s', (string)$schemaId, $e->getMessage())
			);
			return null;
		}

	}//end resolveSchema()
}//end class
