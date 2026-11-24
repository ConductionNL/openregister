<?php

/**
 * WholeSetExportActionInterface: the built-in bulk action that extracts a whole set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Processes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Processes;

use OCA\OpenRegister\BulkAction\BulkActionInterface;

/**
 * The whole-set extract, as the built-in bulk actions list it.
 *
 * Processes registers it among its built-in actions, in its place; Configuration,
 * which keeps the export profiles, implements it and binds it in its own wiring.
 */
interface WholeSetExportActionInterface extends BulkActionInterface {
}//end interface
