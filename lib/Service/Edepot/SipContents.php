<?php

/**
 * OpenRegister SIP Contents
 *
 * The entries one SIP package ships and the manifest that lists them.
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
 * Collects a package's entries (format-agnostic), in order.
 *
 * Each entry is an in-memory string or an on-disk file with its logical SIP
 * path, and each one is listed in `sip-manifest.json` with its size and
 * SHA-256. The MDTO documents are also kept per object, so mets.xml can list
 * them: a manifest that omits files it ships is not a manifest.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipContents {

	/**
	 * The entries, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * The manifest rows, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $manifest = [];

	/**
	 * The MDTO documents per object uuid.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $metadata = [];

	/**
	 * Add an in-memory document.
	 *
	 * @param string $path    The SIP path.
	 * @param string $content The bytes.
	 *
	 * @return array{path: string, size: int, checksum: string} Its manifest row.
	 */
	public function addDocument(string $path, string $content): array {
		$row = ['path' => $path, 'size' => strlen($content), 'checksum' => hash('sha256', $content)];
		$this->entries[]  = ['path' => $path, 'kind' => 'string', 'content' => $content];
		$this->manifest[] = $row;

		return $row;
	}//end addDocument()

	/**
	 * Add an MDTO document of an object, which mets.xml lists too.
	 *
	 * @param string|null $uuid    The object uuid.
	 * @param string      $path    The SIP path.
	 * @param string      $content The bytes.
	 *
	 * @return void
	 */
	public function addMetadataDocument(?string $uuid, string $path, string $content): void {
		$this->metadata[$uuid][] = $this->addDocument(path: $path, content: $content);
	}//end addMetadataDocument()

	/**
	 * Add an on-disk content file, listed with its recorded size and checksum.
	 *
	 * @param string               $path The SIP path.
	 * @param array<string, mixed> $file The file metadata (path, size, checksum).
	 *
	 * @return void
	 */
	public function addFile(string $path, array $file): void {
		$this->entries[]  = ['path' => $path, 'kind' => 'file', 'filePath' => $file['path']];
		$this->manifest[] = ['path' => $path, 'size' => $file['size'], 'checksum' => $file['checksum']];
	}//end addFile()

	/**
	 * The MDTO documents per object uuid.
	 *
	 * @return array<string, list<array<string, mixed>>> Uuid => manifest rows.
	 */
	public function metadataFiles(): array {
		return $this->metadata;
	}//end metadataFiles()

	/**
	 * Every entry, closed by `sip-manifest.json` (which lists all the others).
	 *
	 * @return list<array<string, mixed>> The entries.
	 */
	public function entries(): array {
		$manifestJson = json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		return array_merge($this->entries, [['path' => 'sip-manifest.json', 'kind' => 'string', 'content' => $manifestJson]]);
	}//end entries()
}//end class
