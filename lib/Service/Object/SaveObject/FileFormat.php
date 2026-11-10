<?php

/**
 * The format of a parsed file: its MIME type and the extension it is stored under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * A file's MIME type with the extension it is stored under.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FileFormat {
	/**
	 * Constructor.
	 *
	 * @param string $mimeType  The MIME type.
	 * @param string $extension The extension.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function __construct(
		public readonly string $mimeType,
		public readonly string $extension,
	) {
	}//end __construct()
}//end class
