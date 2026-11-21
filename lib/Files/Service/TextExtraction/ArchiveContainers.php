<?php

/**
 * OpenRegister ArchiveContainers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use ZipArchive;

/**
 * The archive being read and the zips nested in it, opened from temporary
 * copies, so a member is read by the path the enumerator listed it under.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */
final class ArchiveContainers {

	/**
	 * The nested archives by their listed path.
	 *
	 * @var array<string, ZipArchive>
	 */
	private array $nested = [];

	/**
	 * Temporary copies to remove on close.
	 *
	 * @var array<int, string>
	 */
	private array $copies = [];

	/**
	 * Constructor.
	 *
	 * @param ZipArchive $outer The archive itself, open.
	 */
	public function __construct(
		private readonly ZipArchive $outer,
	) {
	}//end __construct()

	/**
	 * Open a zip member of the outer archive, so its members can be read.
	 *
	 * @param string $path The nested archive's listed path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function openNested(string $path): void {
		$source = $this->outer->getStream($path);
		$copy   = tempnam(sys_get_temp_dir(), 'or-archive-');
		if ($source === false || $copy === false) {
			return;
		}

		$this->copies[] = $copy;
		$target         = fopen($copy, 'w');
		if ($target === false) {
			return;
		}

		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);

		$inner = new ZipArchive();
		if ($inner->open($copy, ZipArchive::RDONLY) === true) {
			$this->nested[$path] = $inner;
		}
	}//end openNested()

	/**
	 * The bytes of a listed member.
	 *
	 * @param string $path The member's listed path.
	 *
	 * @return string|null The bytes, or null when the member cannot be read.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function read(string $path): ?string {
		if ($this->outer->locateName($path) !== false) {
			return $this->bytes(zip: $this->outer, name: $path);
		}

		foreach ($this->nested as $prefix => $inner) {
			if (str_starts_with($path, $prefix.'/') === true) {
				return $this->bytes(zip: $inner, name: substr($path, (strlen($prefix) + 1)));
			}
		}

		return null;
	}//end read()

	/**
	 * Close the nested archives and remove their copies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public function close(): void {
		foreach ($this->nested as $inner) {
			$inner->close();
		}

		$this->outer->close();
		foreach ($this->copies as $copy) {
			if (file_exists($copy) === true) {
				// NOSEMGREP: php.lang.security.unlink-use.unlink-use -- The path is a tempnam() copy this class made.
				unlink($copy);
			}
		}

		$this->nested = [];
		$this->copies = [];
	}//end close()

	/**
	 * A member's bytes from one archive.
	 *
	 * @param ZipArchive $zip  The archive.
	 * @param string     $name The member name in it.
	 *
	 * @return string|null
	 */
	private function bytes(ZipArchive $zip, string $name): ?string {
		$bytes = $zip->getFromName($name);
		if ($bytes === false) {
			return null;
		}

		return $bytes;
	}//end bytes()
}//end class
