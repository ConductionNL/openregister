<?php
/**
 * DeclaredGroupsReconcileInterface: restoring the groups apps declare, and the rights they give them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Runs one sweep of the declared-group reconciler (Core).
 *
 * The configuration import (Configuration) asks for it at the end of every import,
 * so an upgrade restores product-owned groups' rights; the access area binds
 * its GroupReconciler here.
 */
interface DeclaredGroupsReconcileInterface {

	/**
	 * Run one reconciliation sweep. Never throws.
	 *
	 * @return array<string, mixed> What was declared, created and, when wired, which rights were restored.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function reconcile(): array;
}//end interface
