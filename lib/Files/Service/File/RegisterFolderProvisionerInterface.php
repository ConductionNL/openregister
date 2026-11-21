<?php

/**
 * RegisterFolderProvisionerInterface: what callers outside the Files component use of RegisterFolderProvisioner.
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

use OCA\OpenRegister\Kernel\Contracts\Files\RegisterFolderProvisioningInterface;

/**
 * Ensures every register has its files folder, as the repair step runs it.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-repair-step-provisions-folders-for-registers-imported-earlier-req-rfai-002
 */
interface RegisterFolderProvisionerInterface extends RegisterFolderProvisioningInterface {
}//end interface
