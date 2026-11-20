<?php
/**
 * RetainingActivityInterface: what Core reads of a processing activity.
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

/**
 * A processing activity, as the retention clocks (Core) read it: which one it
 * is and how long it keeps the data.
 *
 * Implemented by the processing-activity entity (Compliance), so the deletion
 * services read the AVG clock without naming the entity. The column accessors
 * are served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method string|null getUuid()
 * @method string|null getRetentionPeriod()
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface RetainingActivityInterface {
}//end interface
