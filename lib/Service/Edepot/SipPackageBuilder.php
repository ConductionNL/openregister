<?php

/**
 * OpenRegister SIP Package Builder
 *
 * Assembles SIP (Submission Information Package) archives conforming to the
 * OAIS reference model (ISO 14721) for e-Depot transfer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Builder for SIP (Submission Information Package) archives.
 *
 * Creates ZIP archives containing per-object directories with MDTO XML metadata,
 * object data snapshots, associated content files, and package-level METS/PREMIS
 * structural and preservation metadata. Supports a ZIP default and an RFC 8493
 * BagIt serializer branch.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SipPackageBuilder {

	/**
	 * Default maximum package size in bytes (2 GB).
	 */
	public const DEFAULT_MAX_PACKAGE_SIZE = 2147483648;

	/**
	 * METS namespace URI.
	 */
	private const METS_NAMESPACE = 'http://www.loc.gov/METS/';

	/**
	 * PREMIS namespace URI.
	 */
	private const PREMIS_NAMESPACE = 'info:lc/xmlns/premis-v2';

	/**
	 * Constructor.
	 *
	 * @param MdtoXmlGenerator $mdtoGenerator The MDTO XML generator.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ITempManager $tempManager Temporary file manager.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly MdtoXmlGenerator $mdtoGenerator,
		private readonly IAppConfig $appConfig,
		private readonly ITempManager $tempManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build SIP package(s) for a list of objects.
	 *
	 * Returns an array of file paths to generated ZIP archives. Multiple archives
	 * are created when the combined file size exceeds the maximum package size.
	 *
	 * @param string $transferId The transfer list UUID.
	 * @param array $objectsWithFiles Objects and their file metadata (object, files[]).
	 * @param int $maxPackageSize Maximum package size in bytes.
	 * @param string $format Output format: `zip` (default) or `bagit` (RFC 8493).
	 *
	 * @return array<int, string> Array of file paths to generated SIP archives.
	 *
	 * @throws InvalidArgumentException If no objects are provided or the format is unknown.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 *   (Requirement: BagIt is a serialization option on the existing builder)
	 */
	public function build(string $transferId, array $objectsWithFiles, int $maxPackageSize = 0, string $format = 'zip'): array {
		if (empty($objectsWithFiles) === true) {
			throw new InvalidArgumentException('No objects provided for SIP package');
		}

		if (in_array($format, ['zip', 'bagit'], true) === false) {
			throw new InvalidArgumentException("Unknown SIP output format '{$format}' (expected 'zip' or 'bagit')");
		}

		if ($maxPackageSize <= 0) {
			$maxPackageSize = (int)$this->appConfig->getValueString(
				'openregister',
				'edepot_max_package_size',
				(string)self::DEFAULT_MAX_PACKAGE_SIZE
			);
		}

		$batches = $this->splitIntoBatches(objectsWithFiles: $objectsWithFiles, maxSize: $maxPackageSize);
		$totalBatches = count($batches);
		$sipFiles = [];

		foreach ($batches as $index => $batch) {
			$sipFiles[] = $this->buildSinglePackage(
				transferId: $transferId,
				objectsWithFiles: $batch,
				sequenceNumber: ($index + 1),
				totalPackages: $totalBatches,
				format: $format
			);
		}

		return $sipFiles;
	}//end build()

	/**
	 * Split objects into batches based on maximum package size.
	 *
	 * @param array $objectsWithFiles Objects and their file metadata.
	 * @param int $maxSize Maximum package size in bytes.
	 *
	 * @return array<int, array> Array of batches.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function splitIntoBatches(array $objectsWithFiles, int $maxSize): array {
		$batches = [];
		$currentBatch = [];
		$currentSize = 0;

		foreach ($objectsWithFiles as $item) {
			$itemSize = 0;
			foreach ($item['files'] as $file) {
				$itemSize += $file['size'];
			}

			if (empty($currentBatch) === false && ($currentSize + $itemSize) > $maxSize) {
				$batches[] = $currentBatch;
				$currentBatch = [];
				$currentSize = 0;
			}

			$currentBatch[] = $item;
			$currentSize += $itemSize;
		}

		if (empty($currentBatch) === false) {
			$batches[] = $currentBatch;
		}

		return $batches;
	}//end splitIntoBatches()

	/**
	 * The SIP entries for an object's content files, each with its own MDTO document.
	 *
	 * MDTO gives every file its own document, placed next to the file and
	 * named `<bestandsnaam>.bestand.MDTO.xml` (MDTO SIP specification,
	 * "Naamgeving"). A file missing from disk is skipped, and so is its
	 * document, because a document describing a file the package lacks would
	 * be false.
	 *
	 * @param string $objectDir The object's directory in the SIP.
	 * @param ObjectEntity $object The object the files belong to.
	 * @param array $files The object's file metadata.
	 *
	 * @return array{entries: list<array<string, mixed>>, manifest: list<array<string, mixed>>, metadata: list<array<string, mixed>>} The rows to add.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function contentFileEntries(string $objectDir, ObjectEntity $object, array $files): array {
		$entries = [];
		$manifest = [];
		$metadata = [];

		foreach ($files as $file) {
			if (file_exists($file['path']) === false) {
				continue;
			}

			$subDir = 'original';
			if ($file['isRendition'] === true) {
				$subDir = 'rendition';
			}

			$filePath = "{$objectDir}/content/{$subDir}/{$file['name']}";
			$entries[] = ['path' => $filePath, 'kind' => 'file', 'filePath' => $file['path']];
			$manifest[] = ['path' => $filePath, 'size' => $file['size'], 'checksum' => $file['checksum']];

			$bestandXml = $this->mdtoGenerator->generateBestand($object, $file);
			$bestandPath = $filePath . MdtoBestandGenerator::SIDECAR_SUFFIX;
			$entries[] = ['path' => $bestandPath, 'kind' => 'string', 'content' => $bestandXml];
			$manifest[] = $this->createManifestEntry(path: $bestandPath, content: $bestandXml);
			$metadata[] = $this->createManifestEntry(path: $bestandPath, content: $bestandXml);
		}

		return ['entries' => $entries, 'manifest' => $manifest, 'metadata' => $metadata];
	}//end contentFileEntries()

	/**
	 * Build a single SIP package ZIP archive.
	 *
	 * @param string $transferId The transfer list UUID.
	 * @param array $objectsWithFiles Objects in this batch.
	 * @param int $sequenceNumber This package's position in the sequence.
	 * @param int $totalPackages Total number of packages.
	 * @param string $format Output format: `zip` or `bagit`.
	 *
	 * @return string Path to the generated archive.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 *   (Requirement: BagIt is a serialization option on the existing builder)
	 */
	private function buildSinglePackage(
		string $transferId,
		array $objectsWithFiles,
		int $sequenceNumber,
		int $totalPackages,
		string $format = 'zip',
	): string {
		// Collect the SIP content once (format-agnostic): each entry is either
		// an in-memory string or an on-disk file, with its logical SIP path.
		$entries = [];
		$manifest = [];
		// The MDTO documents the package ships, per object, so mets.xml can
		// list them. A manifest that omits files it ships is not a manifest.
		$metadataFiles = [];

		foreach ($objectsWithFiles as $item) {
			$object = $item['object'];
			$files = $item['files'];
			$uuid = $object->getUuid();
			$objectDir = "objects/{$uuid}";

			// The retention period is required to TRANSFER, though MDTO allows
			// the element to be absent. Asking here keeps the export endpoint
			// able to serialise a record whose period is unknown.
			$this->mdtoGenerator->assertTransferPreconditions($object);

			$mdtoXml = $this->mdtoGenerator->generate($object, $files);
			$entries[] = ['path' => "{$objectDir}/mdto.xml", 'kind' => 'string', 'content' => $mdtoXml];
			$manifest[] = $this->createManifestEntry(path: "{$objectDir}/mdto.xml", content: $mdtoXml);
			$metadataFiles[$uuid][] = $this->createManifestEntry(path: "{$objectDir}/mdto.xml", content: $mdtoXml);

			$metadataJson = json_encode($object->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			$entries[] = ['path' => "{$objectDir}/metadata.json", 'kind' => 'string', 'content' => $metadataJson];
			$manifest[] = $this->createManifestEntry(path: "{$objectDir}/metadata.json", content: $metadataJson);

			$fileEntries = $this->contentFileEntries(objectDir: $objectDir, object: $object, files: $files);
			$entries = array_merge($entries, $fileEntries['entries']);
			$manifest = array_merge($manifest, $fileEntries['manifest']);
			$metadataFiles[$uuid] = array_merge($metadataFiles[$uuid], $fileEntries['metadata']);
		}//end foreach

		$metsXml = $this->generateMetsXml(
			transferId: $transferId,
			objectsWithFiles: $objectsWithFiles,
			metadataFiles: $metadataFiles
		);
		$entries[] = ['path' => 'mets.xml', 'kind' => 'string', 'content' => $metsXml];
		$manifest[] = $this->createManifestEntry(path: 'mets.xml', content: $metsXml);

		$premisXml = $this->generatePremisXml(transferId: $transferId, objectsWithFiles: $objectsWithFiles);
		$entries[] = ['path' => 'premis.xml', 'kind' => 'string', 'content' => $premisXml];
		$manifest[] = $this->createManifestEntry(path: 'premis.xml', content: $premisXml);

		if ($totalPackages > 1) {
			$sequenceJson = json_encode(
				[
					'transferId' => $transferId,
					'sequenceNumber' => $sequenceNumber,
					'totalPackages' => $totalPackages,
				],
				JSON_PRETTY_PRINT
			);
			$entries[] = ['path' => 'sip-sequence.json', 'kind' => 'string', 'content' => $sequenceJson];
			$manifest[] = $this->createManifestEntry(path: 'sip-sequence.json', content: $sequenceJson);
		}

		$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$entries[] = ['path' => 'sip-manifest.json', 'kind' => 'string', 'content' => $manifestJson];

		$suffix = '';
		if ($totalPackages > 1) {
			$suffix = "-part{$sequenceNumber}";
		}

		$archivePath = match ($format) {
			'bagit' => $this->writeBagitArchive(transferId: $transferId, entries: $entries, suffix: $suffix),
			default => $this->writeZipArchive(entries: $entries, suffix: $suffix),
		};

		$this->logger->info(
			message: '[SipPackageBuilder] Built SIP package',
			context: [
				'transferId' => $transferId,
				'sequence' => "{$sequenceNumber}/{$totalPackages}",
				'objects' => count($objectsWithFiles),
				'format' => $format,
				'path' => $archivePath,
			]
		);

		return $archivePath;
	}//end buildSinglePackage()

	/**
	 * Create a manifest entry for a content string.
	 *
	 * @param string $path The relative path in the ZIP.
	 * @param string $content The file content.
	 *
	 * @return array{path: string, size: int, checksum: string} The manifest entry.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function createManifestEntry(string $path, string $content): array {
		return [
			'path' => $path,
			'size' => strlen($content),
			'checksum' => hash('sha256', $content),
		];
	}//end createManifestEntry()

	/**
	 * Write the collected content entries as a flat ZIP SIP (the historical
	 * layout — content at the archive root, unchanged by this change).
	 *
	 * @param array<int, array<string, mixed>> $entries Content entries (string|file).
	 * @param string $suffix Filename suffix for multi-part packages.
	 *
	 * @return string Path to the generated ZIP file.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 *   (Scenario: Zip is the unchanged default)
	 */
	private function writeZipArchive(array $entries, string $suffix): string {
		$zipPath = $this->tempManager->getTemporaryFile(".sip{$suffix}.zip");
		if ($zipPath === false) {
			throw new InvalidArgumentException('Failed to allocate a temporary file for the SIP archive');
		}

		$zip = new ZipArchive();
		$result = $zip->open($zipPath, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		if ($result !== true) {
			throw new InvalidArgumentException("Failed to create ZIP archive: error code {$result}");
		}

		foreach ($entries as $entry) {
			if ($entry['kind'] === 'file') {
				$zip->addFile($entry['filePath'], $entry['path']);
				continue;
			}

			$zip->addFromString($entry['path'], $entry['content']);
		}

		$zip->close();

		return $zipPath;
	}//end writeZipArchive()

	/**
	 * Write the collected content entries as an RFC 8493 BagIt bag (v1.0):
	 * payload under `data/`, a complete `manifest-sha256.txt`, and a
	 * `tagmanifest-sha256.txt` over the tag files. An unchecksummable payload
	 * file fails the build — an incomplete manifest is never shipped.
	 *
	 * @param string $transferId The transfer uuid (bag External-Identifier).
	 * @param array<int, array<string, mixed>> $entries Content entries (string|file).
	 * @param string $suffix Filename suffix for multi-part packages.
	 *
	 * @return string Path to the generated bag ZIP file.
	 *
	 * @throws InvalidArgumentException When a payload file cannot be checksummed.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 *   (Requirement: BagIt is a serialization option on the existing builder)
	 */
	private function writeBagitArchive(string $transferId, array $entries, string $suffix): string {
		$zipPath = $this->tempManager->getTemporaryFile(".bag{$suffix}.zip");
		if ($zipPath === false) {
			// The temp manager returns false when it cannot create the file.
			// This file declares strict_types, so passing that false straight to
			// ZipArchive::open() raises a TypeError about an argument rather than
			// saying what actually went wrong — and the method promises a string.
			throw new InvalidArgumentException('Failed to allocate a temporary file for the BagIt archive');
		}

		$zip = new ZipArchive();
		$result = $zip->open($zipPath, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		if ($result !== true) {
			throw new InvalidArgumentException("Failed to create BagIt archive: error code {$result}");
		}

		// Payload goes under data/; build the payload manifest as we add files.
		$manifestLines = [];
		$totalBytes = 0;
		$fileCount = 0;

		foreach ($entries as $entry) {
			$dataPath = 'data/' . $entry['path'];

			if ($entry['kind'] === 'file') {
				$sourcePath = (string)$entry['filePath'];
				$sha256 = false;
				if (is_file($sourcePath) === true && is_readable($sourcePath) === true) {
					$sha256 = hash_file('sha256', $sourcePath);
				}

				if ($sha256 === false) {
					throw new InvalidArgumentException(
						"BagIt build failed: cannot checksum payload file '{$sourcePath}' — refusing an incomplete manifest"
					);
				}

				$zip->addFile($sourcePath, $dataPath);
				$size = (int)filesize($sourcePath);
				$totalBytes += $size;
			} else {
				$content = (string)$entry['content'];
				$sha256 = hash('sha256', $content);
				$zip->addFromString($dataPath, $content);
				$totalBytes += strlen($content);
			}//end if

			$manifestLines[] = $sha256 . '  ' . $dataPath;
			$fileCount++;
		}//end foreach

		// Tag files (RFC 8493).
		$bagitTxt = "BagIt-Version: 1.0\nTag-File-Character-Encoding: UTF-8\n";

		$baggingDate = gmdate('Y-m-d');
		$bagInfoTxt = "Bagging-Date: {$baggingDate}\n"
			. "Payload-Oxum: {$totalBytes}.{$fileCount}\n"
			. "Source-Organization: OpenRegister\n"
			. "External-Identifier: {$transferId}\n";

		$manifestTxt = implode("\n", $manifestLines) . "\n";

		// Tag manifest covers the three tag files above.
		$tagManifestLines = [
			hash('sha256', $bagitTxt) . '  bagit.txt',
			hash('sha256', $bagInfoTxt) . '  bag-info.txt',
			hash('sha256', $manifestTxt) . '  manifest-sha256.txt',
		];
		$tagManifestTxt = implode("\n", $tagManifestLines) . "\n";

		$zip->addFromString('bagit.txt', $bagitTxt);
		$zip->addFromString('bag-info.txt', $bagInfoTxt);
		$zip->addFromString('manifest-sha256.txt', $manifestTxt);
		$zip->addFromString('tagmanifest-sha256.txt', $tagManifestTxt);

		$zip->close();

		return $zipPath;
	}//end writeBagitArchive()

	/**
	 * Generate METS XML structural metadata.
	 *
	 * @param string $transferId The transfer list UUID.
	 * @param array<int,array<string,mixed>> $objectsWithFiles Objects and their file metadata.
	 * @param array<string,array<int,array<string,mixed>>> $metadataFiles The MDTO documents the package ships, keyed by object uuid.
	 *
	 * @return string The METS XML string.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function generateMetsXml(string $transferId, array $objectsWithFiles, array $metadataFiles = []): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$mets = $dom->createElementNS(self::METS_NAMESPACE, 'mets:mets');
		$mets->setAttribute('OBJID', $transferId);
		$mets->setAttribute('TYPE', 'SIP');
		$dom->appendChild($mets);

		$fileSec = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fileSec');
		$mets->appendChild($fileSec);

		$originalGrp = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fileGrp');
		$originalGrp->setAttribute('USE', 'ORIGINAL');
		$fileSec->appendChild($originalGrp);

		$renditionGrp = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fileGrp');
		$renditionGrp->setAttribute('USE', 'RENDITION');
		$fileSec->appendChild($renditionGrp);

		// The MDTO documents travel in the package too: the object's own and
		// one per file. Leaving them out of fileSec made mets.xml describe
		// less than the package contains.
		$metadataGrp = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fileGrp');
		$metadataGrp->setAttribute('USE', 'METADATA');
		$fileSec->appendChild($metadataGrp);

		$structMap = $dom->createElementNS(self::METS_NAMESPACE, 'mets:structMap');
		$structMap->setAttribute('TYPE', 'physical');
		$mets->appendChild($structMap);

		$rootDiv = $dom->createElementNS(self::METS_NAMESPACE, 'mets:div');
		$rootDiv->setAttribute('LABEL', 'SIP-' . $transferId);
		$structMap->appendChild($rootDiv);

		$fileCounter = 1;
		foreach ($objectsWithFiles as $item) {
			$object = $item['object'];
			$files = $item['files'];
			$uuid = $object->getUuid();

			$objectDiv = $dom->createElementNS(self::METS_NAMESPACE, 'mets:div');
			$objectDiv->setAttribute('LABEL', $uuid);
			$objectDiv->setAttribute('TYPE', 'object');
			$rootDiv->appendChild($objectDiv);

			$fileCounter = $this->appendContentFiles(
				dom: $dom,
				groups: ['original' => $originalGrp, 'rendition' => $renditionGrp],
				objectDiv: $objectDiv,
				uuid: (string)$uuid,
				files: $files,
				fileCounter: $fileCounter
			);

			$fileCounter = $this->appendMetadataFiles(
				dom: $dom,
				group: $metadataGrp,
				objectDiv: $objectDiv,
				rows: ($metadataFiles[$uuid] ?? []),
				fileCounter: $fileCounter
			);
		}//end foreach

		return $dom->saveXML();
	}//end generateMetsXml()

	/**
	 * List an object's content files in the METS file section.
	 *
	 * @param DOMDocument $dom The METS document.
	 * @param array<string,DOMElement> $groups The ORIGINAL and RENDITION file groups.
	 * @param DOMElement $objectDiv The object's div, which points at them.
	 * @param string $uuid The object uuid, for the file paths.
	 * @param array $files The object's file metadata.
	 * @param int $fileCounter The running METS file id counter.
	 *
	 * @return int The counter after these files.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function appendContentFiles(
		DOMDocument $dom,
		array $groups,
		DOMElement $objectDiv,
		string $uuid,
		array $files,
		int $fileCounter,
	): int {
		foreach ($files as $file) {
			$fileId = 'FILE-' . $fileCounter;
			$fileCounter++;

			$subDir = 'original';
			if ($file['isRendition'] === true) {
				$subDir = 'rendition';
			}

			$element = $dom->createElementNS(self::METS_NAMESPACE, 'mets:file');
			$element->setAttribute('ID', $fileId);
			$element->setAttribute('SIZE', (string)$file['size']);
			$element->setAttribute('MIMETYPE', $file['format']);
			$element->setAttribute('CHECKSUM', $file['checksum']);
			$element->setAttribute('CHECKSUMTYPE', 'SHA-256');

			$locat = $dom->createElementNS(self::METS_NAMESPACE, 'mets:FLocat');
			$locat->setAttribute('LOCTYPE', 'URL');
			$locat->setAttributeNS(
				'http://www.w3.org/1999/xlink',
				'xlink:href',
				"objects/{$uuid}/content/{$subDir}/{$file['name']}"
			);
			$element->appendChild($locat);
			$groups[$subDir]->appendChild($element);

			$pointer = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fptr');
			$pointer->setAttribute('FILEID', $fileId);
			$objectDiv->appendChild($pointer);
		}

		return $fileCounter;
	}//end appendContentFiles()

	/**
	 * List an object's MDTO documents in the METS file section.
	 *
	 * @param DOMDocument $dom The METS document.
	 * @param DOMElement $group The METADATA file group.
	 * @param DOMElement $objectDiv The object's div, which points at them.
	 * @param array $rows The manifest rows for this object's MDTO documents.
	 * @param int $fileCounter The running METS file id counter.
	 *
	 * @return int The counter after these files.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	private function appendMetadataFiles(
		DOMDocument $dom,
		DOMElement $group,
		DOMElement $objectDiv,
		array $rows,
		int $fileCounter,
	): int {
		foreach ($rows as $row) {
			$metadataId = 'MD-' . $fileCounter;
			$fileCounter++;

			$element = $dom->createElementNS(self::METS_NAMESPACE, 'mets:file');
			$element->setAttribute('ID', $metadataId);
			$element->setAttribute('SIZE', (string)$row['size']);
			$element->setAttribute('MIMETYPE', 'application/xml');
			$element->setAttribute('CHECKSUM', $row['checksum']);
			$element->setAttribute('CHECKSUMTYPE', 'SHA-256');

			$locat = $dom->createElementNS(self::METS_NAMESPACE, 'mets:FLocat');
			$locat->setAttribute('LOCTYPE', 'URL');
			$locat->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', $row['path']);
			$element->appendChild($locat);
			$group->appendChild($element);

			$pointer = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fptr');
			$pointer->setAttribute('FILEID', $metadataId);
			$objectDiv->appendChild($pointer);
		}

		return $fileCounter;
	}//end appendMetadataFiles()

	/**
	 * Generate PREMIS XML preservation metadata.
	 *
	 * @param string $transferId The transfer list UUID.
	 * @param array<int,array<string,mixed>> $objectsWithFiles Objects and their file metadata.
	 *
	 * @return string The PREMIS XML string.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
	 */
	private function generatePremisXml(string $transferId, array $objectsWithFiles): string {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$premis = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:premis');
		$premis->setAttribute('version', '2.0');
		$dom->appendChild($premis);

		$event = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:event');
		$premis->appendChild($event);

		$eventId = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventIdentifier');
		$eventIdType = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventIdentifierType');
		$eventIdType->textContent = 'UUID';
		$eventId->appendChild($eventIdType);
		$eventIdValue = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventIdentifierValue');
		$eventIdValue->textContent = $transferId;
		$eventId->appendChild($eventIdValue);
		$event->appendChild($eventId);

		$eventType = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventType');
		$eventType->textContent = 'creation';
		$event->appendChild($eventType);

		$eventDateTime = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventDateTime');
		$eventDateTime->textContent = (new DateTime())->format('c');
		$event->appendChild($eventDateTime);

		foreach ($objectsWithFiles as $item) {
			$object = $item['object'];
			$files = $item['files'];
			$uuid = $object->getUuid();

			foreach ($files as $file) {
				$premisObject = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:object');
				$premisObject->setAttributeNS(
					'http://www.w3.org/2001/XMLSchema-instance',
					'xsi:type',
					'premis:file'
				);
				$premis->appendChild($premisObject);

				$objId = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifier');
				$objIdType = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifierType');
				$objIdType->textContent = 'filepath';
				$objId->appendChild($objIdType);

				$subDir = 'original';
				if ($file['isRendition'] === true) {
					$subDir = 'rendition';
				}

				$objIdValue = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifierValue');
				$objIdValue->textContent = "objects/{$uuid}/content/{$subDir}/{$file['name']}";
				$objId->appendChild($objIdValue);
				$premisObject->appendChild($objId);

				$objChar = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectCharacteristics');

				$fixity = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:fixity');
				$algo = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:messageDigestAlgorithm');
				$algo->textContent = 'SHA-256';
				$fixity->appendChild($algo);
				$digest = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:messageDigest');
				$digest->textContent = $file['checksum'];
				$fixity->appendChild($digest);
				$objChar->appendChild($fixity);

				$size = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:size');
				$size->textContent = (string)$file['size'];
				$objChar->appendChild($size);

				$format = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:format');
				$formatDesignation = $dom->createElementNS(
					self::PREMIS_NAMESPACE,
					'premis:formatDesignation'
				);
				$formatName = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:formatName');
				$formatName->textContent = $file['format'];
				$formatDesignation->appendChild($formatName);
				$format->appendChild($formatDesignation);
				$objChar->appendChild($format);

				$premisObject->appendChild($objChar);
			}//end foreach
		}//end foreach

		return $dom->saveXML();
	}//end generatePremisXml()
}//end class
