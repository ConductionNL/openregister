<?php

/**
 * OpenRegister BagIt Payload
 *
 * The payload of one BagIt bag and the tag files that describe it.
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
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use InvalidArgumentException;
use ZipArchive;

/**
 * Adds payload entries under `data/` and keeps the payload manifest and the
 * Payload-Oxum (bytes.count) as it goes (RFC 8493).
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 */
final class BagitPayload {

	/**
	 * The payload manifest lines (`sha256  data/path`).
	 *
	 * @var list<string>
	 */
	private array $manifestLines = [];

	/**
	 * The payload bytes.
	 *
	 * @var int
	 */
	private int $totalBytes = 0;

	/**
	 * Add one entry to the bag's payload.
	 *
	 * @param ZipArchive           $zip   The open bag archive.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When an on-disk payload file cannot be checksummed.
	 */
	public function add(ZipArchive $zip, array $entry): void {
		$dataPath = 'data/'.$entry['path'];
		if ($entry['kind'] === 'file') {
			$sha256 = $this->addFile(zip: $zip, entry: $entry);
		} else {
			$content = (string)$entry['content'];
			$sha256  = hash('sha256', $content);
			$zip->addFromString($dataPath, $content);
			$this->totalBytes += strlen($content);
		}

		$this->manifestLines[] = $sha256.'  '.$dataPath;
	}//end add()

	/**
	 * The tag files, in the order they are written: bagit.txt, bag-info.txt,
	 * the payload manifest, and the tag manifest over the three before it.
	 *
	 * @param string $transferId The transfer identifier (External-Identifier).
	 *
	 * @return array<string, string> File name => content.
	 */
	public function tagFiles(string $transferId): array {
		$tagFiles = [
			'bagit.txt' => "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n",
			'bag-info.txt' => $this->bagInfo(transferId: $transferId),
			'manifest-sha256.txt' => implode("\n", $this->manifestLines)."\n",
		];

		$tagManifestLines = [];
		foreach ($tagFiles as $name => $content) {
			$tagManifestLines[] = hash('sha256', $content).'  '.$name;
		}

		$tagFiles['tagmanifest-sha256.txt'] = implode("\n", $tagManifestLines)."\n";

		return $tagFiles;
	}//end tagFiles()

	/**
	 * The bag-info.txt: date, Payload-Oxum, source and the transfer.
	 *
	 * @param string $transferId The transfer identifier (External-Identifier).
	 *
	 * @return string The content.
	 */
	private function bagInfo(string $transferId): string {
		return 'Bagging-Date: '.gmdate('Y-m-d')."\n"
			."Payload-Oxum: {$this->totalBytes}.".count($this->manifestLines)."\n"
			."Source-Organization: OpenRegister\n"
			."External-Identifier: {$transferId}\n";
	}//end bagInfo()

	/**
	 * Add an on-disk payload file; one that cannot be checksummed refuses the bag.
	 *
	 * @param ZipArchive           $zip   The open bag archive.
	 * @param array<string, mixed> $entry The file entry: its path on disk (`filePath`) and in the payload (`path`).
	 *
	 * @return string The file's SHA-256.
	 *
	 * @throws InvalidArgumentException When the file cannot be checksummed.
	 */
	private function addFile(ZipArchive $zip, array $entry): string {
		$sourcePath = (string)$entry['filePath'];
		$dataPath   = 'data/'.$entry['path'];
		$sha256     = self::checksumOf(sourcePath: $sourcePath);

		$zip->addFile($sourcePath, $dataPath);
		$this->totalBytes += (int)filesize($sourcePath);

		return $sha256;
	}//end addFile()

	/**
	 * The SHA-256 of a payload file, refusing one that cannot be read.
	 *
	 * @param string $sourcePath The file on disk.
	 *
	 * @return string The checksum.
	 *
	 * @throws InvalidArgumentException When the file cannot be checksummed.
	 */
	private static function checksumOf(string $sourcePath): string {
		$sha256 = false;
		if (is_file($sourcePath) === true && is_readable($sourcePath) === true) {
			$sha256 = hash_file('sha256', $sourcePath);
		}

		if ($sha256 === false) {
			throw new InvalidArgumentException(
				"BagIt build failed: cannot checksum payload file '{$sourcePath}' — refusing an incomplete manifest"
			);
		}

		return $sha256;
	}//end checksumOf()
}//end class
