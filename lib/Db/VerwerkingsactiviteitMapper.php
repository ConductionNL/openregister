<?php

/**
 * Mapper for `oc_openregister_verwerkingsactiviteiten`.
 *
 * Standard `QBMapper` round-trip plus three lookup helpers used by
 * the audit-trail trigger contract:
 *   - `findByUuid()` — direct UUID lookup, returns null on miss.
 *   - `findByCode()` — short readable key lookup.
 *   - `resolveReference()` — unified `code` -> `uuid` resolver used
 *      when reading the schema/register annotation
 *      `x-openregister-processing-activity` so operators can write
 *      either form.
 *
 * Per-tenant isolation is applied to listing queries via the optional
 * `$organisationId` argument; single-row finds bypass the filter so
 * audit hooks can resolve activities defined outside the current
 * tenant when an explicit reference is supplied.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for Verwerkingsactiviteit rows.
 *
 * @template-extends QBMapper<Verwerkingsactiviteit>
 */
class VerwerkingsactiviteitMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_verwerkingsactiviteiten',
            entityClass: Verwerkingsactiviteit::class
        );

    }//end __construct()

    /**
     * Find by primary key.
     *
     * @param int $id Primary key.
     *
     * @return Verwerkingsactiviteit
     *
     * @throws DoesNotExistException When no row matches the id.
     */
    public function find(int $id): Verwerkingsactiviteit
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity(query: $qb);

    }//end find()

    /**
     * Find by uuid.
     *
     * @param string $uuid The activity uuid.
     *
     * @return Verwerkingsactiviteit|null Null when no row matches.
     */
    public function findByUuid(string $uuid): ?Verwerkingsactiviteit
    {
        if ($uuid === '') {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end findByUuid()

    /**
     * Find by short readable code.
     *
     * @param string $code The activity code.
     *
     * @return Verwerkingsactiviteit|null Null when no row matches.
     */
    public function findByCode(string $code): ?Verwerkingsactiviteit
    {
        if ($code === '') {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end findByCode()

    /**
     * Resolve a `code|uuid` reference to a concrete entity.
     *
     * Tries `code` first, falls back to `uuid`. Used by the audit-trail
     * trigger contract to honour `x-openregister-processing-activity`
     * annotations regardless of which form operators chose to write.
     *
     * @param string $reference The reference string (code or uuid).
     *
     * @return Verwerkingsactiviteit|null
     */
    public function resolveReference(string $reference): ?Verwerkingsactiviteit
    {
        if ($reference === '') {
            return null;
        }

        $byCode = $this->findByCode(code: $reference);
        if ($byCode !== null) {
            return $byCode;
        }

        return $this->findByUuid(uuid: $reference);

    }//end resolveReference()

    /**
     * List all activities, optionally filtered by tenant or status.
     *
     * @param string|null $organisationId Optional multi-tenant filter.
     * @param string|null $status         Optional status filter.
     *
     * @return Verwerkingsactiviteit[]
     */
    public function findAll(?string $organisationId=null, ?string $status=null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());

        if ($organisationId !== null && $organisationId !== '') {
            $qb->andWhere(
                $qb->expr()->eq('organisation_id', $qb->createNamedParameter($organisationId))
            );
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere(
                $qb->expr()->eq('status', $qb->createNamedParameter($status))
            );
        }

        $qb->orderBy('naam', 'ASC');

        return $this->findEntities(query: $qb);

    }//end findAll()

    /**
     * Insert with vocabulary validation.
     *
     * Auto-fills `uuid`, `created`, `updated`, and `status` when blank.
     *
     * @param Verwerkingsactiviteit $entity Entity to insert.
     *
     * @return Verwerkingsactiviteit Persisted entity with id populated.
     *
     * @throws InvalidArgumentException When `rechtsgrond` is unset/invalid
     *                                  or `naam`/`doelbinding` are blank.
     */
    public function insert($entity): Verwerkingsactiviteit
    {
        $this->validate(entity: $entity);

        if ($entity->getUuid() === null || $entity->getUuid() === '') {
            $entity->setUuid(Uuid::v4()->toRfc4122());
        }

        if ($entity->getStatus() === null || $entity->getStatus() === '') {
            $entity->setStatus('concept');
        }

        $now = new DateTime();
        if ($entity->getCreated() === null) {
            $entity->setCreated($now);
        }

        $entity->setUpdated($now);

        return parent::insert(entity: $entity);

    }//end insert()

    /**
     * Update with vocabulary validation. Bumps `updated`.
     *
     * @param Verwerkingsactiviteit $entity Entity to update.
     *
     * @return Verwerkingsactiviteit
     */
    public function update($entity): Verwerkingsactiviteit
    {
        $this->validate(entity: $entity);
        $entity->setUpdated(new DateTime());
        return parent::update(entity: $entity);

    }//end update()

    /**
     * Validate the entity's required fields + controlled vocabularies.
     *
     * @param Verwerkingsactiviteit $entity Entity to validate.
     *
     * @return void
     *
     * @throws InvalidArgumentException When validation fails.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function validate(Verwerkingsactiviteit $entity): void
    {
        if ($entity->getNaam() === null || trim((string) $entity->getNaam()) === '') {
            throw new InvalidArgumentException(
                'Verwerkingsactiviteit MUST have a naam (AVG Art 30 §1(a))'
            );
        }

        if ($entity->getDoelbinding() === null || trim((string) $entity->getDoelbinding()) === '') {
            throw new InvalidArgumentException(
                'Verwerkingsactiviteit MUST have a doelbinding (AVG Art 30 §1(b))'
            );
        }

        if (Verwerkingsactiviteit::isValidRechtsgrond($entity->getRechtsgrond()) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid rechtsgrond "%s"; expected one of: %s (AVG Art 6)',
                    (string) $entity->getRechtsgrond(),
                    implode(', ', Verwerkingsactiviteit::RECHTSGROND_VOCABULARY)
                )
            );
        }

        if ($entity->getStatus() !== null
            && $entity->getStatus() !== ''
            && Verwerkingsactiviteit::isValidStatus($entity->getStatus()) === false
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid status "%s"; expected one of: %s',
                    (string) $entity->getStatus(),
                    implode(', ', Verwerkingsactiviteit::STATUS_VOCABULARY)
                )
            );
        }

    }//end validate()
}//end class
