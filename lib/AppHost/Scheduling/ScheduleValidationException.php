<?php

/**
 * OpenRegister AppHost — Schedule Validation Exception
 *
 * Thrown when a single manifest `schedules[]` entry is structurally invalid
 * (missing id/action, or not carrying exactly one of interval/cron). Collected
 * as a diagnostic by {@see ScheduleManifest}; never bubbles out of the
 * reconciler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
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

use Exception;

/**
 * Raised for a structurally-invalid single schedule entry.
 *
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 */
class ScheduleValidationException extends Exception
{
}//end class
