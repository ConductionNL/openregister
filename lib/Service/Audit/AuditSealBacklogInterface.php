<?php

/**
 * AuditSealBacklogInterface: the bounded, resumable seal-backlog pass the occ command and the background job run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * Drain the backlog of unsealed audit rows, window by window, within caps.
 */
interface AuditSealBacklogInterface {

	/**
	 * Seal unsealed rows in ascending id order until drained or a cap is reached.
	 *
	 * `stoppedBy` is `drained`, `row-cap`, `time-cap` or `lock` (a window was
	 * abandoned because the seal lock was busy; the next run continues).
	 * `unsealedBehindChain` counts unsealed rows that lie before the newest
	 * chain link: those cannot be sealed in place without forking the chain and
	 * need `occ openregister:rechain-audit-trail`.
	 *
	 * @param int           $maxRows    Most rows to seal in this pass (at least 1).
	 * @param int           $maxSeconds Most wall-clock seconds to spend, 0 for no limit.
	 * @param callable|null $onWindow   Called after each sealed window with the counters so far.
	 *
	 * @return array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string, unsealedBehindChain: int}
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function drain(int $maxRows, int $maxSeconds = 0, ?callable $onWindow = null): array;
}//end interface
