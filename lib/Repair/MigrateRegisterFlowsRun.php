<?php

/**
 * OpenRegister register-flows migration run
 *
 * The counts one run of the register-flows migration keeps while it walks the
 * flows register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * One run of the register-flows migration: what moved, what was already
 * there, what was not a flow and what failed.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
final class MigrateRegisterFlowsRun {

	/**
	 * Flows copied into the table.
	 *
	 * @var integer
	 */
	public int $moved = 0;

	/**
	 * Flows the table already held.
	 *
	 * @var integer
	 */
	public int $already = 0;

	/**
	 * Rows refused because they are not a flow definition.
	 *
	 * @var integer
	 */
	public int $notFlows = 0;

	/**
	 * Rows without a uuid, failed lookups and failed inserts.
	 *
	 * @var integer
	 */
	public int $failed = 0;
}//end class
