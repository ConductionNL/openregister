<?php

/**
 * A value object a tool function takes in place of its arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Mcp\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Tool;

/**
 * Marks the one parameter of a tool method as an operation object.
 *
 * The model's arguments bind to the operation's constructor, by name or by
 * position, exactly as they would bind to the method's own parameters; the
 * tool method then receives the built object.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface ToolOperation {
}//end interface
