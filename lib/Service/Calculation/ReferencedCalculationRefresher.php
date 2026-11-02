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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\ObjectService;
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
class ReferencedCalculationRefresher {

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
	 * @param RegisterMapper     $registers The registers.
	 * @param SchemaMapper       $schemas   The schemas.
	 * @param MagicMapper        $rows      Finds the referring objects.
	 * @param ContainerInterface $container Hands out the object service on first use.
	 * @param LoggerInterface    $logger    The logger.
	 * @param SchemaTypeConverter $types    Restores the stored form of `type: string` values the read decoded.
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly MagicMapper $rows,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly SchemaTypeConverter $types=new SchemaTypeConverter(),
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
		if ($uuid === '' || self::$depth >= self::MAX_DEPTH) {
			return 0;
		}

		try {
			$target   = $this->schemas->find(id: (string) $updated->getSchema(), _rbac: false, _multitenancy: false);
			$register = $this->registers->find(id: (string) $updated->getRegister(), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return 0;
		}

		// The guard: nothing is read or written unless a materialised `@ref`
		// in this register reads a field of this schema that changed.
		$read    = $this->readFields(dependents: $this->dependentsOf(register: $register, target: $target));
		$changed = array_values(array_intersect($read, $this->changedFields(new: ($updated->getObject() ?? []), old: $old?->getObject())));
		if ($changed === []) {
			return 0;
		}

		self::$depth++;
		try {
			return $this->refreshIn(register: $register, target: $target, uuid: $uuid, changed: $changed);
		} finally {
			self::$depth--;
		}
	}//end refresh()

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
	 * @param Register           $register The register.
	 * @param Schema             $target   The updated object's schema.
	 * @param string             $uuid     The updated object's uuid.
	 * @param array<int, string> $changed  The fields that changed.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function refreshIn(Register $register, Schema $target, string $uuid, array $changed): int {
		$saved = 0;
		foreach ($this->dependentsOf(register: $register, target: $target) as $dependent) {
			if (array_intersect($dependent['reads'], $changed) === []) {
				continue;
			}

			$saved += $this->resave(register: $register, schema: $dependent['schema'], field: $dependent['field'], uuid: $uuid);
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
				$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
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
		$references = (($schema->getConfiguration() ?? [])['x-openregister-references'] ?? []);
		if (is_array($references) === false || $references === []) {
			return [];
		}

		$text  = (string) json_encode($this->materialisedExpressions(schema: $schema));
		$found = [];
		foreach ($references as $name => $spec) {
			$field = $this->pointingField(spec: $spec, target: $target);
			if ($field === null) {
				continue;
			}

			preg_match_all('/"@ref\.'.preg_quote((string) $name, '/').'\.([A-Za-z0-9_]+)/', $text, $matches);
			if ($matches[1] !== []) {
				$found[$field] = array_values(array_unique([...($found[$field] ?? []), ...$matches[1]]));
			}
		}

		return $found;
	}//end dependencies()

	/**
	 * The expressions of a schema's enabled materialised calculations.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, mixed>
	 */
	private function materialisedExpressions(Schema $schema): array {
		$annotation = (($schema->getConfiguration() ?? [])['x-openregister-calculations'] ?? []);
		if (is_array($annotation) === false) {
			$annotation = [];
		}

		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$expressions = [];
		foreach ((new PropertyCalculations())->merge(annotation: $annotation, properties: $properties) as $spec) {
			if (is_array($spec) === true && ($spec['materialise'] ?? false) === true && ($spec['enabled'] ?? true) !== false) {
				$expressions[] = ($spec['expression'] ?? null);
			}
		}

		return $expressions;
	}//end materialisedExpressions()

	/**
	 * The field a declared reference follows to the target schema, null when it does not point there.
	 *
	 * @param mixed  $spec   The reference declaration.
	 * @param Schema $target The updated object's schema.
	 *
	 * @return string|null
	 */
	private function pointingField(mixed $spec, Schema $target): ?string {
		if (is_array($spec) === false || ($spec['mode'] ?? '') !== 'relatedObject' || is_string($spec['field'] ?? null) === false) {
			return null;
		}

		$names = array_filter([strtolower((string) $target->getSlug()), (string) $target->getId(), (string) $target->getUuid()]);
		if (in_array(strtolower((string) ($spec['schema'] ?? '')), $names, true) === false) {
			return null;
		}

		return $spec['field'];
	}//end pointingField()

	/**
	 * Re-save the objects of a schema whose field points at the updated object.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The pointing schema.
	 * @param string   $field    The pointing field.
	 * @param string   $uuid     The updated object's uuid.
	 *
	 * @return integer The number of objects re-saved.
	 */
	private function resave(Register $register, Schema $schema, string $field, string $uuid): int {
		try {
			$rows = $this->rows->findAllInRegisterSchemaTable(register: $register, schema: $schema, limit: self::SYNC_LIMIT, filters: [$field => $uuid]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ReferencedCalculationRefresher] referrers could not be read',
				['schema' => $schema->getSlug(), 'reason' => $e->getMessage()]
			);
			return 0;
		}

		if (count($rows) >= self::SYNC_LIMIT) {
			$this->logger->warning(
				'[ReferencedCalculationRefresher] more objects point at '.$uuid
					.' than one save refreshes; run occ openregister:rematerialise-calculations for the rest',
				['register' => $register->getSlug(), 'schema' => $schema->getSlug()]
			);
		}

		$service = $this->container->get(ObjectService::class);
		$saved   = 0;
		foreach ($rows as $row) {
			try {
				// The read decodes JSON held in a `type: string` property; hand it back as stored.
				$service->saveObject(
					object: $this->types->restoreStringTypedValues(data: ($row->getObject() ?? []), properties: ($schema->getProperties() ?? [])),
					register: $row->getRegister(),
					schema: $row->getSchema(),
					uuid: $row->getUuid(),
					_rbac: false,
					_multitenancy: false
				);
				$saved++;
			} catch (Throwable $e) {
				$this->logger->warning('[ReferencedCalculationRefresher] re-save failed', ['uuid' => $row->getUuid(), 'reason' => $e->getMessage()]);
			}
		}

		return $saved;
	}//end resave()

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
