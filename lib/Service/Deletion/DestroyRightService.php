<?php

/**
 * DestroyRightService — destroying is a right, not an administrator check.
 *
 * The deletion-audit-trail spec said admin-only "SHOULD" be enforced, which is
 * the shape that produces a different answer in every deployment and cannot
 * express "only the record manager may delete a document" at all. The verb is
 * now `destroy`, declared in the schema's authorization block like any other
 * action, and refused with the rule that refused it.
 *
 * FAILS CLOSED, deliberately and as a behaviour change. Before this change a
 * caller holding `delete` could purge; now the right has to be declared and
 * granted. Administrators still bypass, so no instance locks itself out, and
 * an instance that grants `destroy` to the same group it granted `delete` to
 * is back where it was in one edit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a caller holds the right to destroy, and names the rule when
 * they do not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 */
class DestroyRightService {
	/**
	 * The right a destruction requires.
	 *
	 * @var string
	 */
	public const ACTION = 'destroy';

	/**
	 * Wire the RBAC collaborators.
	 *
	 * @param PermissionHandler $permissionHandler Canonical RBAC verdict.
	 * @param IUserSession      $userSession       The calling principal.
	 * @param IGroupManager     $groupManager      Administrator bypass.
	 * @param LoggerInterface   $logger            PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PermissionHandler $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The refusal for this caller, or null when they may destroy.
	 *
	 * @param ObjectEntity $object The object being destroyed.
	 * @param Schema|null  $schema The object's schema, when it resolves.
	 *
	 * @return DestructionRefusedException|null The refusal, or null when allowed.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function refusalFor(ObjectEntity $object, ?Schema $schema): ?DestructionRefusedException {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DestructionRefusedException(
				rule: 'not-authenticated',
				reason: 'Destroying an object requires an authenticated caller.',
				statusCode: 401
			);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		if ($schema === null) {
			return new DestructionRefusedException(
				rule: 'schema-unresolvable',
				reason: 'The object\'s schema cannot be resolved, so the right to destroy cannot be read. '
					. 'An unreadable rule refuses.',
				statusCode: 403
			);
		}

		$declared = $this->declaredGroups(schema: $schema, object: $object);
		if ($declared === []) {
			return new DestructionRefusedException(
				rule: 'destroy-right-undeclared',
				reason: 'No group holds the destroy right on schema '
					. ($schema->getSlug() ?? (string)$schema->getId())
					. '. Declare it in the schema authorization block before anything can be destroyed.',
				statusCode: 403,
				context: ['right' => self::ACTION]
			);
		}

		if ($this->holdsRight(schema: $schema, object: $object, userId: $user->getUID()) === false) {
			return new DestructionRefusedException(
				rule: 'destroy-right-missing',
				reason: 'User ' . $user->getUID() . ' does not hold the destroy right on schema '
					. ($schema->getSlug() ?? (string)$schema->getId()) . '.',
				statusCode: 403,
				context: [
					'right' => self::ACTION,
					'grantedTo' => $declared,
				]
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * The groups the schema declares the destroy right for.
	 *
	 * An authorization cascade that cannot be evaluated returns no groups, so
	 * the caller refuses rather than treating "unreadable" as "unrestricted".
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object, whose own block may override.
	 *
	 * @return array<int, string> The declared group ids.
	 */
	private function declaredGroups(Schema $schema, ObjectEntity $object): array {
		try {
			$authorization = $this->permissionHandler->resolveAuthorization(schema: $schema, object: $object);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[DestroyRight] Authorization unresolvable, refusing the destroy',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
			return [];
		}

		if ($authorization === null || isset($authorization[self::ACTION]) === false) {
			return [];
		}

		$groups = $authorization[self::ACTION];
		if (is_array($groups) === false) {
			return [];
		}

		return array_values(array_filter($groups, 'is_string'));
	}//end declaredGroups()

	/**
	 * Whether the caller holds the declared right.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object.
	 * @param string       $userId The caller.
	 *
	 * @return bool True when the caller may destroy.
	 */
	private function holdsRight(Schema $schema, ObjectEntity $object, string $userId): bool {
		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: self::ACTION,
				userId: $userId,
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[DestroyRight] Permission check failed, refusing the destroy',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end holdsRight()
}//end class
