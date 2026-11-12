<?php

/**
 * AuditFlowAttributionInterface: the flow-run audit reads callers outside the component use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The hop-row action and the per-run audit read the flow-run endpoints and the hop audit use.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to AuditFlowAttribution.
 */
interface AuditFlowAttributionInterface {

	/**
	 * The action of a per-hop audit row. A hop row carries the run's attribution
	 * but records that the hop ran, not that it wrote anything.
	 *
	 * @var string
	 */
	public const ACTION_HOP = 'flow.hop';

	/**
	 * Every write attributed to one flow run, oldest first. Hop rows are left
	 * out: a run with auditing on must report the same writes as one without.
	 *
	 * Ordered by id rather than by `flow_step` so a run that visited the same
	 * node twice — a loop — reads in the order the writes actually happened
	 * rather than collapsing both visits into one position.
	 *
	 * @param string  $runUuid The flow run's uuid.
	 * @param integer $limit   Maximum rows to return.
	 *
	 * @return AuditTrail[] The rows this run caused.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function findByRun(string $runUuid, int $limit = 1000): array;
}//end interface
