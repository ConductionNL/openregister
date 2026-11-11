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

use InvalidArgumentException;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

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
 */
class SipPackageBuilder {

	/**
	 * Default maximum package size in bytes (2 GB).
	 */
	public const DEFAULT_MAX_PACKAGE_SIZE = 2147483648;

	/**
	 * Writes the zip or BagIt archive.
	 *
	 * @var SipArchiveWriter
	 */
	private readonly SipArchiveWriter $archives;

	/**
	 * Constructor.
	 *
	 * @param MdtoXmlGenerator $mdtoGenerator The MDTO XML generator.
	 * @param IAppConfig       $appConfig     The app configuration.
	 * @param ITempManager     $tempManager   Temporary file manager.
	 * @param LoggerInterface  $logger        Logger.
	 */
	public function __construct(
		private readonly MdtoXmlGenerator $mdtoGenerator,
		private readonly IAppConfig $appConfig,
		ITempManager $tempManager,
		private readonly LoggerInterface $logger,
	) {
		$this->archives = new SipArchiveWriter(tempManager: $tempManager);
	}//end __construct()

	/**
	 * Build SIP package(s) for a transfer: more than one when its files exceed the maximum package size.
	 *
	 * @param TransferPackageRequest $request The transfer's objects, size limit and format.
	 *
	 * @return array<int, string> Array of file paths to generated SIP archives.
	 *
	 * @throws InvalidArgumentException If no objects are provided or the format is unknown.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 *   (Requirement: BagIt is a serialization option on the existing builder)
	 */
	public function package(TransferPackageRequest $request): array {
		self::assertBuildable(objectsWithFiles: $request->objectsWithFiles, format: $request->format);

		$maxSize  = $this->maxPackageSize(requested: $request->maxPackageSize);
		$batches  = self::splitIntoBatches(objectsWithFiles: $request->objectsWithFiles, maxSize: $maxSize);

		return $this->buildBatches(request: $request, batches: $batches);
	}//end package()

	/**
	 * Build one SIP archive per batch, numbered in order.
	 *
	 * @param TransferPackageRequest $request The transfer's id and format.
	 * @param array<int, array>      $batches The objects of each package.
	 *
	 * @return array<int, string> The generated archive paths.
	 */
	private function buildBatches(TransferPackageRequest $request, array $batches): array {
		$sipFiles = [];
		foreach ($batches as $index => $batch) {
			$part       = new SipPart(
				transferId: $request->transferId,
				sequenceNumber: ($index + 1),
				totalPackages: count($batches),
				format: $request->format
			);
			$sipFiles[] = $this->buildSinglePackage(run: new SipPackageRun(part: $part, objectsWithFiles: $batch));
		}

		return $sipFiles;
	}//end buildBatches()

	/**
	 * Refuse an empty transfer and an unknown format.
	 *
	 * @param array  $objectsWithFiles Objects and their file metadata.
	 * @param string $format           The output format.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If no objects are provided or the format is unknown.
	 */
	private static function assertBuildable(array $objectsWithFiles, string $format): void {
		if (empty($objectsWithFiles) === true) {
			throw new InvalidArgumentException('No objects provided for SIP package');
		}

		if (in_array($format, ['zip', 'bagit'], true) === false) {
			throw new InvalidArgumentException("Unknown SIP output format '{$format}' (expected 'zip' or 'bagit')");
		}
	}//end assertBuildable()

	/**
	 * The maximum package size: the requested one, else the configured one.
	 *
	 * @param int $requested The requested size, or 0 or less for the configured one.
	 *
	 * @return int The size in bytes.
	 */
	private function maxPackageSize(int $requested): int {
		if ($requested > 0) {
			return $requested;
		}

		return (int)$this->appConfig->getValueString(
			'openregister',
			'edepot_max_package_size',
			(string)self::DEFAULT_MAX_PACKAGE_SIZE
		);
	}//end maxPackageSize()

	/**
	 * Split objects into batches that each fit within the maximum package size.
	 *
	 * An object is never split: one larger than the maximum gets a batch of its own.
	 *
	 * @param array $objectsWithFiles Objects with file metadata (never empty).
	 * @param int   $maxSize          Maximum batch size in bytes.
	 *
	 * @return array<int, array> Array of batches.
	 */
	private static function splitIntoBatches(array $objectsWithFiles, int $maxSize): array {
		$batches     = [[]];
		$currentSize = 0;
		foreach ($objectsWithFiles as $item) {
			$itemSize = array_sum(array_column($item['files'], 'size'));
			if (end($batches) !== [] && ($currentSize + $itemSize) > $maxSize) {
				$batches[]   = [];
				$currentSize = 0;
			}

			$batches[array_key_last($batches)][] = $item;
			$currentSize += $itemSize;
		}

		return $batches;
	}//end splitIntoBatches()

	/**
	 * Build one SIP package.
	 *
	 * @param SipPackageRun $run The package: its part, its objects and its contents.
	 *
	 * @return string Path to the generated archive.
	 *
	 * @throws InvalidArgumentException When an object misses a transfer precondition or the archive cannot be written.
	 */
	private function buildSinglePackage(SipPackageRun $run): string {
		$contents = $run->contents;
		foreach ($run->objectsWithFiles as $item) {
			$this->addObject(contents: $contents, item: $item);
		}

		$contents->addDocument(path: 'mets.xml', content: self::metsXml(run: $run));
		$contents->addDocument(path: 'premis.xml', content: self::premisXml(run: $run));
		if ($run->part->isSplit() === true) {
			$contents->addDocument(path: 'sip-sequence.json', content: $run->part->sequenceJson());
		}

		$archivePath = $this->archives->write(part: $run->part, entries: $contents->entries());
		$this->logBuilt(run: $run, archivePath: $archivePath);

		return $archivePath;
	}//end buildSinglePackage()

	/**
	 * Add one object: its MDTO document, its data snapshot and its content files.
	 *
	 * The retention period is required to TRANSFER, though MDTO allows the
	 * element to be absent. Asking here keeps the export endpoint able to
	 * serialise a record whose period is unknown.
	 *
	 * @param SipContents          $contents The package contents.
	 * @param array<string, mixed> $item     The object with its files.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object misses a transfer precondition.
	 */
	private function addObject(SipContents $contents, array $item): void {
		$object    = $item['object'];
		$uuid      = $object->getUuid();
		$objectDir = "objects/{$uuid}";
		$this->mdtoGenerator->assertTransferPreconditions($object);

		$mdtoXml = $this->mdtoGenerator->generate($object, $item['files']);
		$contents->listAsMetadata(uuid: $uuid, row: $contents->addDocument(path: "{$objectDir}/mdto.xml", content: $mdtoXml));
		$metadataJson = json_encode($object->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		$contents->addDocument(path: "{$objectDir}/metadata.json", content: $metadataJson);
		$this->addContentFiles(contents: $contents, item: $item);
	}//end addObject()

	/**
	 * Add the object's content files that are on disk, each with its MDTO bestand sidecar.
	 *
	 * @param SipContents          $contents The package contents.
	 * @param array<string, mixed> $item     The object with its files.
	 *
	 * @return void
	 */
	private function addContentFiles(SipContents $contents, array $item): void {
		$object = $item['object'];
		foreach ($item['files'] as $file) {
			if (file_exists($file['path']) === false) {
				continue;
			}

			$filePath = "objects/{$object->getUuid()}/content/".SipLayout::subDir(file: $file)."/{$file['name']}";
			$contents->addFile(path: $filePath, file: $file);
			$sidecar = $this->mdtoGenerator->generateBestand($object, $file);
			$row = $contents->addDocument(path: $filePath.MdtoBestandGenerator::SIDECAR_SUFFIX, content: $sidecar);
			$contents->listAsMetadata(uuid: $object->getUuid(), row: $row);
		}
	}//end addContentFiles()

	/**
	 * The package's mets.xml.
	 *
	 * @param SipPackageRun $run The package, with its contents for the MDTO documents.
	 *
	 * @return string The document.
	 */
	private static function metsXml(SipPackageRun $run): string {
		$mets = new SipMetsDocument(transferId: $run->part->transferId);
		foreach ($run->objectsWithFiles as $item) {
			$mets->addObject(item: $item, contents: $run->contents);
		}

		return $mets->xml();
	}//end metsXml()

	/**
	 * The package's premis.xml.
	 *
	 * @param SipPackageRun $run The package.
	 *
	 * @return string The document.
	 */
	private static function premisXml(SipPackageRun $run): string {
		$premis = new SipPremisDocument(transferId: $run->part->transferId);
		foreach ($run->objectsWithFiles as $item) {
			foreach ($item['files'] as $file) {
				$premis->addFile(uuid: $item['object']->getUuid(), file: $file);
			}
		}

		return $premis->xml();
	}//end premisXml()

	/**
	 * Log a built package.
	 *
	 * @param SipPackageRun $run         The package.
	 * @param string        $archivePath Where it was written.
	 *
	 * @return void
	 */
	private function logBuilt(SipPackageRun $run, string $archivePath): void {
		$part = $run->part;
		$this->logger->info(
			message: '[SipPackageBuilder] Built SIP package',
			context: [
				'transferId' => $part->transferId,
				'sequence' => "{$part->sequenceNumber}/{$part->totalPackages}",
				'objects' => count($run->objectsWithFiles),
				'format' => $part->format,
				'path' => $archivePath,
			]
		);
	}//end logBuilt()
}//end class
