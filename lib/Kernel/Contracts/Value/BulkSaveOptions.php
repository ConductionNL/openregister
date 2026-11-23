<?php

/**
 * BulkSaveOptions
 *
 * The per-call switches of one bulk save: its default register and schema, and whether
 * it validates, dispatches events and writes audit rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published BulkSaveOptions, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\BulkSaveOptions, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveOptions extends \OCA\OpenRegister\Contract\BulkSaveOptions {
}//end class
