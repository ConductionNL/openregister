<?php

/**
 * An upload part size outside 1 MiB to 100 MiB.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A submitted `upload.partSizeBytes` that is not a whole number between 1048576 and 104857600.
 *
 * Callers translate this to HTTP 400: the value is the caller's to correct.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-3
 */
class UploadPartSizeOutOfRangeException extends InvalidArgumentException {
}//end class
