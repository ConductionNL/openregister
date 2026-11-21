<?php

/**
 * FileReaderInterface: reading an object's files through FileService.
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

use OCA\OpenRegister\Kernel\Contracts\Core\FileNodeLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\StoredFileReaderInterface;

/**
 * The file look-ups and projections callers make without changing a file.
 *
 * The methods are declared on the Core ports it extends.
 */
interface FileReaderInterface extends StoredFileReaderInterface, FileNodeLookupInterface {
}//end interface
