<?php

/**
 * ShareUrlBuilderInterface: what callers outside the Files component use of ShareUrlBuilder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Kernel\Contracts\Core\ShareLinkUrlInterface;

/**
 * Builds the public link of a share, as FileService formats it.
 *
 * Its method is the Core port ShareLinkUrlInterface, which the file service asks; this name stays
 * for the callers in Files.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
 */
interface ShareUrlBuilderInterface extends ShareLinkUrlInterface {
}//end interface
