<?php

/**
 * ArchiveMemberReader: the bytes of the members an archive listing admitted
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\ITempManager;
use ZipArchive;

/**
 * Hands the bytes of each listed member of a zip, and of the zips nested in it, to a visitor.
 *
 * Reads only what ArchiveMemberEnumerator listed as LISTED: the listing has
 * already applied the bounds and refused the members that leave the archive
 * or are encrypted, so nothing here opens a member the enumerator would not.
 * A nested zip is handed over as a whole, so one that cannot be opened is
 * still scanned, and then copied to a temporary file and walked.
 *
 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-each-archive-member-is-scanned-on-its-own-req-ums-002
 */
class ArchiveMemberReader {

	/**
	 * Constructor.
	 *
	 * @param ITempManager $temp Temporary files for a nested zip.
	 */
	public function __construct(
		private readonly ITempManager $temp,
	) {
	}//end __construct()

	/**
	 * Visit every listed member with its bytes, nested archives and their members alike.
	 *
	 * @param string                         $path    The archive's local path.
	 * @param array<string, string>          $listed  Listed member path to MIME type.
	 * @param callable(string, string): void $visitor Called with the member path and its bytes.
	 *
	 * @return int The number of members visited.
	 *
	 * @spec openspec/changes/upload-malware-scan/specs/file-actions/spec.md#requirement-each-archive-member-is-scanned-on-its-own-req-ums-002
	 */
	public function each(string $path, array $listed, callable $visitor): int {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return 0;
		}

		$visited = $this->walk(zip: $zip, prefix: '', listed: $listed, visitor: $visitor);
		$zip->close();

		return $visited;
	}//end each()

	/**
	 * Visit the members of one open archive.
	 *
	 * @param ZipArchive                     $zip     The open archive.
	 * @param string                         $prefix  Its path inside the outer archive, with a trailing slash, or ''.
	 * @param array<string, string>          $listed  Listed member path to MIME type.
	 * @param callable(string, string): void $visitor The visitor.
	 *
	 * @return int The number of members visited.
	 */
	private function walk(ZipArchive $zip, string $prefix, array $listed, callable $visitor): int {
		$visited = 0;
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string)$zip->getNameIndex($index);
			$path = $prefix . $name;
			if (array_key_exists($path, $listed) === false) {
				continue;
			}

			$visitor($path, (string)$zip->getFromIndex($index));
			$visited++;
			if ($listed[$path] === 'application/zip') {
				$visited += $this->nested(zip: $zip, name: $name, path: $path, inner: ['listed' => $listed, 'visitor' => $visitor]);
			}
		}

		return $visited;
	}//end walk()

	/**
	 * Visit the members of a nested zip, read through a temporary copy.
	 *
	 * @param ZipArchive           $zip   The outer archive.
	 * @param string               $name  The member's name in it.
	 * @param string               $path  The member's listed path.
	 * @param array<string, mixed> $inner The listing and the visitor.
	 *
	 * @return int The number of members visited.
	 */
	private function nested(ZipArchive $zip, string $name, string $path, array $inner): int {
		$copy = $this->temp->getTemporaryFile('.zip');
		if ($copy === false || file_put_contents($copy, (string)$zip->getFromName($name)) === false) {
			return 0;
		}

		$archive = new ZipArchive();
		if ($archive->open($copy, ZipArchive::RDONLY) !== true) {
			return 0;
		}

		$visited = $this->walk(zip: $archive, prefix: $path . '/', listed: $inner['listed'], visitor: $inner['visitor']);
		$archive->close();

		return $visited;
	}//end nested()
}//end class
