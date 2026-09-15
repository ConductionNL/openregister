<?php

/**
 * ExportRightService — may read and may take it all with you are two grants.
 *
 * OpenRegister exported well and gated the export behind `read`, which means
 * every reader could take the whole set off the instance. The AVG treats
 * reading a record and transferring a dataset as different acts, and so does
 * this service: `export` is its own verb, evaluated beside `read` on every
 * export path, the API included.
 *
 * DEFAULTS TO GRANTED, deliberately (design D-2). A verb that ships denied
 * breaks every instance on the morning of the upgrade, and a security control
 * that arrives silently gets turned off in a hurry. So a schema whose
 * authorization block says nothing about `export` falls back to its `read`
 * grant, and an administrator narrows it by writing the key. Once the key is
 * written it is the only rule: falling back to `read` after an administrator
 * has said who may export would make the control unenforceable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a caller holds the right to export, and names the verb when
 * they do not.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */
class ExportRightService {
	/**
	 * The right an export requires.
	 *
	 * @var string
	 */
	public const ACTION = 'export';

	/**
	 * The right an export falls back to while no administrator has narrowed it.
	 *
	 * @var string
	 */
	public const FALLBACK_ACTION = 'read';

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
	 * The refusal for the session's caller, or null when they may export.
	 *
	 * @param Schema|null $schema The schema being exported, when it resolves.
	 *
	 * @return ExportRefusedException|null The refusal, or null when allowed.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function refusalFor(?Schema $schema): ?ExportRefusedException {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new ExportRefusedException(
				rule: 'not-authenticated',
				reason: 'Exporting requires an authenticated caller.',
				statusCode: 401
			);
		}

		return $this->refusalForUid(schema: $schema, userId: $user->getUID());
	}//end refusalFor()

	/**
	 * The refusal for a named principal, or null when they may export.
	 *
	 * The scheduled runner and the whole-set job both act as an owner who is not
	 * the caller, so the uid is a parameter rather than a session read. A verb
	 * that only held on the interactive path would be the hidden-button control
	 * this change exists to replace.
	 *
	 * @param Schema|null $schema The schema being exported, when it resolves.
	 * @param string      $userId The principal the export runs as.
	 *
	 * @return ExportRefusedException|null The refusal, or null when allowed.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function refusalForUid(?Schema $schema, string $userId): ?ExportRefusedException {
		if ($this->groupManager->isAdmin($userId) === true) {
			return null;
		}

		if ($schema === null) {
			// A register-wide export with no single schema is checked per schema
			// by its caller. Reaching here with nothing to read means the rule
			// cannot be read, and an unreadable rule refuses.
			return new ExportRefusedException(
				rule: 'schema-unresolvable',
				reason: 'The schema cannot be resolved, so the right to export cannot be read. '
					. 'An unreadable rule refuses.',
				statusCode: 403
			);
		}

		$action = self::ACTION;
		if ($this->declaresExport(schema: $schema) === false) {
			// Nobody has narrowed the verb on this schema yet, so it is held
			// wherever `read` is held. See design D-2.
			$action = self::FALLBACK_ACTION;
		}

		if ($this->holdsRight(schema: $schema, action: $action, userId: $userId) === true) {
			return null;
		}

		return new ExportRefusedException(
			rule: 'export-right-missing',
			reason: 'User ' . $userId . ' does not hold the export right on schema '
				. ($schema->getSlug() ?? (string)$schema->getId())
				. '. Reading this schema and taking its data off the instance are separate grants.',
			statusCode: 403,
			context: [
				'schema' => ($schema->getSlug() ?? (string)$schema->getId()),
				'evaluated' => $action,
			]
		);
	}//end refusalForUid()

	/**
	 * Whether the schema's authorization block names the export verb.
	 *
	 * A block that cannot be resolved counts as not declaring it, so the export
	 * falls back to `read` rather than refusing a caller whose read already
	 * worked. The fallback is the safe side here precisely because `read` is
	 * itself enforced: nothing widens, one grant simply stands in for two.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when an administrator has written the export key.
	 */
	private function declaresExport(Schema $schema): bool {
		try {
			$authorization = $this->permissionHandler->resolveAuthorization(schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ExportRight] Authorization unresolvable, falling back to the read grant',
				context: ['schema' => $schema->getId(), 'error' => $e->getMessage()]
			);
			return false;
		}

		if ($authorization === null || isset($authorization[self::ACTION]) === false) {
			return false;
		}

		return is_array($authorization[self::ACTION]);
	}//end declaresExport()

	/**
	 * Whether the caller holds the named action on the schema.
	 *
	 * @param Schema $schema The schema.
	 * @param string $action The verb to evaluate.
	 * @param string $userId The caller.
	 *
	 * @return bool True when the caller holds it.
	 */
	private function holdsRight(Schema $schema, string $action, string $userId): bool {
		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: $action,
				userId: $userId,
				objectOwner: null,
				_rbac: true,
				object: null
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ExportRight] Permission check failed, refusing the export',
				context: ['schema' => $schema->getId(), 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end holdsRight()
}//end class
