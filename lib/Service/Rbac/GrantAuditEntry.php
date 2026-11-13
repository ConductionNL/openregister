<?php

/**
 * OpenRegister grant audit entry
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * One audit-trail entry about a change of who may reach a record: the typed
 * action and the change set written with it.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class GrantAuditEntry {
	/**
	 * Constructor.
	 *
	 * @param string               $action  The typed action (one of GrantChangeAuditor::ACTION_*).
	 * @param array<string, mixed> $changed The change set.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $changed,
	) {
	}//end __construct()
}//end class
