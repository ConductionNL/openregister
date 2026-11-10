<?php

/**
 * Authorization Audit Service
 *
 * Logs all changes to authorization configuration on registers and schemas.
 * Provides structured audit entries for compliance and debugging.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-authorization-cache
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * Audit service for authorization configuration changes
 *
 * Logs authorization changes via Nextcloud's structured logging system.
 * Each log entry includes who made the change, what changed, and the
 * old and new values for full audit trail compliance.
 */
class AuthorizationAuditService {

	/**
	 * Log event type for authorization changes.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'openregister_authorization';

	/**
	 * Constructor.
	 *
	 * @param LinkSessionRuntime      $runtime        The user session (for the actor) and the logger.
	 * @param RegisterMapperInterface $registerMapper Register mapper for cascade count.
	 */
	public function __construct(
		private readonly LinkSessionRuntime $runtime,
		private readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()

	/**
	 * Log a schema authorization change.
	 *
	 * @param int $schemaId The schema ID.
	 * @param string $schemaTitle The schema title.
	 * @param array|null $oldAuthorization The previous authorization value.
	 * @param array|null $newAuthorization The new authorization value.
	 *
	 * @return void
	 *
	 * @spec exclude Facade plumbing: emits one structured audit log line; sibling of recordRegisterChange
	 *              (rbac-scopes REQ-002), no distinct behavioral contract.
	 */
	public function logSchemaAuthorizationChange(int $schemaId, string $schemaTitle, ?array $oldAuthorization, ?array $newAuthorization): void {
		$change = new AuthorizationChange(entityId: $schemaId, entityTitle: $schemaTitle, before: $oldAuthorization, after: $newAuthorization);
		$this->recordSchemaChange(change: $change);
	}//end logSchemaAuthorizationChange()

	/**
	 * Record a change to a schema's authorization.
	 *
	 * @param AuthorizationChange $change The schema and its old and new authorization.
	 *
	 * @return void
	 *
	 * @spec exclude Facade plumbing: the value-object form of logSchemaAuthorizationChange.
	 */
	public function recordSchemaChange(AuthorizationChange $change): void {
		$this->logChange(
			message: '[AuthorizationAudit] Schema authorization changed',
			fields: $this->entityFields(type: 'schema', change: $change)
				+ $this->stateFields(name: 'authorization', change: $change)
		);
	}//end recordSchemaChange()

	/**
	 * Record a change to a register's authorization, with the count of schemas the cascade affects.
	 *
	 * @param AuthorizationChange $change The register and its old and new authorization.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function recordRegisterChange(AuthorizationChange $change): void {
		$this->logChange(
			message: '[AuthorizationAudit] Register authorization changed',
			fields: $this->entityFields(type: 'register', change: $change)
				+ $this->stateFields(name: 'authorization', change: $change)
				+ ['affected_schema_count' => $this->affectedSchemaCount(registerId: $change->entityId)]
		);
	}//end recordRegisterChange()

	/**
	 * Record a change to a register's role definitions.
	 *
	 * @param AuthorizationChange $change The register and its old and new role definitions.
	 *
	 * @return void
	 *
	 * @spec exclude Facade plumbing: a role-definition change on a register.
	 */
	public function recordRoleChange(AuthorizationChange $change): void {
		$this->logChange(
			message: '[AuthorizationAudit] Role definitions changed',
			fields: $this->entityFields(type: 'register_roles', change: $change)
				+ $this->stateFields(name: 'roles', change: $change)
		);
	}//end recordRoleChange()

	/**
	 * The entity a change is about, followed by who made it.
	 *
	 * @param string              $type   The entity type the entry names.
	 * @param AuthorizationChange $change The change.
	 *
	 * @return array<string, mixed>
	 */
	private function entityFields(string $type, AuthorizationChange $change): array {
		return ['entity_type' => $type, 'entity_id' => $change->entityId, 'entity_title' => $change->entityTitle]
			+ $this->changedBy();
	}//end entityFields()

	/**
	 * The JSON-encoded old and new values of a change, as `old_<name>` and `new_<name>`.
	 *
	 * @param string              $name   The name of the changed setting.
	 * @param AuthorizationChange $change The change.
	 *
	 * @return array<string, string|false>
	 */
	private function stateFields(string $name, AuthorizationChange $change): array {
		return ['old_'.$name => json_encode($change->before), 'new_'.$name => json_encode($change->after)];
	}//end stateFields()

	/**
	 * Who made the change: the session user, or the system.
	 *
	 * @return array{changed_by_user: string, changed_by_name: string}
	 */
	private function changedBy(): array {
		$user = $this->runtime->userSession->getUser();
		$userName = 'System';
		$userId = 'system';
		if ($user !== null) {
			$userName = $user->getDisplayName();
			$userId = $user->getUID();
		}

		return ['changed_by_user' => $userId, 'changed_by_name' => $userName];
	}//end changedBy()

	/**
	 * How many schemas a register change cascades to.
	 *
	 * This is approximate -- we don't load each schema to check whether it has
	 * its own authorization. Zero when the register cannot be read.
	 *
	 * @param int $registerId The register ID.
	 *
	 * @return int The number of schemas on the register.
	 */
	private function affectedSchemaCount(int $registerId): int {
		$affectedSchemaCount = 0;
		try {
			$register = $this->registerMapper->find($registerId);
			$schemas = $register->getSchemas();
			if (is_array($schemas) === true) {
				$affectedSchemaCount = count($schemas);
			}
		} catch (\Throwable $e) {
			// The count is informational; an unreadable register reports zero.
			$this->runtime->logger->debug(
				message: '[AuthorizationAuditService] Could not count the schemas of a register',
				context: [
					'registerId' => $registerId,
					'exception' => $e->getMessage(),
				]
			);
		}

		return $affectedSchemaCount;
	}//end affectedSchemaCount()

	/**
	 * Write one structured audit entry.
	 *
	 * @param string              $message The log message.
	 * @param array<string,mixed> $fields  The entry's fields after file, line and event type.
	 *
	 * @return void
	 */
	private function logChange(string $message, array $fields): void {
		$this->runtime->logger->info(
			message: $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'event_type' => self::EVENT_TYPE,
			] + $fields
		);
	}//end logChange()
}//end class
