<?php

/**
 * OpenRegister TranslationMapper
 *
 * CRUD + search + completeness queries against the unified
 * `openregister_translations` sidecar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the unified translations sidecar table.
 *
 * @template-extends QBMapper<Translation>
 */
class TranslationMapper extends QBMapper
{
    /**
     * Construct the mapper bound to the translations table.
     *
     * @param IDBConnection $db Database connection handle.
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_translations', entityClass: Translation::class);
    }//end __construct()

    /**
     * UPSERT a translation slot. Returns the persisted entity.
     *
     * Slot key is `(object_uuid, property, language)`. When updating
     * an existing slot, status defaults to retaining the previous
     * status unless caller passes a non-null override.
     *
     * @param string      $objectUuid     UUID of the object being translated.
     * @param string      $property       Property path within the object.
     * @param string      $language       BCP-47 language tag of the slot.
     * @param string|null $value          Translated value (null clears).
     * @param string|null $status         Optional workflow status override.
     * @param string|null $translator     Optional UID of the translator.
     * @param string|null $sourceLanguage Optional canonical source language for the property.
     *
     * @return Translation Persisted translation row.
     */
    public function upsert(
        string $objectUuid,
        string $property,
        string $language,
        ?string $value,
        ?string $status=null,
        ?string $translator=null,
        ?string $sourceLanguage=null
    ): Translation {
        $existing = $this->findOne(objectUuid: $objectUuid, property: $property, language: $language);
        $entity   = $existing ?? new Translation();
        $entity->setObjectUuid($objectUuid);
        $entity->setProperty($property);
        $entity->setLanguage($language);
        $entity->setValue($value);
        if ($status !== null) {
            $entity->setStatus($status);
        } else if ($entity->getStatus() === null) {
            $entity->setStatus(Translation::STATUS_DRAFT);
        }

        if ($translator !== null) {
            $entity->setTranslator($translator);
        }

        if ($sourceLanguage !== null && $sourceLanguage !== '') {
            $entity->setSourceLanguage($sourceLanguage);
        }

        $entity->setUpdated(new DateTime());

        if ($existing === null) {
            return $this->insert(entity: $entity);
        }

        return $this->update(entity: $entity);
    }//end upsert()

    /**
     * Find a single translation slot by its natural key.
     *
     * @param string $objectUuid UUID of the object being translated.
     * @param string $property   Property path within the object.
     * @param string $language   BCP-47 language tag of the slot.
     *
     * @return Translation|null Matching row, or null when not found.
     */
    public function findOne(string $objectUuid, string $property, string $language): ?Translation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('property',   $qb->createNamedParameter($property)))
            ->andWhere($qb->expr()->eq('language',   $qb->createNamedParameter($language)))
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findOne()

    /**
     * Find all translations for one object — all properties, all languages.
     *
     * @param string $objectUuid UUID of the object to look up.
     *
     * @return Translation[]
     */
    public function findByObject(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
        return $this->findEntities(query: $qb);
    }//end findByObject()

    /**
     * Delete every row for an object — called on object delete.
     *
     * @param string $objectUuid UUID of the object whose rows should be removed.
     *
     * @return int Number of rows deleted.
     */
    public function deleteByObject(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
        return $qb->executeStatement();
    }//end deleteByObject()

    /**
     * Per-language completeness count for a single object.
     *
     * Returns `[language => count]` — caller divides against the
     * schema's translatable-property total to derive the ratio.
     *
     * @param string $objectUuid UUID of the object to count rows for.
     *
     * @return array<string, int>
     */
    public function getCompletenessByObject(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('language')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'count')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->isNotNull('value'))
            ->andWhere($qb->expr()->neq('value', $qb->createNamedParameter('')))
            ->groupBy('language');
        $stmt = $qb->executeQuery();

        $out = [];
        while (($row = $stmt->fetch()) !== false) {
            $out[(string) $row['language']] = (int) $row['count'];
        }

        $stmt->closeCursor();
        return $out;
    }//end getCompletenessByObject()

    /**
     * Search translations by content + optional filters.
     *
     * - `query`           — case-insensitive substring against `value`
     * - `language`        — scope to a specific language (null = cross-language)
     * - `status`          — filter by workflow status
     * - `objectUuid`      — scope to one object
     * - `sourceLanguage`  — scope to rows whose canonical source-of-truth
     *                       language equals the given BCP-47 tag
     *                       (`i18n-source-of-truth`)
     * - `isOutOfDate`     — when true, narrows to rows in `outdated` status
     *
     * Uses `LOWER(value) LIKE LOWER(?)` so the query works on both
     * Postgres and MariaDB without DB-specific FTS. tsvector
     * optimisation is a v1.1 follow-up.
     *
     * @param string|null $query          Case-insensitive substring against the value column.
     * @param string|null $language       Optional language filter (BCP-47 tag).
     * @param string|null $status         Optional workflow-status filter.
     * @param string|null $objectUuid     Optional object-uuid scope.
     * @param int         $limit          Maximum number of rows to return (1..1000).
     * @param string|null $sourceLanguage Optional source-language filter (BCP-47 tag).
     * @param bool        $isOutOfDate    When true, narrows to status='outdated'.
     *
     * @return Translation[]
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
     */
    public function search(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        int $limit=100,
        ?string $sourceLanguage=null,
        bool $isOutOfDate=false
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_translations')
            ->setMaxResults(max(1, min(1000, $limit)));

        if ($query !== null && $query !== '') {
            $qb->andWhere(
                $qb->expr()->iLike(
                    'value',
                    $qb->createNamedParameter('%'.$this->escapeLike(value: $query).'%')
                )
            );
        }

        if ($language !== null && $language !== '') {
            $qb->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)));
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        if ($objectUuid !== null && $objectUuid !== '') {
            $qb->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
        }

        if ($sourceLanguage !== null && $sourceLanguage !== '') {
            $qb->andWhere($qb->expr()->eq('source_language', $qb->createNamedParameter($sourceLanguage)));
        }

        if ($isOutOfDate === true) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Translation::STATUS_OUTDATED)));
        }

        return $this->findEntities(query: $qb);
    }//end search()

    /**
     * Flip every non-source-language row for one (object, property) to outdated.
     *
     * Targets every row WHERE `object_uuid = $objectUuid AND property = $property
     * AND language != $sourceLanguage AND status IN (approved,
     * human_reviewed, machine_translated)`. Rows already in `outdated` or
     * `draft` are not re-flipped.
     *
     * @param string $objectUuid     UUID of the object whose translations to flip.
     * @param string $property       Property whose derived translations to flip.
     * @param string $sourceLanguage Source language for the property (never flipped).
     *
     * @return int Number of rows transitioned to `outdated`.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
     */
    public function markDerivedOutdated(string $objectUuid, string $property, string $sourceLanguage): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('openregister_translations')
            ->set('status', $qb->createNamedParameter(Translation::STATUS_OUTDATED))
            ->set('updated', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->eq('property', $qb->createNamedParameter($property)))
            ->andWhere($qb->expr()->neq('language', $qb->createNamedParameter($sourceLanguage)))
            ->andWhere(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(
                        Translation::FLIPPABLE_STATUSES,
                        IQueryBuilder::PARAM_STR_ARRAY
                    )
                )
            );

        return $qb->executeStatement();
    }//end markDerivedOutdated()

    /**
     * Back-fill the source_language column on rows that have an empty value.
     *
     * Streams candidate rows in batches and updates each row's
     * `source_language` to the resolved `<defaultLanguage>` for the parent
     * register. Used by the
     * `openregister:translations:backfill-source-language` console command.
     *
     * @param array<string, string> $registerDefaults Map of `register_id => default_language`.
     * @param int                   $batchSize        Maximum rows updated per pass.
     *
     * @return int Number of rows updated across all batches.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
     */
    public function backfillSourceLanguage(array $registerDefaults, int $batchSize=1000): int
    {
        $totalUpdated = 0;

        // Distinct register ids present in the registers map.
        $registerIds = array_keys($registerDefaults);
        if (count($registerIds) === 0) {
            // Fallback: blanket-fill every empty row with 'nl' so the
            // NOT NULL DEFAULT '' constraint is honoured.
            $qb = $this->db->getQueryBuilder();
            $qb->update('openregister_translations')
                ->set('source_language', $qb->createNamedParameter('nl'))
                ->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')));
            return $qb->executeStatement();
        }

        foreach ($registerDefaults as $registerId => $defaultLanguage) {
            if ($defaultLanguage === '' || $defaultLanguage === null) {
                $defaultLanguage = 'nl';
            }

            $qb = $this->db->getQueryBuilder();
            $qb->update('openregister_translations', 't')
                ->set('source_language', $qb->createNamedParameter((string) $defaultLanguage))
                ->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')))
                ->andWhere(
                    "object_uuid IN (SELECT uuid FROM `*PREFIX*openregister_objects` WHERE register = "
                    .$qb->createNamedParameter((string) $registerId).")"
                )
                ->setMaxResults(max(1, $batchSize));

            try {
                $updated       = $qb->executeStatement();
                $totalUpdated += $updated;
            } catch (\Throwable $e) {
                // Driver doesn't support setMaxResults on UPDATE — fall back to
                // a single non-bounded UPDATE for this register.
                $qb2 = $this->db->getQueryBuilder();
                $qb2->update('openregister_translations')
                    ->set('source_language', $qb2->createNamedParameter((string) $defaultLanguage))
                    ->where($qb2->expr()->eq('source_language', $qb2->createNamedParameter('')))
                    ->andWhere(
                        "object_uuid IN (SELECT uuid FROM `*PREFIX*openregister_objects` WHERE register = "
                        .$qb2->createNamedParameter((string) $registerId).")"
                    );
                $totalUpdated += (int) $qb2->executeStatement();
            }
        }//end foreach

        // Catch-all: rows whose object UUID no longer joins back to a
        // register (orphans). Use 'nl' as the conservative fallback.
        $qb = $this->db->getQueryBuilder();
        $qb->update('openregister_translations')
            ->set('source_language', $qb->createNamedParameter('nl'))
            ->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')));
        $totalUpdated += (int) $qb->executeStatement();

        return $totalUpdated;
    }//end backfillSourceLanguage()

    /**
     * Count rows whose `source_language` is the empty back-fill default.
     *
     * Used by the back-fill command's idempotency check.
     *
     * @return int Count of rows still pending back-fill.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
     */
    public function countMissingSourceLanguage(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS pending'))
            ->from('openregister_translations')
            ->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')));

        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();
        return (int) ($row['pending'] ?? 0);
    }//end countMissingSourceLanguage()

    /**
     * Find the most common source_language across an object's translatable
     * properties. Used to drive the `X-Source-Language` response header.
     *
     * Returns null when the object has no translation rows.
     *
     * @param string $objectUuid UUID of the object to inspect.
     *
     * @return string|null Dominant source language, or null when no rows exist.
     *
     * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
     */
    public function getDominantSourceLanguage(string $objectUuid): ?string
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('source_language')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere($qb->expr()->neq('source_language', $qb->createNamedParameter('')))
            ->groupBy('source_language')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(1);

        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();
        if ($row === false || isset($row['source_language']) === false) {
            return null;
        }

        $value = (string) $row['source_language'];
        if ($value === '') {
            return null;
        }

        return $value;
    }//end getDominantSourceLanguage()

    /**
     * Find object UUIDs missing a translation in the given language for
     * one or more properties.
     *
     * @param string   $language       BCP-47 language tag to test for completeness.
     * @param string[] $properties     Property paths required for completeness.
     * @param string[] $candidateUuids Restrict to these uuids (e.g. to scope to a register/schema).
     *
     * @return string[] List of object_uuids missing at least one translation
     */
    public function findObjectsMissingLanguage(string $language, array $properties, array $candidateUuids): array
    {
        if (count($properties) === 0 || count($candidateUuids) === 0) {
            return [];
        }

        // Find (uuid, property) pairs that DO have the language; subtract
        // from the (uuid × property) cross-product to get the missing slots.
        $qb = $this->db->getQueryBuilder();
        $qb->select('object_uuid', 'property')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
            ->andWhere(
                $qb->expr()->in(
                    'object_uuid',
                    $qb->createNamedParameter($candidateUuids, IQueryBuilder::PARAM_STR_ARRAY)
                )
            )
            ->andWhere(
                $qb->expr()->in(
                    'property',
                    $qb->createNamedParameter($properties, IQueryBuilder::PARAM_STR_ARRAY)
                )
            )
            ->andWhere($qb->expr()->isNotNull('value'))
            ->andWhere($qb->expr()->neq('value', $qb->createNamedParameter('')));
        $stmt = $qb->executeQuery();

        $present = [];
        while (($row = $stmt->fetch()) !== false) {
            $present[(string) $row['object_uuid']][(string) $row['property']] = true;
        }

        $stmt->closeCursor();

        $missing = [];
        foreach ($candidateUuids as $uuid) {
            foreach ($properties as $prop) {
                if (isset($present[$uuid][$prop]) === false) {
                    $missing[] = $uuid;
                    continue 2;
                }
            }
        }

        return $missing;
    }//end findObjectsMissingLanguage()

    /**
     * Escape `%` and `_` for use inside a LIKE pattern.
     *
     * @param string $value Raw value to be escaped for LIKE matching.
     *
     * @return string The escaped string safe for LIKE clauses.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }//end escapeLike()
}//end class
