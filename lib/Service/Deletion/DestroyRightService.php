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
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a caller holds the right to destroy, and names the rule when
 * they do not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
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
	 * @param PermissionHandlerInterface $permissionHandler Canonical RBAC verdict.
	 * @param CallerContext              $caller            The calling principal and the administrator bypass.
	 * @param LoggerInterface            $logger            PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly CallerContext $caller,
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
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function refusalFor(ObjectEntity $object, ?Schema $schema): ?DestructionRefusedException {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return self::unauthenticatedRefusal();
		}

		if ($this->caller->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		return $this->schemaRefusal(run: new DestroyRightRun(object: $object, caller: $user), schema: $schema);
	}//end refusalFor()

	/**
	 * The refusal for a caller without a session.
	 *
	 * @return DestructionRefusedException
	 */
	private static function unauthenticatedRefusal(): DestructionRefusedException {
		return new DestructionRefusedException(
			rule: 'not-authenticated',
			reason: 'Destroying an object requires an authenticated caller.',
			statusCode: 401
		);
	}//end unauthenticatedRefusal()

	/**
	 * The refusal a signed-in, non-administrator caller gets from the schema, or null when allowed.
	 *
	 * @param DestroyRightRun $run    The object and the caller.
	 * @param Schema|null     $schema The object's schema, when it resolves.
	 *
	 * @return DestructionRefusedException|null The refusal, or null when allowed.
	 */
	private function schemaRefusal(DestroyRightRun $run, ?Schema $schema): ?DestructionRefusedException {
		if ($schema === null) {
			return new DestructionRefusedException(
				rule: 'schema-unresolvable',
				reason: 'The object\'s schema cannot be resolved, so the right to destroy cannot be read. '
					. 'An unreadable rule refuses.',
				statusCode: 403
			);
		}

		return $this->grantRefusal(run: $run, schema: $schema);
	}//end schemaRefusal()

	/**
	 * The refusal when the schema declares the right to nobody, or the caller does not hold it.
	 *
	 * @param DestroyRightRun $run    The object and the caller.
	 * @param Schema          $schema The object's schema.
	 *
	 * @return DestructionRefusedException|null The refusal, or null when the caller holds the right.
	 */
	private function grantRefusal(DestroyRightRun $run, Schema $schema): ?DestructionRefusedException {
		$declared = $this->declaredGroups(schema: $schema, object: $run->object);
		if ($declared === []) {
			return self::undeclaredRightRefusal(schema: $schema);
		}

		if ($this->holdsRight(schema: $schema, object: $run->object) === false) {
			return self::missingRightRefusal(
				reason: 'User ' . $run->caller->getUID() . ' does not hold the destroy right on schema '
					. self::schemaLabel(schema: $schema) . '.',
				declared: $declared
			);
		}

		return null;
	}//end grantRefusal()

	/**
	 * The schema as a refusal names it: its slug, or its id without one.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string
	 */
	private static function schemaLabel(Schema $schema): string {
		return ($schema->getSlug() ?? (string)$schema->getId());
	}//end schemaLabel()

	/**
	 * The refusal when no group holds the destroy right on the schema.
	 *
	 * @param Schema $schema The object's schema.
	 *
	 * @return DestructionRefusedException
	 */
	private static function undeclaredRightRefusal(Schema $schema): DestructionRefusedException {
		return new DestructionRefusedException(
			rule: 'destroy-right-undeclared',
			reason: 'No group holds the destroy right on schema '
				. self::schemaLabel(schema: $schema)
				. '. Declare it in the schema authorization block before anything can be destroyed.',
			statusCode: 403,
			context: ['right' => self::ACTION]
		);
	}//end undeclaredRightRefusal()

	/**
	 * The refusal when the caller does not hold the declared destroy right.
	 *
	 * @param string             $reason   Why the caller is refused.
	 * @param array<int, string> $declared The groups that hold the right.
	 *
	 * @return DestructionRefusedException
	 */
	private static function missingRightRefusal(string $reason, array $declared): DestructionRefusedException {
		return new DestructionRefusedException(
			rule: 'destroy-right-missing',
			reason: $reason,
			statusCode: 403,
			context: [
				'right' => self::ACTION,
				'grantedTo' => $declared,
			]
		);
	}//end missingRightRefusal()

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
	 * Whether the session's caller holds the declared right.
	 *
	 * The caller is read from the session; no caller holds no right.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when the caller may destroy.
	 */
	private function holdsRight(Schema $schema, ObjectEntity $object): bool {
		$userId = $this->caller->userSession->getUser()?->getUID();
		if ($userId === null) {
			return false;
		}

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
