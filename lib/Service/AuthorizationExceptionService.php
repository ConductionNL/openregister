<?php

/**
 * OpenRegister Authorization Exception Service
 *
 * This service handles the business logic for authorization exceptions
 * in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuthorizationException;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use OCP\IUserSession;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Service class for managing authorization exceptions
 *
 * This service provides business logic for creating, managing, and evaluating
 * authorization exceptions that override the standard RBAC system.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationExceptionService
{

    /**
     * Authorization exception mapper instance
     *
     * @var AuthorizationExceptionMapper
     */
    private AuthorizationExceptionMapper $mapper;

    /**
     * User session instance
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager instance
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor for the AuthorizationExceptionService
     *
     * @param AuthorizationExceptionMapper $mapper       The authorization exception mapper
     * @param IUserSession                 $userSession  The user session
     * @param IGroupManager                $groupManager The group manager
     * @param LoggerInterface              $logger       The logger
     */
    public function __construct(
        AuthorizationExceptionMapper $mapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->mapper       = $mapper;
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
        $this->logger       = $logger;

    }//end __construct()


    /**
     * Create a new authorization exception
     *
     * @param string      $type             The exception type (inclusion or exclusion)
     * @param string      $subjectType      The subject type (user or group)
     * @param string      $subjectId        The subject ID
     * @param string      $action           The action (create, read, update, delete)
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     * @param int         $priority         Priority for exception resolution
     * @param string|null $description      Optional description
     *
     * @throws InvalidArgumentException If invalid parameters are provided
     *
     * @return AuthorizationException The created authorization exception
     */
    public function createException(
        string $type,
        string $subjectType,
        string $subjectId,
        string $action,
        ?string $schemaUuid = null,
        ?string $registerUuid = null,
        ?string $organizationUuid = null,
        int $priority = 0,
        ?string $description = null
    ): AuthorizationException {
        // Get current user
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('No authenticated user to create authorization exception');
        }

        // Validate input parameters
        $this->validateExceptionParameters($type, $subjectType, $subjectId, $action);

        // Create the exception entity
        $exception = new AuthorizationException();
        $exception->setType($type);
        $exception->setSubjectType($subjectType);
        $exception->setSubjectId($subjectId);
        $exception->setAction($action);
        $exception->setSchemaUuid($schemaUuid);
        $exception->setRegisterUuid($registerUuid);
        $exception->setOrganizationUuid($organizationUuid);
        $exception->setPriority($priority);
        $exception->setDescription($description);

        // Save to database
        $createdException = $this->mapper->createException($exception, $user->getUID());

        $this->logger->info('Authorization exception created', [
            'uuid'             => $createdException->getUuid(),
            'type'             => $type,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'action'           => $action,
            'schema_uuid'      => $schemaUuid,
            'created_by'       => $user->getUID(),
        ]);

        return $createdException;

    }//end createException()


    /**
     * Evaluate authorization exceptions for a user and action
     *
     * This method determines if a user has permission based on authorization exceptions.
     * It returns:
     * - true: User has explicit permission (inclusion found)
     * - false: User is explicitly denied (exclusion found)
     * - null: No applicable exceptions, fall back to normal RBAC
     *
     * @param string      $userId           The user ID to check
     * @param string      $action           The action to check
     * @param string|null $schemaUuid       Optional schema UUID
     * @param string|null $registerUuid     Optional register UUID
     * @param string|null $organizationUuid Optional organization UUID
     *
     * @return bool|null True if allowed, false if denied, null if no applicable exceptions
     */
    public function evaluateUserPermission(
        string $userId,
        string $action,
        ?string $schemaUuid = null,
        ?string $registerUuid = null,
        ?string $organizationUuid = null
    ): ?bool {
        // Get all applicable exceptions for this user
        $userExceptions = $this->mapper->findApplicableExceptions(
            AuthorizationException::SUBJECT_TYPE_USER,
            $userId,
            $action,
            $schemaUuid,
            $registerUuid,
            $organizationUuid
        );

        // Get user's groups and find applicable group exceptions
        $userObj = $this->groupManager->getUserGroups($this->groupManager->get($userId));
        $groupExceptions = [];
        
        foreach ($userObj as $group) {
            $groupId = $group->getGID();
            $exceptions = $this->mapper->findApplicableExceptions(
                AuthorizationException::SUBJECT_TYPE_GROUP,
                $groupId,
                $action,
                $schemaUuid,
                $registerUuid,
                $organizationUuid
            );
            $groupExceptions = array_merge($groupExceptions, $exceptions);
        }

        // Combine all exceptions and sort by priority
        $allExceptions = array_merge($userExceptions, $groupExceptions);
        usort($allExceptions, function (AuthorizationException $a, AuthorizationException $b): int {
            return $b->getPriority() <=> $a->getPriority(); // Sort by priority descending
        });

        // Evaluate exceptions in priority order
        foreach ($allExceptions as $exception) {
            if ($exception->isExclusion()) {
                // Exclusion found - user is denied access
                $this->logger->debug('Authorization exclusion applied', [
                    'user_id'          => $userId,
                    'action'           => $action,
                    'exception_uuid'   => $exception->getUuid(),
                    'subject_type'     => $exception->getSubjectType(),
                    'subject_id'       => $exception->getSubjectId(),
                    'priority'         => $exception->getPriority(),
                ]);
                return false;
            }

            if ($exception->isInclusion()) {
                // Inclusion found - user is granted access
                $this->logger->debug('Authorization inclusion applied', [
                    'user_id'          => $userId,
                    'action'           => $action,
                    'exception_uuid'   => $exception->getUuid(),
                    'subject_type'     => $exception->getSubjectType(),
                    'subject_id'       => $exception->getSubjectId(),
                    'priority'         => $exception->getPriority(),
                ]);
                return true;
            }
        }

        // No applicable exceptions found - fall back to normal RBAC
        return null;

    }//end evaluateUserPermission()


    /**
     * Check if a user has any authorization exceptions
     *
     * @param string $userId The user ID to check
     *
     * @return bool True if user has any active exceptions
     */
    public function userHasExceptions(string $userId): bool
    {
        $userExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_USER, $userId);
        
        if (count($userExceptions) > 0) {
            return true;
        }

        // Check group exceptions
        $userObj = $this->groupManager->get($userId);
        if ($userObj === null) {
            return false;
        }
        
        $userGroups = $this->groupManager->getUserGroups($userObj);
        foreach ($userGroups as $group) {
            $groupExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_GROUP, $group->getGID());
            if (count($groupExceptions) > 0) {
                return true;
            }
        }

        return false;

    }//end userHasExceptions()


    /**
     * Get all authorization exceptions for a user (including group exceptions)
     *
     * @param string $userId The user ID
     *
     * @return array<AuthorizationException> Array of authorization exceptions
     */
    public function getUserExceptions(string $userId): array
    {
        // Get direct user exceptions
        $userExceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_USER, $userId);

        // Get group exceptions
        $userObj = $this->groupManager->get($userId);
        $groupExceptions = [];
        
        if ($userObj !== null) {
            $userGroups = $this->groupManager->getUserGroups($userObj);
            foreach ($userGroups as $group) {
                $exceptions = $this->mapper->findBySubject(AuthorizationException::SUBJECT_TYPE_GROUP, $group->getGID());
                $groupExceptions = array_merge($groupExceptions, $exceptions);
            }
        }

        // Combine and sort by priority
        $allExceptions = array_merge($userExceptions, $groupExceptions);
        usort($allExceptions, function (AuthorizationException $a, AuthorizationException $b): int {
            return $b->getPriority() <=> $a->getPriority();
        });

        return $allExceptions;

    }//end getUserExceptions()


    /**
     * Validate exception parameters
     *
     * @param string $type        The exception type
     * @param string $subjectType The subject type
     * @param string $subjectId   The subject ID
     * @param string $action      The action
     *
     * @throws InvalidArgumentException If any parameter is invalid
     *
     * @return void
     */
    private function validateExceptionParameters(
        string $type,
        string $subjectType,
        string $subjectId,
        string $action
    ): void {
        // Validate type
        if (!in_array($type, AuthorizationException::getValidTypes(), true)) {
            throw new InvalidArgumentException(
                'Invalid exception type: ' . $type . '. Valid types: ' . implode(', ', AuthorizationException::getValidTypes())
            );
        }

        // Validate subject type
        if (!in_array($subjectType, AuthorizationException::getValidSubjectTypes(), true)) {
            throw new InvalidArgumentException(
                'Invalid subject type: ' . $subjectType . '. Valid types: ' . implode(', ', AuthorizationException::getValidSubjectTypes())
            );
        }

        // Validate action
        if (!in_array($action, AuthorizationException::getValidActions(), true)) {
            throw new InvalidArgumentException(
                'Invalid action: ' . $action . '. Valid actions: ' . implode(', ', AuthorizationException::getValidActions())
            );
        }

        // Validate subject exists
        if ($subjectType === AuthorizationException::SUBJECT_TYPE_USER) {
            // @todo Could add user existence validation here
        } elseif ($subjectType === AuthorizationException::SUBJECT_TYPE_GROUP) {
            if (!$this->groupManager->groupExists($subjectId)) {
                throw new InvalidArgumentException('Group does not exist: ' . $subjectId);
            }
        }

    }//end validateExceptionParameters()


}//end class
