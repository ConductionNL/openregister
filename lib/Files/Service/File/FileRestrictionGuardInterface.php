<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FileRestrictionGuardInterface: what callers outside Files use of FileRestrictionGuard.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Kernel\Contracts\Core\FileReadRestrictionInterface;

/**
 * The one verdict on a restricted file: may the caller read it.
 *
 * Its methods are the Core port FileReadRestrictionInterface, which the file handlers ask; this
 * name stays for the callers in Files and Integrations.
 */
interface FileRestrictionGuardInterface extends FileReadRestrictionInterface {
}//end interface
