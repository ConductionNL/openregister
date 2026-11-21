<?php

/**
 * OpenRegister preview size interface
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Kernel\Contracts\Core\PreviewDimensionsInterface;

/**
 * The width and height, in pixels, a file preview is asked for.
 *
 * The preview generator outside the Files component reads a size through
 * this interface, so it does not name the concrete value object.
 *
 * Its methods are the Core port PreviewDimensionsInterface, which the preview handler reads; this
 * name stays for the Files callers.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface PreviewSizeInterface extends PreviewDimensionsInterface {
}//end interface
