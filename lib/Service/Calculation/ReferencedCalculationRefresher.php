<?php

/**
 * OpenRegister Referenced Calculation Refresher
 *
 * A materialised calculation that reads `@ref.<name>.<field>` is evaluated
 * when the object carrying it is saved, so it goes stale when the referenced
 * object changes that field later: a case's `hasStartableFlows` stays false
 * after its case type starts listing flows. This refresher re-saves the
 * objects that point at an updated object, through the declared
 * `x-openregister-references` (mode `relatedObject`) of their schema, when
 * one of their materialised calculations reads a field that changed. The
 * save path then materialises the new value.
 *
 * At most SYNC_LIMIT objects per dependency are re-saved in the request; a
 * larger set is logged with the command that refreshes the rest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-saves the objects whose materialised calculations read a field of an updated object.
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A re-save reads the referrers, resolves their schemas
 *   and hands each row back in its stored form; each collaborator is one of those steps.
 */
class ReferencedCalculationRefresher implements ReferencedCalculationRefresherInterface {

	/**
	 * The most objects re-saved per dependency within one save.
	 */
	public const SYNC_LIMIT = 200;

	/**
	 * How deep a refresh may cascade (a refreshed object refreshing its own referrers).
	 */
	private const MAX_DEPTH = 2;

	/**
	 * The current cascade depth.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * The dependencies on a target schema found in this request, by register id and target schema id.
	 *
	 * @var array<string, array<int, array{schema: Schema, field: string, reads: array<int, string>}>>
	 */
	private array $found = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog   The registers, the schemas and the rows that find the referring objects.
	 * @param ContainerInterface     $container Hands out the object service on first use.
	 * @param LoggerInterface        $logger    The logger.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Re-save the objects in the updated object's register whose materialised calculations read a changed field of it.
	 *
	 * @param ObjectEntity      $updated The object as saved.
	 * @param ObjectEntity|null $old     The object before the save, null when unknown (every field counts as changed).
	 *
	 * @return integer The number of objects re-saved.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function refresh(ObjectEntity $updated, ?ObjectEntity $old): int {
		$uuid = (string) $updated->getUuid();
		if ($this->mayRefresh(uuid: $uuid) === false) {
			return 0;
		}

		$table = $this->tableOf(object: $updated);
		if ($table === null) {
			return 0;
		}

		// The guard: nothing is read or written unless a materialised `@ref`
		// in this register reads a field of this schema that changed.
		$changed = array_values(array_intersect($this->fieldsReadFrom(table: $table), $this->fieldsChangedBy(updated: $updated, old: $old)));
		if ($changed === []) {
			return 0;
		}

		return $this->refreshOneLevelDeeper(table: $table, change: new ReferencedChange(uuid: $uuid, changed: $changed));
	}//end refresh()

	/**
	 * Whether an object can start a refresh: it has a uuid and the nesting limit is not reached.
	 *
	 * @param string $uuid The updated object's uuid.
	 *
	 * @return bool
	 */
	private function mayRefresh(string $uuid): bool {
		return $uuid !== '' && self::$depth < self::MAX_DEPTH;
	}//end mayRefresh()

	/**
	 * The register and schema an object lives in, or null when either does not resolve.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return RegisterSchema|null
	 */
	private function tableOf(ObjectEntity $object): ?RegisterSchema {
		try {
			$target   = $this->catalog->schemas()->find(id: (string) $object->getSchema(), _rbac: false, _multitenancy: false);
			$register = $this->catalog->registers()->find(id: (string) $object->getRegister(), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		return new RegisterSchema(register: $register, schema: $target);
	}//end tableOf()

	/**
	 * Every field of the table's schema that a dependent in its register reads.
	 *
	 * @param RegisterSchema $table The updated object's register and schema.
	 *
	 * @return array<int, string>
	 */
	private function fieldsReadFrom(RegisterSchema $table): array {
		return $this->readFields(dependents: $this->dependentsOf(register: $table->register, target: $table->schema));
	}//end fieldsReadFrom()

	/**
	 * The fields a save changed; every field when the previous state is unknown.
	 *
	 * @param ObjectEntity      $updated The object as saved.
	 * @param ObjectEntity|null $old     The object before the save, or null.
	 *
	 * @return array<int, string>
	 */
	private function fieldsChangedBy(ObjectEntity $updated, ?ObjectEntity $old): array {
		return $this->changedFields(new: ($updated->getObject() ?? []), old: $old?->getObject());
	}//end fieldsChangedBy()

	/**
	 * Refresh the dependents one nesting level deeper, so a chain of refreshes stops at MAX_DEPTH.
	 *
	 * @param RegisterSchema   $table  The updated object's register and schema.
	 * @param ReferencedChange $change The updated object's uuid and the fields that changed.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function refreshOneLevelDeeper(RegisterSchema $table, ReferencedChange $change): int {
		self::$depth++;
		try {
			return $this->refreshIn(table: $table, change: $change);
		} finally {
			self::$depth--;
		}
	}//end refreshOneLevelDeeper()

	/**
	 * Every field of the target schema some dependent reads.
	 *
	 * @param array<int, array{schema: Schema, field: string, reads: array<int, string>}> $dependents The dependents.
	 *
	 * @return array<int, string>
	 */
	private function readFields(array $dependents): array {
		$read = [];
		foreach ($dependents as $dependent) {
			$read = [...$read, ...$dependent['reads']];
		}

		return array_values(array_unique($read));
	}//end readFields()

	/**
	 * Re-save, per schema of the register, the objects that point at the updated object and read a changed field.
	 *
	 * @param RegisterSchema   $table  The updated object's register and schema.
	 * @param ReferencedChange $change The updated object's uuid and the fields that changed.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function refreshIn(RegisterSchema $table, ReferencedChange $change): int {
		$saved = 0;
		foreach ($this->dependentsOf(register: $table->register, target: $table->schema) as $dependent) {
			if (array_intersect($dependent['reads'], $change->changed) === []) {
				continue;
			}

			$referrers = new RegisterSchema(register: $table->register, schema: $dependent['schema']);
			$saved    += $this->resave(table: $referrers, pointer: new ReferencePointer(field: $dependent['field'], uuid: $change->uuid));
		}

		return $saved;
	}//end refreshIn()

	/**
	 * Every schema of the register that reads the target schema through a materialised calculation, once per request.
	 *
	 * @param Register $register The register.
	 * @param Schema   $target   The updated object's schema.
	 *
	 * @return array<int, array{schema: Schema, field: string, reads: array<int, string>}>
	 */
	private function dependentsOf(Register $register, Schema $target): array {
		$key = $register->getId().'/'.$target->getId();
		if (isset($this->found[$key]) === true) {
			return $this->found[$key];
		}

		$dependents = [];
		foreach ($register->getSchemas() as $schemaId) {
			try {
				$schema = $this->catalog->schemas()->find(id: $schemaId, _rbac: false, _multitenancy: false);
			} catch (Throwable) {
				continue;
			}

			foreach ($this->dependencies(schema: $schema, target: $target) as $field => $reads) {
				$dependents[] = ['schema' => $schema, 'field' => $field, 'reads' => $reads];
			}
		}

		$this->found[$key] = $dependents;
		return $dependents;
	}//end dependentsOf()

	/**
	 * The fields of the target schema a schema's materialised calculations read, by the field that points at it.
	 *
	 * @param Schema $schema The schema that may point at the target.
	 * @param Schema $target The updated object's schema.
	 *
	 * @return array<string, array<int, string>> The pointing field => the target fields read.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function dependencies(Schema $schema, Schema $target): array {
		$references = self::listOrEmpty(value: (($schema->getConfiguration() ?? [])['x-openregister-references'] ?? []));
		if ($references === []) {
			return [];
		}

		$text  = (string) json_encode($this->materialisedExpressions(schema: $schema));
		$found = [];
		foreach ($references as $name => $spec) {
			$field = $this->pointingField(spec: $spec, target: $target);
			if ($field !== null) {
				$found = self::withFieldReads(found: $found, reads: [$field => self::refReads(name: (string) $name, text: $text)]);
			}
		}

		return $found;
	}//end dependencies()

	/**
	 * The target fields an encoded expression text reads through one reference, as `@ref.<name>.<field>`.
	 *
	 * @param string $name The reference name.
	 * @param string $text The JSON-encoded materialised expressions.
	 *
	 * @return array<int, string> The fields read, in order of appearance.
	 */
	private static function refReads(string $name, string $text): array {
		preg_match_all('/"@ref\.'.preg_quote($name, '/').'\.([A-Za-z0-9_]+)/', $text, $matches);
		return $matches[1];
	}//end refReads()

	/**
	 * Merge fields read, by pointing field, into the found map; a field with no reads adds nothing.
	 *
	 * @param array<string, array<int, string>> $found The reads found so far.
	 * @param array<string, array<int, string>> $reads The pointing field => the target fields it reads.
	 *
	 * @return array<string, array<int, string>> The merged map, each list distinct.
	 */
	private static function withFieldReads(array $found, array $reads): array {
		foreach ($reads as $field => $read) {
			if ($read !== []) {
				$found[$field] = array_values(array_unique([...($found[$field] ?? []), ...$read]));
			}
		}

		return $found;
	}//end withFieldReads()

	/**
	 * A configured value when it is an array, else an empty list.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return array<mixed>
	 */
	private static function listOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOrEmpty()

	/**
	 * The expressions of a schema's enabled materialised calculations.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, mixed>
	 */
	private function materialisedExpressions(Schema $schema): array {
		$annotation = self::listOrEmpty(value: (($schema->getConfiguration() ?? [])['x-openregister-calculations'] ?? []));
		$properties = self::listOrEmpty(value: ($schema->getProperties() ?? []));

		$expressions = [];
		foreach ((new PropertyCalculations())->merge(annotation: $annotation, properties: $properties) as $spec) {
			if (self::isEnabledMaterialised(spec: $spec) === true) {
				$expressions[] = ($spec['expression'] ?? null);
			}
		}

		return $expressions;
	}//end materialisedExpressions()

	/**
	 * Whether a calculation declaration is materialised and not disabled.
	 *
	 * @param mixed $spec The calculation declaration.
	 *
	 * @return bool
	 */
	private static function isEnabledMaterialised(mixed $spec): bool {
		return is_array($spec) === true && ($spec['materialise'] ?? false) === true && ($spec['enabled'] ?? true) !== false;
	}//end isEnabledMaterialised()

	/**
	 * The field a declared reference follows to the target schema, null when it does not point there.
	 *
	 * @param mixed  $spec   The reference declaration.
	 * @param Schema $target The updated object's schema.
	 *
	 * @return string|null
	 */
	private function pointingField(mixed $spec, Schema $target): ?string {
		if (self::isRelatedObjectReference(spec: $spec) === false) {
			return null;
		}

		$names = array_filter([strtolower((string) $target->getSlug()), (string) $target->getId(), (string) $target->getUuid()]);
		if (in_array(strtolower((string) ($spec['schema'] ?? '')), $names, true) === false) {
			return null;
		}

		return $spec['field'];
	}//end pointingField()

	/**
	 * Whether a reference declaration follows a related object through a named field.
	 *
	 * @param mixed $spec The reference declaration.
	 *
	 * @return bool
	 */
	private static function isRelatedObjectReference(mixed $spec): bool {
		return is_array($spec) === true && ($spec['mode'] ?? '') === 'relatedObject' && is_string($spec['field'] ?? null) === true;
	}//end isRelatedObjectReference()

	/**
	 * Re-save the objects of a schema whose field points at the updated object.
	 *
	 * @param RegisterSchema   $table   The register and the pointing schema.
	 * @param ReferencePointer $pointer The pointing field and the updated object's uuid.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function resave(RegisterSchema $table, ReferencePointer $pointer): int {
		$rows = $this->referrersOf(table: $table, pointer: $pointer);
		if ($rows === null) {
			return 0;
		}

		if (count($rows) >= self::SYNC_LIMIT) {
			$this->logger->warning(
				'[ReferencedCalculationRefresher] more objects point at '.$pointer->uuid
					.' than one save refreshes; run occ openregister:rematerialise-calculations for the rest',
				['register' => $table->register->getSlug(), 'schema' => $table->schema->getSlug()]
			);
		}

		return $this->resaveRows(schema: $table->schema, rows: $rows);
	}//end resave()

	/**
	 * The objects of a schema whose field points at the updated object, at most SYNC_LIMIT of them.
	 *
	 * @param RegisterSchema   $table   The register and the pointing schema.
	 * @param ReferencePointer $pointer The pointing field and the updated object's uuid.
	 *
	 * @return array<int, ObjectEntity>|null The referring objects, or null when they could not be read.
	 */
	private function referrersOf(RegisterSchema $table, ReferencePointer $pointer): ?array {
		try {
			return $this->catalog->tables()->findAllInRegisterSchemaTable(
				table: $table,
				listing: new ListQuery(limit: self::SYNC_LIMIT, filters: $pointer->filters())
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ReferencedCalculationRefresher] referrers could not be read',
				['schema' => $table->schema->getSlug(), 'reason' => $e->getMessage()]
			);
			return null;
		}
	}//end referrersOf()

	/**
	 * Re-save referring objects as the system, each in its stored form; a failed save is logged and not counted.
	 *
	 * @param Schema                   $schema The referring objects' schema.
	 * @param array<int, ObjectEntity> $rows   The referring objects.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function resaveRows(Schema $schema, array $rows): int {
		$saved = 0;
		foreach ($rows as $row) {
			if ($this->resaveRow(schema: $schema, row: $row) === true) {
				$saved++;
			}
		}

		return $saved;
	}//end resaveRows()

	/**
	 * Re-save one referring object as the system, in its stored form.
	 *
	 * @param Schema       $schema The referring object's schema.
	 * @param ObjectEntity $row    The referring object.
	 *
	 * @return boolean True when it was saved, false when the save failed (logged).
	 */
	private function resaveRow(Schema $schema, ObjectEntity $row): bool {
		$service = $this->container->get(ObjectOperationsInterface::class);
		try {
			// The read decodes JSON held in a `type: string` property; hand it back as stored.
			$service->saveObject(
				object: (new SchemaTypeConverter())->restoreStringTypedValues(data: ($row->getObject() ?? []), properties: ($schema->getProperties() ?? [])),
				register: $row->getRegister(),
				schema: $row->getSchema(),
				uuid: $row->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[ReferencedCalculationRefresher] re-save failed', ['uuid' => $row->getUuid(), 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end resaveRow()

	/**
	 * The top-level fields whose value differs between the old and the new data.
	 *
	 * @param array<string, mixed>      $new The data as saved.
	 * @param array<string, mixed>|null $old The data before, null when unknown.
	 *
	 * @return array<int, string>
	 */
	private function changedFields(array $new, ?array $old): array {
		if ($old === null) {
			return array_map('strval', array_keys($new));
		}

		$changed = [];
		foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $key) {
			if (json_encode($new[$key] ?? null) !== json_encode($old[$key] ?? null)) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}//end changedFields()
}//end class
