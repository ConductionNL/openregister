<?php
/**
 * DependentValueCheckInterface: the dependent-value rule a save runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Service\Object\ObjectSaveCheck;

/**
 * The save check that refuses a value its schema declares dependent on another value.
 *
 * The object save path (Core) runs it, in its registration slot, when an object is
 * validated without saving; the dependent-value rule (Processes) implements it and
 * binds itself in its own registrar. The check itself is ObjectSaveCheck::check().
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
interface DependentValueCheckInterface extends ObjectSaveCheck {
}//end interface
