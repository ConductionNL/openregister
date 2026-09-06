<?php

/**
 * A case-plan definition or verb payload was refused at the boundary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A case-plan definition or verb payload was refused at the boundary.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
 */
class CaseValidationException extends InvalidArgumentException {
}//end class
