<?php

/**
 * OpenRegister AppHost — Observability Validation Exception
 *
 * Thrown when an `observability` manifest block contains an unknown check
 * type, source kind, filter operator, or otherwise malformed descriptor.
 * Callers convert it into a manifest diagnostic and fall back to defaults so
 * a bad descriptor never produces a runtime 500.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\AppHost\Observability
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

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Raised on a malformed observability descriptor (manifest-validation time).
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
class ObservabilityValidationException extends \InvalidArgumentException {
}//end class
