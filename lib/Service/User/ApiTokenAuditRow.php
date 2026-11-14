<?php

/**
 * ApiTokenAuditRow: the audit entry for a personal API token minted or revoked.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCP\IUser;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the audit row that names a token, its owner and the actor.
 *
 * Only the token's id, name and lifetime are copied from the stored entry, by
 * name: the value, its hash and its last four characters never reach the row,
 * so a field added to the stored entry later cannot leak into the trail.
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */
final class ApiTokenAuditRow {

	/**
	 * The audit action of a minted token.
	 *
	 * @var string
	 */
	public const CREATED = 'api-token.created';

	/**
	 * The audit action of a revoked token.
	 *
	 * @var string
	 */
	public const REVOKED = 'api-token.revoked';

	/**
	 * The audit row.
	 *
	 * @param string               $action  CREATED or REVOKED.
	 * @param array<string, mixed> $token   The stored token entry (id, name, expires, ...).
	 * @param string               $ownerId The user the token belongs to.
	 * @param IUser|null           $actor   Who acted; null reads as the system.
	 *
	 * @return AuditTrail The unsealed row.
	 *
	 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
	 */
	public function row(string $action, array $token, string $ownerId, ?IUser $actor): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction($action);
		$row->setUser($actor?->getUID() ?? 'system');
		$row->setUserName($actor?->getDisplayName() ?? 'System');
		$row->setChanged(
			[
				'token' => [
					'id' => (string)($token['id'] ?? ''),
					'name' => (string)($token['name'] ?? ''),
					'expires' => $token['expires'] ?? null,
				],
				'owner' => $ownerId,
			]
		);
		$row->setCreated(new DateTime());

		return $row;
	}//end row()
}//end class
