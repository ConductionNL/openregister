<?php

/**
 * BulkBatchDefaults
 *
 * The register and schema a bulk save names for every row that names none.
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
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The published BulkBatchDefaults, under the name openregister used before it was published.
 *
 * @deprecated Use \OCA\OpenRegister\Contract\BulkBatchDefaults, the published contract (task 7.2).
 *             This name keeps every construction, instanceof and type hint working.
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class BulkBatchDefaults extends \OCA\OpenRegister\Contract\BulkBatchDefaults {
}//end class
