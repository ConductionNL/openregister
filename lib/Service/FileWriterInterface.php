<?php

/**
 * FileWriterInterface: adding, overwriting and deleting an object's files through FileService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\StoredFileWriterInterface;

/**
 * The file writes callers make on an object's folder.
 *
 * The methods are declared on the Core port it extends.
 */
interface FileWriterInterface extends StoredFileWriterInterface {
}//end interface
