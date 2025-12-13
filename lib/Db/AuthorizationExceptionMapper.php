<?php

/**
 * OpenRegister Authorization Exception Mapper
 *
 * This file contains the mapper class for handling authorization exception
 * database operations in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for authorization exception database operations
 *
 * This class handles all database operations for authorization exceptions,
 * including CRUD operations and specialized queries for the authorization system.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git_id>
 * @link    https://www.OpenRegister.app
 *
 * @method AuthorizationException insert(Entity $entity)
 * @method AuthorizationException update(Entity $entity)
 * @method AuthorizationException insertOrUpdate(Entity $entity)
 * @method AuthorizationException delete(Entity $entity)
 * @method AuthorizationException find(int|string $id)
 * @method AuthorizationException findEntity(IQueryBuilder $query)
 * @method AuthorizationException[] findAll(int|null $limit = null, int|null $offset = null)
 * @method list<AuthorizationException> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<AuthorizationException>
 */
class AuthorizationExceptionMapper extends QBMapper
{


    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_authorization_exceptions', AuthorizationException::class);

    }//end __construct()


    /**
     * Create a new authorization exception
     *
     * This method creates a new authorization exception with automatic UUID generation
     * and timestamp management.
     *
     * @param AuthorizationException $exception The authorization exception to create
     * @param string                 $userId    The user ID creating the exception
     *
     * @return AuthorizationException The created authorization exception
     */
    public function createException(AuthorizationException $exception, string $userId): AuthorizationException
    {
        // Generate UUID if not set.
        if ($exception->getUuid() === null) {
            $exception->setUuid(Uuid::v4()->toRfc4122());
        }

        // Set creation metadata.
        $exception->setCreatedBy($userId);
        $exception->setCreatedAt(new \DateTime());
        $exception->setUpdatedAt(new \DateTime());

        return $this->insert($exception);

    }//end createException()


    /**
     * Update an existing authorization exception
     *
     * This method updates an authorization exception and automatically
     * updates the updatedAt timestamp.
     *
     * @param AuthorizationException $exception The authorization exception to update
     *
     * @return AuthorizationException The updated authorization exception
     */
    public function updateException(AuthorizationException $exception): AuthorizationException
    {
        // Update the timestamp.
        $exception->setUpdatedAt(new \DateTime());

        return $this->update($exception);

    }//end updateException()


    /**
     * Find an authorization exception by UUID
     *
     * @param string $uuid The UUID of the authorization exception
     *
     * @throws DoesNotExistException         If no exception is found
     * @throws MultipleObjectsReturnedException If multiple exceptions are found
     *
     * @return AuthorizationException The found authorization exception
     */
    public function findByUuid(string $uuid): AuthorizationException
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        return $this->findEntity($qb);

    }//end findByUuid()


    /**
     * Find all active authorization exceptions for a subject
     *
     * @param string $subjectType The subject type (user or group)
     * @param string $subjectId   The subject ID
     * @param bool   $activeOnly  Whether to return only active exceptions
     *
     * @return AuthorizationException[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\AuthorizationException>
     */
    public function findBySubject(string $subjectType, string $subjectId, bool $activeOnly=true): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
            ->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)));

        if ($activeOnly === true) {
            $qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        return $this->findEntities($qb);

    }//end findBySubject()


    /**
     * Find authorization exceptions that apply to specific criteria
     *
     * This method finds all authorization exceptions that match the given criteria,
     * ordered by priority (highest first) for proper exception resolution.
     *
     * @param string      $subjectType      The subject type (user or group)
     * @param string      $subjectId        The subject ID
     * @param string      $action           The action (create, read, update, delete)
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     * @param bool        $activeOnly       Whether to return only active exceptions
     *
     * @return AuthorizationException[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\AuthorizationException>
     */
    public function findApplicableExceptions(
        string $subjectType,
        string $subjectId,
        string $action,
        ?string $schemaUuid=null,
        ?string $registerUuid=null,
        ?string $organizationUuid=null,
        bool $activeOnly=true
    ): array {
        $qb = $this->db->getQueryBuilder();

        // Base conditions.
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
            ->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));

        // Active filter.
        if ($activeOnly === true) {
            $qb->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        // Schema filter (null means applies to all schemas).
        if ($schemaUuid !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('schema_uuid'),
                    $qb->expr()->eq('schema_uuid', $qb->createNamedParameter($schemaUuid))
                )
            );
        }

        // Register filter (null means applies to all registers).
        if ($registerUuid !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('register_uuid'),
                    $qb->expr()->eq('register_uuid', $qb->createNamedParameter($registerUuid))
                )
            );
        }

        // Organization filter (null means applies to all organizations).
        if ($organizationUuid !== null) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('organization_uuid'),
                    $qb->expr()->eq('organization_uuid', $qb->createNamedParameter($organizationUuid))
                )
            );
        }

        // Order by priority (highest first), then by creation date (newest first).
        $qb->orderBy('priority', 'DESC')
            ->addOrderBy('created_at', 'DESC');

        return $this->findEntities($qb);

    }//end findApplicableExceptions()


}//end class
