<?php

/**
 * OpenRegister SurvivorshipRecomputeListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. When a schema
 * declares an `x-openregister-survivorship` annotation, loads the linked
 * source records from `sourceLinkField`, reads the per-object attribute
 * overrides from `overridesField` (default `attributeOverrides`), resolves
 * the golden record via the pure `SurvivorshipResolver` (backed by the
 * `trustConfiguration` register through `TrustTierResolver`, with the
 * override map short-circuiting tier selection), and materialises
 * `goldenRecordField` + `provenanceField` into the object payload before
 * persistence — only when the computed values differ from the stored ones.
 * The `overridesField` itself is steward input, not derived: it is read but
 * NEVER cleared or rewritten here, so an unrelated recompute always
 * preserves it. Mirrors the materialise-on-save contract of
 * {@see QualityScoreOnSaveListener}: runs before the write, is fail-soft
 * (logs a warning and continues on any error), and never aborts the save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Materialises a declared golden record + provenance into the object payload
 * on save.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reuses the survivorship
 *   collaborators (SchemaMapper, ObjectService, SurvivorshipResolver,
 *   TrustTierResolver, SourceRecordResolver) plus the logger, per ADR-011
 *   reuse — a facade purely to hide the dependency count would add indirection
 *   without reducing coupling, mirroring MergeService.
 */
class SurvivorshipRecomputeListener implements IEventListener {
	/**
	 * Default field the golden record is written to when the annotation omits `goldenRecordField`.
	 *
	 * @var string
	 */
	private const DEFAULT_GOLDEN_FIELD = 'goldenRecord';

	/**
	 * Default field the provenance map is written to when the annotation omits `provenanceField`.
	 *
	 * @var string
	 */
	private const DEFAULT_PROVENANCE_FIELD = 'attributeProvenance';

	/**
	 * Default field the per-object attribute-override map is read from when
	 * the annotation omits `overridesField`.
	 *
	 * @var string
	 */
	private const DEFAULT_OVERRIDES_FIELD = 'attributeOverrides';

	/**
	 * Slug of the OR-owned trust-configuration register schema.
	 *
	 * @var string
	 */
	private const TRUST_CONFIGURATION_SCHEMA = 'trustConfiguration';

	/**
	 * Wire collaborators used to look up the schema, load linked sources +
	 * trust rows, and resolve the golden record.
	 *
	 * @param SchemaMapperInterface         $schemaMapper         Schema lookup mapper.
	 * @param ObjectOperationsInterface     $objectService        Object read path (RBAC + tenant scoped).
	 * @param SurvivorshipResolverInterface $resolver             Pure golden-record resolver.
	 * @param TrustTierResolver             $trustResolver        Pure trust-tier lookup + decay engine.
	 * @param SourceRecordResolverInterface $sourceRecordResolver Mode-aware source-record resolver (embedded | reverseFk).
	 * @param LoggerInterface               $logger               PSR logger for warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly ObjectOperationsInterface $objectService,
		private readonly SurvivorshipResolverInterface $resolver,
		private readonly TrustTierResolver $trustResolver,
		private readonly SourceRecordResolverInterface $sourceRecordResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run survivorship resolution before the object is persisted.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	public function handle(Event $event): void {
		$object = self::savedObjectOf(event: $event);
		if ($object !== null) {
			$this->process(object: $object);
		}
	}//end handle()

	/**
	 * The object a pre-write event is about to persist, or null for any other event.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return ObjectEntity|null The object being created or the new state of the updated one.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private static function savedObjectOf(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return null;
	}//end savedObjectOf()

	/**
	 * Compute and patch the golden record + provenance onto the object data.
	 *
	 * Fail-soft: any error during resolution is logged and swallowed — the
	 * object is still persisted with its data unchanged. The override map is
	 * read from `overridesField` and threaded into the resolver but is never
	 * itself written here — {@see materialise()} only ever patches
	 * `goldenRecordField` / `provenanceField`, so the raw `$data` array
	 * (including any override map already on the object) flows through to
	 * `ObjectEntity::setObject()` untouched, preserving overrides across an
	 * unrelated recompute.
	 *
	 * @param ObjectEntity $object Object being created or updated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.3
	 */
	private function process(ObjectEntity $object): void {
		try {
			$schema = $this->loadSchema(object: $object);
			if ($schema !== null) {
				$this->recompute(object: $object, schema: $schema);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Survivorship resolution failed on %s: %s', (string)$object->getUuid(), $e->getMessage())
			);
		}
	}//end process()

	/**
	 * Resolve and materialise the golden record of an object whose schema is known.
	 *
	 * Skips schemas without a survivorship annotation or without a resolvable
	 * source linkage, and keeps a stored golden record a reverse-FK recompute
	 * with no sources would otherwise wipe.
	 *
	 * @param ObjectEntity $object Object being created or updated.
	 * @param Schema       $schema The object's schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	private function recompute(ObjectEntity $object, Schema $schema): void {
		$config = $this->linkedConfig(schema: $schema);
		if ($config === null) {
			return;
		}

		$data = ($object->getObject() ?? []);
		$sourceRecords = $this->sourcesOf(object: $object, data: $data, config: $config);
		if ($this->shouldPreserveGoldenRecord(sourceRecords: $sourceRecords, config: $config, data: $data) === true) {
			return;
		}

		$resolution = $this->resolve(schema: $schema, config: $config, sourceRecords: $sourceRecords, data: $data);
		$this->materialise(object: $object, data: $data, config: $config, resolution: $resolution);
	}//end recompute()

	/**
	 * The survivorship annotation of a schema, when it declares a source linkage.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null The annotation, or null when absent or without a source linkage.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	private function linkedConfig(Schema $schema): ?array {
		$config = $this->getSurvivorshipConfig(schema: $schema);
		if ($config === null || $this->hasSourceLinkage(config: $config) === false) {
			return null;
		}

		return $config;
	}//end linkedConfig()

	/**
	 * The source records linked to a master object, embedded or by reverse FK.
	 *
	 * @param ObjectEntity         $object The master object.
	 * @param array<string, mixed> $data   The master's current data.
	 * @param array<string, mixed> $config Survivorship annotation.
	 *
	 * @return array<int, array<string, mixed>> The source records.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	private function sourcesOf(ObjectEntity $object, array $data, array $config): array {
		return $this->sourceRecordResolver->resolveSources(
			masterData: $data,
			masterUuid: (string)$object->getUuid(),
			config: $config,
			masterRegister: (string)$object->getRegister()
		);
	}//end sourcesOf()

	/**
	 * Resolve the golden record from the sources, the entity type's trust rows and the steward overrides.
	 *
	 * @param Schema                           $schema        The master's schema (its slug is the default entity type).
	 * @param array<string, mixed>             $config        Survivorship annotation.
	 * @param array<int, array<string, mixed>> $sourceRecords The linked source records.
	 * @param array<string, mixed>             $data          The master's current data (carries the overrides).
	 *
	 * @return array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>} Resolver output.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.3
	 */
	private function resolve(Schema $schema, array $config, array $sourceRecords, array $data): array {
		$entityType = (string)($config['entityType'] ?? ($schema->getSlug() ?? ''));
		$trustRows = $this->loadTrustRows(entityType: $entityType);
		$overridesField = self::fieldName(config: $config, key: 'overridesField', default: self::DEFAULT_OVERRIDES_FIELD);

		return $this->resolver->resolveGoldenRecord(
			entityType: $entityType,
			sourceRecords: $sourceRecords,
			config: $config,
			trustRows: $trustRows,
			trustResolver: $this->trustResolver,
			asOf: new DateTimeImmutable(),
			overrides: ($data[$overridesField] ?? null)
		);
	}//end resolve()

	/**
	 * Write the resolved golden record + provenance into the object payload,
	 * only when the computed values differ from what is already stored.
	 *
	 * @param ObjectEntity $object Object being saved.
	 * @param array<string, mixed> $data Object's current data.
	 * @param array<string, mixed> $config Survivorship annotation.
	 * @param array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>} $resolution Resolver output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private function materialise(ObjectEntity $object, array $data, array $config, array $resolution): void {
		$changed = false;
		foreach (self::fieldWrites(config: $config, resolution: $resolution) as $write) {
			if (($data[$write['field']] ?? null) !== $write['value']) {
				$data[$write['field']] = $write['value'];
				$changed = true;
			}
		}

		if ($changed === true) {
			$object->setObject($data);
		}
	}//end materialise()

	/**
	 * The two derived fields to write, in order: the golden record, then its provenance.
	 *
	 * @param array<string, mixed> $config Survivorship annotation.
	 * @param array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>} $resolution Resolver output.
	 *
	 * @return array<int, array{field: string, value: mixed}> The writes.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private static function fieldWrites(array $config, array $resolution): array {
		return [
			[
				'field' => self::fieldName(config: $config, key: 'goldenRecordField', default: self::DEFAULT_GOLDEN_FIELD),
				'value' => $resolution['goldenRecord'],
			],
			[
				'field' => self::fieldName(config: $config, key: 'provenanceField', default: self::DEFAULT_PROVENANCE_FIELD),
				'value' => $resolution['attributeProvenance'],
			],
		];
	}//end fieldWrites()

	/**
	 * A field name the annotation configures, the default when it is absent or empty.
	 *
	 * @param array<string, mixed> $config  Survivorship annotation.
	 * @param string               $key     The annotation key naming the field.
	 * @param string               $default The field used when the annotation names none.
	 *
	 * @return string The field name.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private static function fieldName(array $config, string $key, string $default): string {
		$field = (string)($config[$key] ?? $default);
		if ($field === '') {
			return $default;
		}

		return $field;
	}//end fieldName()

	/**
	 * Look up the schema referenced by an object instance.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$ref = (string)$object->getSchema();
		try {
			return match ($ref) {
				'' => null,
				default => $this->schemaMapper->find($ref, _multitenancy: false),
			};
		} catch (Throwable) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Read the `x-openregister-survivorship` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null Survivorship config, or null when absent.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private function getSurvivorshipConfig(Schema $schema): ?array {
		$value = (($schema->getConfiguration() ?? [])['x-openregister-survivorship'] ?? null);
		if (is_array($value) === true && count($value) > 0) {
			return $value;
		}

		return null;
	}//end getSurvivorshipConfig()

	/**
	 * Whether the survivorship config declares a resolvable source linkage —
	 * an embedded `sourceLinkField` or a reverse-FK `sourceLink` block.
	 *
	 * @param array<string, mixed> $config Survivorship config.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	private function hasSourceLinkage(array $config): bool {
		if ((string)($config['sourceLinkField'] ?? '') !== '') {
			return true;
		}

		return $this->sourceRecordResolver->isReverseFk(config: $config);
	}//end hasSourceLinkage()

	/**
	 * Reverse-FK guard: a recompute with NO resolvable sources must not clobber
	 * an existing golden record. Covers create time (no uuid yet → no sources
	 * can reference the master) and any context where the source query yields
	 * nothing — the record is only recomputed from sources that exist, never
	 * wiped by a transient empty resolution. Embedded mode keeps its prior
	 * behaviour (an empty embedded array legitimately clears the record).
	 *
	 * The stored record is read from `goldenRecordField` as configured, with
	 * the default only when the key is absent (an empty name is not defaulted
	 * here, unlike on the write).
	 *
	 * @param array<int, array<string, mixed>> $sourceRecords Resolved sources.
	 * @param array<string, mixed> $config Survivorship config.
	 * @param array<string, mixed> $data Object payload.
	 *
	 * @return bool True when the existing golden record must be preserved as-is.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.2
	 */
	private function shouldPreserveGoldenRecord(array $sourceRecords, array $config, array $data): bool {
		if (empty($sourceRecords) === false) {
			return false;
		}

		if ($this->sourceRecordResolver->isReverseFk(config: $config) === false) {
			return false;
		}

		$goldenField = (string)($config['goldenRecordField'] ?? self::DEFAULT_GOLDEN_FIELD);
		return empty(($data[$goldenField] ?? null)) === false;
	}//end shouldPreserveGoldenRecord()

	/**
	 * Load the candidate trust-configuration rows for an entity type via the
	 * OR-owned `trustConfiguration` register (RBAC + tenant scoped).
	 *
	 * @param string $entityType Entity type to scope the lookup.
	 *
	 * @return array<int, array<string, mixed>> Trust-configuration rows.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private function loadTrustRows(string $entityType): array {
		if ($entityType === '') {
			return [];
		}

		$query = ['filters' => ['schema' => self::TRUST_CONFIGURATION_SCHEMA, 'entityType' => $entityType]];
		try {
			$objects = $this->objectService->findAll($query, _rbac: true, _multitenancy: true);
		} catch (Throwable) {
			return [];
		}

		return self::payloadsOf(objects: $objects);
	}//end loadTrustRows()

	/**
	 * The data of every object entity in a result, in order; anything else is dropped.
	 *
	 * @param array<int, mixed> $objects A find result.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	private static function payloadsOf(array $objects): array {
		$entities = array_filter($objects, static fn (mixed $object): bool => $object instanceof ObjectEntity);

		return array_values(array_map(static fn (ObjectEntity $object): array => ($object->getObject() ?? []), $entities));
	}//end payloadsOf()
}//end class
