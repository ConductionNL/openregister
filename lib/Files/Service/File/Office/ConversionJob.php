<?php

/**
 * ConversionJob — one conversion in the office work folder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Office
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Office;

use OCP\Files\Folder;

/**
 * Value object: the work folder, the random file name and the target extension of one conversion.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
final class ConversionJob {
	/**
	 * Constructor.
	 *
	 * @param Folder $folder The work folder.
	 * @param string $name   The random base name of the source and the result.
	 * @param string $target The target extension.
	 */
	public function __construct(
		public readonly Folder $folder,
		public readonly string $name,
		public readonly string $target,
	) {
	}//end __construct()

	/**
	 * Where the conversion writes its result: `<work folder>/<name>.<target>`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function targetPath(): string {
		return $this->folder->getPath() . '/' . $this->name . '.' . $this->target;
	}//end targetPath()
}//end class
