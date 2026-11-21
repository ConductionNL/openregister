<?php
/**
 * EnvelopedDefinitionControllerInterface: a controller whose answers hold register or schema definitions.
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
 * Marks a Core controller that answers with register or schema definitions.
 *
 * The anonymous envelope (Platform) redacts such an answer to a caller without
 * a session as a definition rather than as object rows; it asks this marker so
 * it need not name the controllers.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
interface EnvelopedDefinitionControllerInterface {
}//end interface
