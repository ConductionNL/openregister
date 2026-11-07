<?php

/**
 * OpenRegister AppHost — Cron Schedule Evaluator
 *
 * Thin wrapper over the vendored `dragonmantank/cron-expression` library that
 * turns a cron expression into its next fire time. The OC `job` model has no
 * cron field, so the reconciler computes `nextRun` from the cron string each
 * tick and writes it onto the reconciled job (design D-2). Cron parsing is NEVER
 * hand-rolled — an unparseable expression is reported as invalid, not silently
 * accepted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
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

namespace OCA\OpenRegister\AppHost\Scheduling;

use Cron\CronExpression;
use Throwable;

/**
 * Answers whether a cron expression parses, via a vendored library.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
class CronScheduleEvaluator {
	/**
	 * Whether a cron expression is parseable by the vendored library.
	 *
	 * @param string $expression The cron expression.
	 *
	 * @return bool True when the expression parses; false otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function isValid(string $expression): bool {
		return CronExpression::isValidExpression($expression);
	}//end isValid()
}//end class
