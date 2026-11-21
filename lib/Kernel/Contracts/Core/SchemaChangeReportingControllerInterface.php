<?php
/**
 * SchemaChangeReportingControllerInterface: a controller whose object reads announce a recent breaking schema change.
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
 * Marks a Core controller whose object read and list answers carry the schema change notice.
 *
 * The schema change header (Platform) adds its headers to the `index` and
 * `show` answers of such a controller; it asks this marker so it need not
 * name the controller.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
 */
interface SchemaChangeReportingControllerInterface {
}//end interface
