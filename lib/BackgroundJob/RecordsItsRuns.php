<?php

/**
 * RecordsItsRuns: the marker the operations console reads observability from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * A job whose execution is wrapped, so every run of it is a row.
 *
 * The console asks "which jobs am I actually watching" and it must answer from
 * the code, not from a list somebody keeps in step by hand: a hand-kept list is
 * exactly how a job goes missing from a monitor, and the missing job looks the
 * same as a job that never failed. Implementing this interface is what makes a
 * job observed, and `instanceof` is the whole test.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
interface RecordsItsRuns {
}//end interface
