<?php

/**
 * OpenRegister SIP Archive Writer
 *
 * Writes a SIP package's entries as a plain zip or as a zipped BagIt bag.
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
use OCP\ITempManager;
use ZipArchive;

/**
 * Writes SIP archives into temporary files.
 *
 * A BagIt bag (RFC 8493) carries the payload under `data/`, a SHA-256
 * payload manifest, `bagit.txt`, `bag-info.txt` and a tag manifest over the
 * three tag files. A payload file that cannot be checksummed refuses the bag:
 * an incomplete manifest is never written.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 */
final class SipArchiveWriter {

	/**
	 * Constructor.
	 *
	 * @param ITempManager $tempManager Allocates the archive files.
	 */
	public function __construct(
		private readonly ITempManager $tempManager,
	) {
	}//end __construct()

	/**
	 * Write a package in its part's format.
	 *
	 * @param SipPart                    $part    The package.
	 * @param list<array<string, mixed>> $entries The entries.
	 *
	 * @return string The archive path.
	 *
	 * @throws InvalidArgumentException When the archive cannot be written.
	 */
	public function write(SipPart $part, array $entries): string {
		return match ($part->format) {
			'bagit' => $this->writeBagit(transferId: $part->transferId, entries: $entries, suffix: $part->suffix()),
			default => $this->writeZip(entries: $entries, suffix: $part->suffix()),
		};
	}//end write()

	/**
	 * Write the entries as a plain zip.
	 *
	 * @param list<array<string, mixed>> $entries The entries.
	 * @param string                     $suffix  The file-name suffix.
	 *
	 * @return string The archive path.
	 *
	 * @throws InvalidArgumentException When the archive cannot be written.
	 */
	private function writeZip(array $entries, string $suffix): string {
		$zipPath = $this->allocate(postFix: ".sip{$suffix}.zip", label: 'SIP');
		$zip     = self::open(path: $zipPath, label: 'ZIP');
		foreach ($entries as $entry) {
			if ($entry['kind'] === 'file') {
				$zip->addFile($entry['filePath'], $entry['path']);
				continue;
			}

			$zip->addFromString($entry['path'], $entry['content']);
		}

		$zip->close();

		return $zipPath;
	}//end writeZip()

	/**
	 * Write the entries as a zipped BagIt bag.
	 *
	 * @param string                     $transferId The transfer identifier (External-Identifier).
	 * @param list<array<string, mixed>> $entries    The entries.
	 * @param string                     $suffix     The file-name suffix.
	 *
	 * @return string The archive path.
	 *
	 * @throws InvalidArgumentException When the archive cannot be written or a payload file cannot be checksummed.
	 */
	private function writeBagit(string $transferId, array $entries, string $suffix): string {
		$zipPath = $this->allocate(postFix: ".bag{$suffix}.zip", label: 'BagIt');
		$zip     = self::open(path: $zipPath, label: 'BagIt');

		// Payload goes under data/; build the payload manifest as we add files.
		$payload = new BagitPayload();
		foreach ($entries as $entry) {
			$payload->add(zip: $zip, entry: $entry);
		}

		foreach ($payload->tagFiles(transferId: $transferId) as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();

		return $zipPath;
	}//end writeBagit()

	/**
	 * Allocate the archive file.
	 *
	 * The temp manager returns false when it cannot create the file. Passing
	 * that false straight to ZipArchive::open() would raise a TypeError about
	 * an argument rather than saying what actually went wrong.
	 *
	 * @param string $postFix The file-name postfix.
	 * @param string $label   `SIP` or `BagIt`, for the refusal.
	 *
	 * @return string The path.
	 *
	 * @throws InvalidArgumentException When no temporary file can be allocated.
	 */
	private function allocate(string $postFix, string $label): string {
		$zipPath = $this->tempManager->getTemporaryFile($postFix);
		if ($zipPath === false) {
			throw new InvalidArgumentException("Failed to allocate a temporary file for the {$label} archive");
		}

		return $zipPath;
	}//end allocate()

	/**
	 * Open a fresh zip at a path.
	 *
	 * @param string $path  The path.
	 * @param string $label `ZIP` or `BagIt`, for the refusal.
	 *
	 * @return ZipArchive The open archive.
	 *
	 * @throws InvalidArgumentException When the archive cannot be created.
	 */
	private static function open(string $path, string $label): ZipArchive {
		$zip    = new ZipArchive();
		$result = $zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		if ($result !== true) {
			throw new InvalidArgumentException("Failed to create {$label} archive: error code {$result}");
		}

		return $zip;
	}//end open()
}//end class
