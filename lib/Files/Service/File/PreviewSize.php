<?php

/**
 * OpenRegister preview size
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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
 * The width and height, in pixels, of a file preview.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class PreviewSize {

	/**
	 * A preview size; both sides default to 256 pixels.
	 *
	 * @param int $width  The width in pixels.
	 * @param int $height The height in pixels.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly int $width = 256,
		public readonly int $height = 256,
	) {
	}//end __construct()

	/**
	 * Describes the size as width x height.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function label(): string {
		return $this->width . 'x' . $this->height;
	}//end label()
}//end class
