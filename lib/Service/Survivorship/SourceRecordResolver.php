<?php

/**
 * OpenRegister SourceRecordResolver
 *
 * Resolves a master object's competing source records for the survivorship /
 * merge engine, in one of two modes declared by the schema's
 * `x-openregister-survivorship` / `x-openregister-merge` annotation:
 *
 *   - embedded  (default) — sources live on the master payload's
 *                `sourceLinkField` as embedded records and/or uuid references.
 *   - reverseFk           — sources are separate objects that reference the
 *                master; resolved by querying `sourceLink.sourceSchema` for
 *                objects whose `sourceLink.referenceField` equals the master's
 *                uuid.
 *
 * The resolved array is fed verbatim to `SurvivorshipResolver`, which reads
 * each entry's `values`/`mappedAttributes`. Never fatal: an unresolvable
 * reference or a malformed `sourceLink` block degrades to embedded mode (or an
 * empty set) with a logged warning rather than throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
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

namespace OCA\OpenRegister\Service\Survivorship;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mode-aware source-record resolver shared by the survivorship recompute
 * listener and the merge service.
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
 */
class SourceRecordResolver
{

    /**
     * Cross-request cache of source-schema reference (slug/uuid) => numeric
     * schema id. Populated only from successful resolutions (a mid-save slug
     * lookup that throws never poisons it). Lets the reverse-FK query pass a
     * numeric schema id — which `ObjectService::setSchema()` resolves via its
     * cached numeric path — instead of a slug, whose DB lookup throws when it
     * runs inside a save transaction (the recompute-on-save listener).
     *
     * @var array<string, string>
     */
    private static array $schemaIdCache = [];

    /**
     * Wire the object read path used to resolve uuid references (embedded
     * mode) and to query source objects (reverse-FK mode).
     *
     * @param ObjectService   $objectService Object read path (RBAC + tenant scoped).
     * @param SchemaMapper    $schemaMapper  Schema lookup, to resolve the source schema slug to a numeric id.
     * @param LoggerInterface $logger        PSR logger for non-fatal warnings.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve the competing source records for a master, honouring the
     * annotation's `sourceLink.mode`.
     *
     * @param array<string, mixed> $masterData     Master object payload.
     * @param string               $masterUuid     Master object uuid (needed for reverse-FK).
     * @param array<string, mixed> $config         `x-openregister-survivorship` (or `-merge`) config.
     * @param string               $masterRegister Master's register ref, used as the reverse-FK
     *                                             source register when the annotation omits one
     *                                             (the magic-table query needs a register + schema).
     *
     * @return array<int, array<string, mixed>> Resolved source-record payloads.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function resolveSources(array $masterData, string $masterUuid, array $config, string $masterRegister=''): array
    {
        $reverseFk = $this->reverseFkConfig(config: $config);
        if ($reverseFk !== null) {
            $sourceRegister = $reverseFk['sourceRegister'];
            if ($sourceRegister === '') {
                $sourceRegister = $masterRegister;
            }

            return $this->resolveReverseFk(
                masterUuid: $masterUuid,
                sourceSchema: $reverseFk['sourceSchema'],
                referenceField: $reverseFk['referenceField'],
                sourceRegister: $sourceRegister
            );
        }

        // Embedded mode (default): read the master payload's sourceLinkField.
        $sourceLinkField = (string) ($config['sourceLinkField'] ?? '');
        if ($sourceLinkField === '') {
            return [];
        }

        return $this->resolveEmbedded(data: $masterData, sourceLinkField: $sourceLinkField);
    }//end resolveSources()

    /**
     * Whether the config selects reverse-FK resolution.
     *
     * @param array<string, mixed> $config Survivorship/merge config.
     *
     * @return bool True when a well-formed reverse-FK `sourceLink` is present.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function isReverseFk(array $config): bool
    {
        return $this->reverseFkConfig(config: $config) !== null;
    }//end isReverseFk()

    /**
     * Return the validated reverse-FK descriptor (`sourceSchema`,
     * `referenceField`, `sourceRegister`) for a config, or null when the
     * config is not reverse-FK. Used by callers that need to query or mutate
     * the source objects themselves (e.g. merge relink).
     *
     * @param array<string, mixed> $config Survivorship/merge config.
     *
     * @return array{sourceSchema: string, referenceField: string, sourceRegister: string}|null
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function reverseFkDescriptor(array $config): ?array
    {
        return $this->reverseFkConfig(config: $config);
    }//end reverseFkDescriptor()

    /**
     * Resolve a source-schema reference to the value the reverse-FK `findAll`
     * filter should carry: the numeric schema id when resolvable (the
     * save-transaction-safe path), else the original reference unchanged.
     * Used by callers that build the query themselves (e.g. merge relink).
     *
     * @param string $ref Source-schema reference (slug/uuid/id).
     *
     * @return string Numeric id when resolvable, otherwise `$ref`.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    public function schemaQueryFilter(string $ref): string
    {
        $id = $this->resolveSchemaId(ref: $ref);
        if ($id !== '') {
            return $id;
        }

        return $ref;
    }//end schemaQueryFilter()

    /**
     * Resolve a schema reference (slug/uuid/id) to its numeric id, memoising
     * successful resolutions across requests. A lookup that throws (e.g. a slug
     * resolved inside a save transaction) returns '' and is NOT cached, so a
     * later clean-context call can still populate it.
     *
     * @param string $ref Schema reference.
     *
     * @return string Numeric schema id, or '' when unresolved.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function resolveSchemaId(string $ref): string
    {
        if ($ref === '') {
            return '';
        }

        if (is_numeric($ref) === true) {
            return $ref;
        }

        if (array_key_exists($ref, self::$schemaIdCache) === true) {
            return self::$schemaIdCache[$ref];
        }

        try {
            $schema = $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
            $id     = (string) $schema->getId();
        } catch (Throwable) {
            return '';
        }

        if ($id !== '') {
            self::$schemaIdCache[$ref] = $id;
        }

        return $id;
    }//end resolveSchemaId()

    /**
     * Parse and validate the reverse-FK `sourceLink` block. A block with mode
     * `reverseFk` but missing `sourceSchema`/`referenceField` degrades to
     * embedded mode (returns null) with a logged warning.
     *
     * @param array<string, mixed> $config Survivorship/merge config.
     *
     * @return array{sourceSchema: string, referenceField: string, sourceRegister: string}|null
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function reverseFkConfig(array $config): ?array
    {
        $sourceLink = ($config['sourceLink'] ?? null);
        if (is_array($sourceLink) === false) {
            return null;
        }

        if ((string) ($sourceLink['mode'] ?? 'embedded') !== 'reverseFk') {
            return null;
        }

        $sourceSchema   = (string) ($sourceLink['sourceSchema'] ?? '');
        $referenceField = (string) ($sourceLink['referenceField'] ?? '');
        if ($sourceSchema === '' || $referenceField === '') {
            $this->logger->warning(
                'x-openregister source resolution: reverseFk sourceLink is missing sourceSchema or referenceField; falling back to embedded mode.'
            );
            return null;
        }

        return [
            'sourceSchema'   => $sourceSchema,
            'referenceField' => $referenceField,
            'sourceRegister' => (string) ($sourceLink['sourceRegister'] ?? ''),
        ];
    }//end reverseFkConfig()

    /**
     * Query the source schema for objects whose `referenceField` equals the
     * master uuid. RBAC + multitenancy scoped. A DB-level property filter is
     * requested, and the result is re-filtered in PHP so correctness does not
     * depend on the magic-table filter honouring an arbitrary property.
     *
     * @param string $masterUuid     Master object uuid.
     * @param string $sourceSchema   Slug/id of the source schema.
     * @param string $referenceField Field on a source object holding the master uuid.
     * @param string $sourceRegister Optional register slug/id; empty means unset.
     *
     * @return array<int, array<string, mixed>> Resolved source-record payloads.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function resolveReverseFk(
        string $masterUuid,
        string $sourceSchema,
        string $referenceField,
        string $sourceRegister
    ): array {
        if ($masterUuid === '') {
            return [];
        }

        // Prefer a numeric schema id (setSchema's cached numeric path works
        // inside a save transaction; a slug lookup throws there).
        $schemaFilter = $this->resolveSchemaId(ref: $sourceSchema);
        if ($schemaFilter === '') {
            $schemaFilter = $sourceSchema;
        }

        $filters = [
            'schema'        => $schemaFilter,
            $referenceField => $masterUuid,
        ];
        if ($sourceRegister !== '') {
            $filters['register'] = $sourceRegister;
        }

        try {
            $objects = $this->objectService->findAll(
                ['filters' => $filters],
                _rbac: true,
                _multitenancy: true
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Reverse-FK source resolution failed for master "%s": %s', $masterUuid, $e->getMessage())
            );
            return [];
        }

        $records = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = ($object->getObject() ?? []);
            // Re-filter in PHP: only sources actually pointing at this master.
            if ((string) ($data[$referenceField] ?? '') !== $masterUuid) {
                continue;
            }

            $records[] = $data;
        }

        return $records;
    }//end resolveReverseFk()

    /**
     * Embedded resolution: the field holds an array of already-embedded source
     * records and/or uuid strings referencing objects elsewhere in the
     * register (resolved via ObjectService::find, RBAC + tenant scoped).
     * Unresolvable entries are skipped.
     *
     * @param array<string, mixed> $data            Master object payload.
     * @param string               $sourceLinkField Field holding the linked source records.
     *
     * @return array<int, array<string, mixed>> Resolved source-record payloads.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function resolveEmbedded(array $data, string $sourceLinkField): array
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
                $resolved = $this->resolveReference(uuid: $entry);
                if ($resolved !== null) {
                    $records[] = $resolved;
                }
            }
        }

        return $records;
    }//end resolveEmbedded()

    /**
     * Resolve a single uuid reference to its payload, RBAC + tenant scoped.
     *
     * @param string $uuid Referenced object's uuid/id.
     *
     * @return array<string, mixed>|null Resolved payload, or null on lookup failure.
     *
     * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#2.1
     */
    private function resolveReference(string $uuid): ?array
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
    }//end resolveReference()
}//end class
