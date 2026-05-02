<?php

/**
 * OpenRegister TranslationMapper
 *
 * CRUD + search + completeness queries against the unified
 * `openregister_translations` sidecar.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Translation>
 */
class TranslationMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_translations', Translation::class);
    }//end __construct()

    /**
     * UPSERT a translation slot. Returns the persisted entity.
     *
     * Slot key is `(object_uuid, property, language)`. When updating
     * an existing slot, status defaults to retaining the previous
     * status unless caller passes a non-null override.
     */
    public function upsert(
        string $objectUuid,
        string $property,
        string $language,
        ?string $value,
        ?string $status=null,
        ?string $translator=null
    ): Translation {
        $existing = $this->findOne($objectUuid, $property, $language);
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

        $entity->setUpdated(new \DateTime());

        if ($existing === null) {
            return $this->insert($entity);
        }

        return $this->update($entity);
    }//end upsert()

    /**
     * Find a single translation slot by its natural key.
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
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }//end findOne()

    /**
     * Find all translations for one object — all properties, all languages.
     *
     * @return Translation[]
     */
    public function findByObject(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_translations')
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
        return $this->findEntities($qb);
    }//end findByObject()

    /**
     * Delete every row for an object — called on object delete.
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
     * - `query`     — case-insensitive substring against `value`
     * - `language`  — scope to a specific language (null = cross-language)
     * - `status`    — filter by workflow status
     * - `objectUuid` — scope to one object
     *
     * Uses `LOWER(value) LIKE LOWER(?)` so the query works on both
     * Postgres and MariaDB without DB-specific FTS. tsvector
     * optimisation is a v1.1 follow-up.
     *
     * @return Translation[]
     */
    public function search(
        ?string $query=null,
        ?string $language=null,
        ?string $status=null,
        ?string $objectUuid=null,
        int $limit=100
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_translations')
            ->setMaxResults(max(1, min(1000, $limit)));

        if ($query !== null && $query !== '') {
            $qb->andWhere(
                $qb->expr()->iLike(
                    'value',
                    $qb->createNamedParameter('%'.$this->escapeLike($query).'%')
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

        return $this->findEntities($qb);
    }//end search()

    /**
     * Find object UUIDs missing a translation in the given language for
     * one or more properties.
     *
     * @param string[] $properties
     * @param string[] $candidateUuids Restrict to these uuids (e.g. to scope to a register/schema)
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
            ->andWhere($qb->expr()->in('object_uuid', $qb->createNamedParameter($candidateUuids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in('property',   $qb->createNamedParameter($properties,    IQueryBuilder::PARAM_STR_ARRAY)))
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
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }//end escapeLike()
}//end class
