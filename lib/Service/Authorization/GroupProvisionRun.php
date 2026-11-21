<?php

/**
 * OpenRegister group provision run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * The state of one {@see GroupProvisioner::provision()} pass: the declaring app,
 * the group in hand, and what happened to each group so far. It never leaves the
 * provisioner that built it.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GroupProvisionRun {

	/**
	 * The declaring app id, for log attribution.
	 *
	 * @var string
	 */
	public string $declaredBy = '';

	/**
	 * The group being provisioned.
	 *
	 * @var string
	 */
	public string $group = '';

	/**
	 * The groups this pass created, in declaration order.
	 *
	 * @var string[]
	 */
	public array $created = [];

	/**
	 * The groups that already existed, in declaration order.
	 *
	 * @var string[]
	 */
	public array $existing = [];

	/**
	 * The groups that could not be checked or created, in declaration order.
	 *
	 * @var string[]
	 */
	public array $failed = [];
}//end class
