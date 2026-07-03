<?php

/**
 * OpenRegister SurvivorshipRecomputeListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. When a schema
 * declares an `x-openregister-survivorship` annotation, loads the linked
 * source records from `sourceLinkField`, resolves the golden record via the
 * pure `SurvivorshipResolver` (backed by the `trustConfiguration` register
 * through `TrustTierResolver`), and materialises `goldenRecordField` +
 * `provenanceField` into the object payload before persistence — only when
 * the computed values differ from the stored ones. Mirrors the
 * materialise-on-save contract of {@see QualityScoreOnSaveListener}: runs
 * before the write, is fail-soft (logs a warning and continues on any
 * error), and never aborts the save.
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
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
 */
class SurvivorshipRecomputeListener implements IEventListener
{
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
     * Slug of the OR-owned trust-configuration register schema.
     *
     * @var string
     */
    private const TRUST_CONFIGURATION_SCHEMA = 'trustConfiguration';

    /**
     * Wire collaborators used to look up the schema, load linked sources +
     * trust rows, and resolve the golden record.
     *
     * @param SchemaMapper         $schemaMapper  Schema lookup mapper.
     * @param ObjectService        $objectService Object read path (RBAC + tenant scoped).
     * @param SurvivorshipResolver $resolver      Pure golden-record resolver.
     * @param TrustTierResolver    $trustResolver Pure trust-tier lookup + decay engine.
     * @param LoggerInterface      $logger        PSR logger for warnings.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly SurvivorshipResolver $resolver,
        private readonly TrustTierResolver $trustResolver,
        private readonly LoggerInterface $logger
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
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatingEvent) {
            $this->process(object: $event->getObject());
            return;
        }

        if ($event instanceof ObjectUpdatingEvent) {
            $this->process(object: $event->getNewObject());
            return;
        }
    }//end handle()

    /**
     * Compute and patch the golden record + provenance onto the object data.
     *
     * Fail-soft: any error during resolution is logged and swallowed — the
     * object is still persisted with its data unchanged.
     *
     * @param ObjectEntity $object Object being created or updated.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
     */
    private function process(ObjectEntity $object): void
    {
        try {
            $schema = $this->loadSchema(object: $object);
            if ($schema === null) {
                return;
            }

            $config = $this->getSurvivorshipConfig(schema: $schema);
            if ($config === null) {
                return;
            }

            $sourceLinkField = (string) ($config['sourceLinkField'] ?? '');
            if ($sourceLinkField === '') {
                return;
            }

            $data          = ($object->getObject() ?? []);
            $sourceRecords = $this->loadSourceRecords(data: $data, sourceLinkField: $sourceLinkField);

            $entityType = (string) ($config['entityType'] ?? ($schema->getSlug() ?? ''));
            $trustRows  = $this->loadTrustRows(entityType: $entityType);
            $now        = new DateTimeImmutable();

            $resolution = $this->resolver->resolveGoldenRecord(
                entityType: $entityType,
                sourceRecords: $sourceRecords,
                config: $config,
                trustRows: $trustRows,
                trustResolver: $this->trustResolver,
                asOf: $now
            );

            $this->materialise(object: $object, data: $data, config: $config, resolution: $resolution);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Survivorship resolution failed on %s: %s',
                    (string) $object->getUuid(),
                    $e->getMessage()
                )
            );
        }//end try
    }//end process()

    /**
     * Write the resolved golden record + provenance into the object payload,
     * only when the computed values differ from what is already stored.
     *
     * @param ObjectEntity                                                                         $object     Object being saved.
     * @param array<string, mixed>                                                                 $data       Object's current data.
     * @param array<string, mixed>                                                                 $config     Survivorship annotation.
     * @param array{goldenRecord: array<string, mixed>, attributeProvenance: array<string, mixed>} $resolution Resolver output.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
     */
    private function materialise(ObjectEntity $object, array $data, array $config, array $resolution): void
    {
        $goldenField = (string) ($config['goldenRecordField'] ?? self::DEFAULT_GOLDEN_FIELD);
        if ($goldenField === '') {
            $goldenField = self::DEFAULT_GOLDEN_FIELD;
        }

        $provenanceField = (string) ($config['provenanceField'] ?? self::DEFAULT_PROVENANCE_FIELD);
        if ($provenanceField === '') {
            $provenanceField = self::DEFAULT_PROVENANCE_FIELD;
        }

        $changed = false;

        if (($data[$goldenField] ?? null) !== $resolution['goldenRecord']) {
            $data[$goldenField] = $resolution['goldenRecord'];
            $changed            = true;
        }

        if (($data[$provenanceField] ?? null) !== $resolution['attributeProvenance']) {
            $data[$provenanceField] = $resolution['attributeProvenance'];
            $changed = true;
        }

        if ($changed === true) {
            $object->setObject($data);
        }
    }//end materialise()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
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
    private function getSurvivorshipConfig(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-survivorship'] ?? null);
        if (is_array($value) === true && count($value) > 0) {
            return $value;
        }

        return null;
    }//end getSurvivorshipConfig()

    /**
     * Resolve the linked source records from `sourceLinkField`.
     *
     * The field may hold either an array of already-embedded source-record
     * objects, or an array of uuid/id strings referencing objects elsewhere
     * in the register (resolved via ObjectService::find, RBAC + tenant
     * scoped under the saving user's session). Unresolvable entries are
     * skipped rather than aborting the resolution.
     *
     * @param array<string, mixed> $data            Object's current data.
     * @param string               $sourceLinkField Field holding the linked source records.
     *
     * @return array<int, array<string, mixed>> Resolved source-record payloads.
     *
     * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
     */
    private function loadSourceRecords(array $data, string $sourceLinkField): array
    {
        $raw = ($data[$sourceLinkField] ?? null);
        if (is_array($raw) === false) {
            return [];
        }

        $records = [];
        foreach ($raw as $entry) {
            if (is_array($entry) === true) {
                $records[] = $entry;
                continue;
            }

            if (is_string($entry) === true && $entry !== '') {
                $resolved = $this->resolveSourceReference(uuid: $entry);
                if ($resolved !== null) {
                    $records[] = $resolved;
                }
            }
        }

        return $records;
    }//end loadSourceRecords()

    /**
     * Resolve a single source-record reference by uuid/id.
     *
     * @param string $uuid Referenced object's uuid/id.
     *
     * @return array<string, mixed>|null Resolved payload, or null on lookup failure.
     */
    private function resolveSourceReference(string $uuid): ?array
    {
        try {
            $entity = $this->objectService->find(id: $uuid, _rbac: true, _multitenancy: true);
        } catch (Throwable) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return ($entity->getObject() ?? []);
    }//end resolveSourceReference()

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
    private function loadTrustRows(string $entityType): array
    {
        if ($entityType === '') {
            return [];
        }

        try {
            $objects = $this->objectService->findAll(
                [
                    'filters' => [
                        'schema'     => self::TRUST_CONFIGURATION_SCHEMA,
                        'entityType' => $entityType,
                    ],
                ],
                _rbac: true,
                _multitenancy: true
            );
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $rows[] = ($object->getObject() ?? []);
            }
        }

        return $rows;
    }//end loadTrustRows()
}//end class
