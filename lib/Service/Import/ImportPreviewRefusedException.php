<?php

/**
 * A previewed import was refused before anything was written.
 *
 * Thrown by the commit, never by the preview: a preview reports refusals per
 * row, while a commit that cannot honestly run at all refuses as a whole. The
 * two cases it carries are a preview that is not in a committable state and a
 * source file that is not the one the decisions describe (D-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use RuntimeException;

/**
 * Thrown when a commit is refused with nothing written.
 */
class ImportPreviewRefusedException extends RuntimeException {
}//end class
