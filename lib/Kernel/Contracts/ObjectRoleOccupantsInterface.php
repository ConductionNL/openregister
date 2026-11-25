<?php

/**
 * ObjectRoleOccupantsInterface: which party roles a user holds on one object today.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The role occupants a grant to a slot follows, for access code outside Integrations to ask.
 */
interface ObjectRoleOccupantsInterface {

	/**
	 * The roles a Nextcloud user occupies on one object at this moment.
	 *
	 * A link counts only when it names this user, carries a role and its
	 * period covers today. A failure to read the links is thrown, so the
	 * caller decides what an unanswerable question means.
	 *
	 * @param string $objectUuid The object.
	 * @param string $userId     The user.
	 *
	 * @return array<int, string> The roles held, each once.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public function activeUserRoles(string $objectUuid, string $userId): array;
}//end interface
