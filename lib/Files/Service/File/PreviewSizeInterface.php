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

/**
 * The width and height, in pixels, a file preview is asked for.
 *
 * The preview generator outside the Files component reads a size through
 * this interface, so it does not name the concrete value object.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface PreviewSizeInterface {

	/**
	 * The width in pixels.
	 *
	 * @return int The width.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function width(): int;

	/**
	 * The height in pixels.
	 *
	 * @return int The height.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function height(): int;

	/**
	 * Describes the size as width x height.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function label(): string;
}//end interface
