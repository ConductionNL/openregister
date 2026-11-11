<?php

/**
 * OoxmlPackageFile
 *
 * The temp file and the ZipArchive behind one OOXML package read: writes the
 * file's bytes to a temp file for ZipArchive to open, opens it read-only as
 * a bounded OoxmlPackage, and closes both handles afterwards.
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
 * @spec openspec/specs/text-extraction-document/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCP\Files\File;
use RuntimeException;
use ZipArchive;

/**
 * Opens one file as an OOXML package and closes its handles.
 *
 * @spec openspec/specs/text-extraction-document/spec.md
 */
final class OoxmlPackageFile {

	/**
	 * The temp file holding the package bytes, once written.
	 *
	 * @var resource|false|null
	 */
	private mixed $tempFile = null;

	/**
	 * The opened archive, once open.
	 *
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * Constructor.
	 *
	 * @param int $maxPartBytes The most bytes read from any one XML part.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function __construct(
		private readonly int $maxPartBytes,
	) {
	}//end __construct()

	/**
	 * Write the file to a temp file and open it as a package.
	 *
	 * @param File $file The file.
	 *
	 * @return OoxmlPackage
	 *
	 * @throws RuntimeException When the bytes are not a zip package.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function open(File $file): OoxmlPackage {
		$this->tempFile = tmpfile();
		fwrite($this->tempFile, $file->getContent());

		$archive = new ZipArchive();
		$opened = $archive->open(stream_get_meta_data($this->tempFile)['uri'], ZipArchive::RDONLY);
		if ($opened !== true) {
			throw new RuntimeException('Not a zip package (ZipArchive code ' . (int)$opened . ')');
		}

		$this->zip = $archive;
		return new OoxmlPackage(zip: $archive, maxPartBytes: $this->maxPartBytes);
	}//end open()

	/**
	 * Close the archive and the temp file, whichever were opened.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md
	 */
	public function close(): void {
		$this->zip?->close();

		if (is_resource($this->tempFile) === true) {
			fclose($this->tempFile);
		}
	}//end close()
}//end class
