<?php

/**
 * ObjectValidationVerdict: whether a sample record would be accepted, and on which properties not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published ObjectValidationVerdict, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\ObjectValidationVerdict, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
final class ObjectValidationVerdict extends \OCA\OpenRegister\Contract\ObjectValidationVerdict {
}//end class
