<?php

/**
 * Records who widened or narrowed access to a record, on the hash-chained trail.
 *
 * A holder of the special right `grant_manage` (and the owner, and an
 * administrator) can grant, revoke and name the owning group of a record, and
 * an administrator can change which groups hold the right. Before this, none
 * of those writes left an audit entry, while an ownership handover did, so
 * "who let this principal in, and when" was the one question about a record's
 * access the trail could not answer.
 *
 * Every entry goes through {@see AuditTrailMapper}, which seals it into the
 * chain like any other; there is no second log. The right's own list is not
 * an object, so its change is a settings change and is written by
 * {@see SettingsChangeAuditor}, on the same chain.
 *
 * FAIL-SOFT, like the settings auditor: the grant has already been stored when
 * this runs, and failing here would report a failed grant for access that was
 * in fact given. A missing entry is logged at ERROR instead.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a change of who may reach a record into audit-trail entries.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class GrantChangeAuditor {

	/**
	 * The typed action a grant on a record writes.
	 *
	 * @var string
	 */
	public const ACTION_GRANTED = 'grant_added';

	/**
	 * The typed action a revoked grant writes.
	 *
	 * @var string
	 */
	public const ACTION_REVOKED = 'grant_revoked';

	/**
	 * The typed action a change of a record's owning group writes.
	 *
	 * @var string
	 */
	public const ACTION_OWNER_GROUP = 'owner_group_changed';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $mapper The trail, which owns the chain.
	 * @param SettingsChangeAuditor $settings Writes the change of an organisation's special right list.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $mapper,
		private readonly SettingsChangeAuditor $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a grant on a record.
	 *
	 * @param ObjectEntity $object The record.
	 * @param array<string, mixed> $grant The grant as created: `id`, `type`, `sharedWith`, `permissions`, `verbs`.
	 * @param string|null $admittedAs How the caller was admitted (GrantManageGuard::AS_OWNER or ::AS_HOLDER).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function recordGrant(ObjectEntity $object, array $grant, ?string $admittedAs): void {
		$this->write(object: $object, action: self::ACTION_GRANTED, changed: $this->describe(grant: $grant, admittedAs: $admittedAs));
	}//end recordGrant()

	/**
	 * Record a revoked grant on a record.
	 *
	 * @param ObjectEntity $object The record.
	 * @param array<string, mixed> $grant The grant as it was: `id`, `type`, `sharedWith`, `permissions`.
	 * @param string|null $admittedAs How the caller was admitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function recordRevoke(ObjectEntity $object, array $grant, ?string $admittedAs): void {
		$this->write(object: $object, action: self::ACTION_REVOKED, changed: $this->describe(grant: $grant, admittedAs: $admittedAs));
	}//end recordRevoke()

	/**
	 * Record a change of a record's owning group.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string|null $before The owning group before, or null for none.
	 * @param string|null $after The owning group after, or null for none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function recordOwnerGroup(ObjectEntity $object, ?string $before, ?string $after): void {
		if ($before === $after) {
			return;
		}

		$this->write(
			object: $object,
			action: self::ACTION_OWNER_GROUP,
			changed: ['ownerGroup' => ['old' => $before, 'new' => $after]]
		);
	}//end recordOwnerGroup()

	/**
	 * Record a change of the groups that hold the special right on an organisation's records.
	 *
	 * @param string $organisationUuid The organisation.
	 * @param array<int|string, mixed> $before The list before.
	 * @param array<int|string, mixed> $after The list after.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function recordSpecialRight(string $organisationUuid, array $before, array $after): void {
		$key = 'organisation ' . $organisationUuid . ': ' . OrganisationSpecialRights::GRANT_MANAGE;

		$this->settings->recordUpdate(app: 'openregister', before: [$key => $before], after: [$key => $after]);
	}//end recordSpecialRight()

	/**
	 * What of a grant the entry names.
	 *
	 * @param array<string, mixed> $grant The grant.
	 * @param string|null $admittedAs How the caller was admitted.
	 *
	 * @return array<string, mixed> The change set of the entry.
	 */
	private function describe(array $grant, ?string $admittedAs): array {
		return [
			'grant' => $grant['id'] ?? null,
			'type' => $grant['type'] ?? null,
			'sharedWith' => $grant['sharedWith'] ?? null,
			'permissions' => $grant['permissions'] ?? null,
			'verbs' => ($grant['verbs'] ?? []),
			'admittedAs' => $admittedAs,
		];
	}//end describe()

	/**
	 * Write one entry; never throws.
	 *
	 * @param ObjectEntity $object The record.
	 * @param string $action The typed action.
	 * @param array<string, mixed> $changed The change set.
	 *
	 * @return void
	 */
	private function write(ObjectEntity $object, string $action, array $changed): void {
		try {
			$this->mapper->createAuditTrailEntry(object: $object, action: $action, context: $changed);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[GrantChangeAuditor] Could not record a change of who may reach a record; the change itself was stored',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'action' => $action,
					'uuid' => $object->getUuid(),
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end write()
}//end class
