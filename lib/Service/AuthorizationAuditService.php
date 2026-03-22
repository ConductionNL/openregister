<?php

/**
 * Authorization Audit Service
 *
 * Logs all changes to authorization configuration on registers and schemas.
 * Provides structured audit entries for compliance and debugging.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Audit service for authorization configuration changes
 *
 * Logs authorization changes via Nextcloud's structured logging system.
 * Each log entry includes who made the change, what changed, and the
 * old and new values for full audit trail compliance.
 */
class AuthorizationAuditService
{

    /**
     * Log event type for authorization changes.
     *
     * @var string
     */
    public const EVENT_TYPE = 'openregister_authorization';

    /**
     * Constructor.
     *
     * @param IUserSession    $userSession    User session for current user context.
     * @param RegisterMapper  $registerMapper Register mapper for cascade count.
     * @param LoggerInterface $logger         Logger for structured audit entries.
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Log a schema authorization change.
     *
     * @param int        $schemaId         The schema ID.
     * @param string     $schemaTitle      The schema title.
     * @param array|null $oldAuthorization The previous authorization value.
     * @param array|null $newAuthorization The new authorization value.
     *
     * @return void
     */
    public function logSchemaAuthorizationChange(
        int $schemaId,
        string $schemaTitle,
        ?array $oldAuthorization,
        ?array $newAuthorization
    ): void {
        $user     = $this->userSession->getUser();
        $userName = $user !== null ? $user->getDisplayName() : 'System';
        $userId   = $user !== null ? $user->getUID() : 'system';

        $this->logger->info(
            message: '[AuthorizationAudit] Schema authorization changed',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'event_type'        => self::EVENT_TYPE,
                'entity_type'       => 'schema',
                'entity_id'         => $schemaId,
                'entity_title'      => $schemaTitle,
                'changed_by_user'   => $userId,
                'changed_by_name'   => $userName,
                'old_authorization' => json_encode($oldAuthorization),
                'new_authorization' => json_encode($newAuthorization),
            ]
        );
    }//end logSchemaAuthorizationChange()

    /**
     * Log a register authorization change.
     *
     * @param int        $registerId       The register ID.
     * @param string     $registerTitle    The register title.
     * @param array|null $oldAuthorization The previous authorization value.
     * @param array|null $newAuthorization The new authorization value.
     *
     * @return void
     */
    public function logRegisterAuthorizationChange(
        int $registerId,
        string $registerTitle,
        ?array $oldAuthorization,
        ?array $newAuthorization
    ): void {
        $user     = $this->userSession->getUser();
        $userName = $user !== null ? $user->getDisplayName() : 'System';
        $userId   = $user !== null ? $user->getUID() : 'system';

        // Count schemas that will inherit this change.
        $affectedSchemaCount = 0;
        try {
            $register = $this->registerMapper->find($registerId);
            $schemas  = $register->getSchemas();
            foreach ($schemas as $schemaId) {
                // Count schemas without their own authorization (they cascade).
                // This is approximate -- we don't load each schema to check.
                $affectedSchemaCount++;
            }
        } catch (\Throwable $e) {
            // Could not count affected schemas.
        }

        $this->logger->info(
            message: '[AuthorizationAudit] Register authorization changed',
            context: [
                'file'                  => __FILE__,
                'line'                  => __LINE__,
                'event_type'            => self::EVENT_TYPE,
                'entity_type'           => 'register',
                'entity_id'             => $registerId,
                'entity_title'          => $registerTitle,
                'changed_by_user'       => $userId,
                'changed_by_name'       => $userName,
                'old_authorization'     => json_encode($oldAuthorization),
                'new_authorization'     => json_encode($newAuthorization),
                'affected_schema_count' => $affectedSchemaCount,
            ]
        );
    }//end logRegisterAuthorizationChange()

    /**
     * Log a role definition change on a register.
     *
     * @param int        $registerId    The register ID.
     * @param string     $registerTitle The register title.
     * @param array|null $oldRoles      The previous roles configuration.
     * @param array|null $newRoles      The new roles configuration.
     *
     * @return void
     */
    public function logRoleDefinitionChange(
        int $registerId,
        string $registerTitle,
        ?array $oldRoles,
        ?array $newRoles
    ): void {
        $user     = $this->userSession->getUser();
        $userName = $user !== null ? $user->getDisplayName() : 'System';
        $userId   = $user !== null ? $user->getUID() : 'system';

        $this->logger->info(
            message: '[AuthorizationAudit] Role definitions changed',
            context: [
                'file'            => __FILE__,
                'line'            => __LINE__,
                'event_type'      => self::EVENT_TYPE,
                'entity_type'     => 'register_roles',
                'entity_id'       => $registerId,
                'entity_title'    => $registerTitle,
                'changed_by_user' => $userId,
                'changed_by_name' => $userName,
                'old_roles'       => json_encode($oldRoles),
                'new_roles'       => json_encode($newRoles),
            ]
        );
    }//end logRoleDefinitionChange()
}//end class
