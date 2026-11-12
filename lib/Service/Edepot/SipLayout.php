<?php

/**
 * OpenRegister SIP Layout
 *
 * Where a content file sits inside a SIP package.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * The SIP layout: `objects/{uuid}/content/{original|rendition}/{name}`.
 *
 * The package entries, mets.xml and premis.xml all name a content file by
 * this one path.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipLayout {

	/**
	 * The sub-directory a file goes in: `rendition` for a rendition, else `original`.
	 *
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return string The sub-directory.
	 */
	public static function subDir(array $file): string {
		if ($file['isRendition'] === true) {
			return 'rendition';
		}

		return 'original';
	}//end subDir()

	/**
	 * The package path of an object's content file.
	 *
	 * @param string               $uuid The object uuid.
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return string The path.
	 */
	public static function contentPath(string $uuid, array $file): string {
		return "objects/{$uuid}/content/".self::subDir(file: $file)."/{$file['name']}";
	}//end contentPath()
}//end class
