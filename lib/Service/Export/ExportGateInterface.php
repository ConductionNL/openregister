<?php

/**
 * ExportGateInterface: the export permission gate as callers outside the component use it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Kernel\Contracts\Core\ExportGuardInterface;

/**
 * Answers whether an export may run, as the refusal response to return or null.
 *
 * The method is declared on the Core contract this interface extends.
 * Bound, with that contract, to ExportGate.
 */
interface ExportGateInterface extends ExportGuardInterface {
}//end interface
