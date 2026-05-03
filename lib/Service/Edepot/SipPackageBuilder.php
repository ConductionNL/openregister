<?php

/**
 * OpenRegister SIP Package Builder
 *
 * Assembles SIP (Submission Information Package) archives conforming to the
 * OAIS reference model (ISO 14721) for e-Depot transfer.
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
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-35
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
 * structural and preservation metadata.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SipPackageBuilder
{

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
     * @param IAppConfig       $appConfig     The app configuration.
     * @param ITempManager     $tempManager   Temporary file manager.
     * @param LoggerInterface  $logger        Logger.
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
     * @param string $transferId       The transfer list UUID.
     * @param array  $objectsWithFiles Objects and their file metadata (object, files[]).
     * @param int    $maxPackageSize   Maximum package size in bytes.
     *
     * @return array<int, string> Array of file paths to generated SIP ZIP archives.
     *
     * @throws InvalidArgumentException If no objects are provided.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-35
     */
    public function build(string $transferId, array $objectsWithFiles, int $maxPackageSize=0): array
    {
        if (empty($objectsWithFiles) === true) {
            throw new InvalidArgumentException('No objects provided for SIP package');
        }

        if ($maxPackageSize <= 0) {
            $maxPackageSize = (int) $this->appConfig->getValueString(
                'openregister',
                'edepot_max_package_size',
                (string) self::DEFAULT_MAX_PACKAGE_SIZE
            );
        }

        $batches      = $this->splitIntoBatches(objectsWithFiles: $objectsWithFiles, maxSize: $maxPackageSize);
        $totalBatches = count($batches);
        $sipFiles     = [];

        foreach ($batches as $index => $batch) {
            $sipFiles[] = $this->buildSinglePackage(
                transferId: $transferId,
                objectsWithFiles: $batch,
                sequenceNumber: ($index + 1),
                totalPackages: $totalBatches
            );
        }

        return $sipFiles;
    }//end build()

    /**
     * Split objects into batches based on maximum package size.
     *
     * @param array $objectsWithFiles Objects and their file metadata.
     * @param int   $maxSize          Maximum package size in bytes.
     *
     * @return array<int, array> Array of batches.
     */
    private function splitIntoBatches(array $objectsWithFiles, int $maxSize): array
    {
        $batches      = [];
        $currentBatch = [];
        $currentSize  = 0;

        foreach ($objectsWithFiles as $item) {
            $itemSize = 0;
            foreach ($item['files'] as $file) {
                $itemSize += $file['size'];
            }

            if (empty($currentBatch) === false && ($currentSize + $itemSize) > $maxSize) {
                $batches[]    = $currentBatch;
                $currentBatch = [];
                $currentSize  = 0;
            }

            $currentBatch[] = $item;
            $currentSize   += $itemSize;
        }

        if (empty($currentBatch) === false) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }//end splitIntoBatches()

    /**
     * Build a single SIP package ZIP archive.
     *
     * @param string $transferId       The transfer list UUID.
     * @param array  $objectsWithFiles Objects in this batch.
     * @param int    $sequenceNumber   This package's position in the sequence.
     * @param int    $totalPackages    Total number of packages.
     *
     * @return string Path to the generated ZIP file.
     */
    private function buildSinglePackage(
        string $transferId,
        array $objectsWithFiles,
        int $sequenceNumber,
        int $totalPackages
    ): string {
        $suffix  = $totalPackages > 1 ? "-part{$sequenceNumber}" : '';
        $zipPath = $this->tempManager->getTemporaryFile(".sip{$suffix}.zip");

        $zip    = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new InvalidArgumentException("Failed to create ZIP archive: error code {$result}");
        }

        $manifest = [];

        foreach ($objectsWithFiles as $item) {
            $object    = $item['object'];
            $files     = $item['files'];
            $uuid      = $object->getUuid();
            $objectDir = "objects/{$uuid}";

            $mdtoXml = $this->mdtoGenerator->generate($object, $files);
            $zip->addFromString("{$objectDir}/mdto.xml", $mdtoXml);
            $manifest[] = $this->createManifestEntry(path: "{$objectDir}/mdto.xml", content: $mdtoXml);

            $metadataJson = json_encode($object->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $zip->addFromString("{$objectDir}/metadata.json", $metadataJson);
            $manifest[] = $this->createManifestEntry(path: "{$objectDir}/metadata.json", content: $metadataJson);

            if (empty($files) === false) {
                foreach ($files as $file) {
                    $subDir   = ($file['isRendition'] === true) ? 'rendition' : 'original';
                    $filePath = "{$objectDir}/content/{$subDir}/{$file['name']}";

                    if (file_exists($file['path']) === true) {
                        $zip->addFile($file['path'], $filePath);
                        $manifest[] = [
                            'path'     => $filePath,
                            'size'     => $file['size'],
                            'checksum' => $file['checksum'],
                        ];
                    }
                }
            }
        }//end foreach

        $metsXml = $this->generateMetsXml(transferId: $transferId, objectsWithFiles: $objectsWithFiles);
        $zip->addFromString('mets.xml', $metsXml);
        $manifest[] = $this->createManifestEntry(path: 'mets.xml', content: $metsXml);

        $premisXml = $this->generatePremisXml(transferId: $transferId, objectsWithFiles: $objectsWithFiles);
        $zip->addFromString('premis.xml', $premisXml);
        $manifest[] = $this->createManifestEntry(path: 'premis.xml', content: $premisXml);

        if ($totalPackages > 1) {
            $sequenceJson = json_encode(
                    [
                        'transferId'     => $transferId,
                        'sequenceNumber' => $sequenceNumber,
                        'totalPackages'  => $totalPackages,
                    ],
                    JSON_PRETTY_PRINT
                    );
            $zip->addFromString('sip-sequence.json', $sequenceJson);
            $manifest[] = $this->createManifestEntry(path: 'sip-sequence.json', content: $sequenceJson);
        }

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $zip->addFromString('sip-manifest.json', $manifestJson);

        $zip->close();

        $this->logger->info(
            message: '[SipPackageBuilder] Built SIP package',
            context: [
                'transferId' => $transferId,
                'sequence'   => "{$sequenceNumber}/{$totalPackages}",
                'objects'    => count($objectsWithFiles),
                'path'       => $zipPath,
            ]
        );

        return $zipPath;
    }//end buildSinglePackage()

    /**
     * Create a manifest entry for a content string.
     *
     * @param string $path    The relative path in the ZIP.
     * @param string $content The file content.
     *
     * @return array{path: string, size: int, checksum: string} The manifest entry.
     */
    private function createManifestEntry(string $path, string $content): array
    {
        return [
            'path'     => $path,
            'size'     => strlen($content),
            'checksum' => hash('sha256', $content),
        ];
    }//end createManifestEntry()

    /**
     * Generate METS XML structural metadata.
     *
     * @param string                         $transferId       The transfer list UUID.
     * @param array<int,array<string,mixed>> $objectsWithFiles Objects and their file metadata.
     *
     * @return string The METS XML string.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
     */
    private function generateMetsXml(string $transferId, array $objectsWithFiles): string
    {
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

        $structMap = $dom->createElementNS(self::METS_NAMESPACE, 'mets:structMap');
        $structMap->setAttribute('TYPE', 'physical');
        $mets->appendChild($structMap);

        $rootDiv = $dom->createElementNS(self::METS_NAMESPACE, 'mets:div');
        $rootDiv->setAttribute('LABEL', 'SIP-'.$transferId);
        $structMap->appendChild($rootDiv);

        $fileCounter = 1;
        foreach ($objectsWithFiles as $item) {
            $object = $item['object'];
            $files  = $item['files'];
            $uuid   = $object->getUuid();

            $objectDiv = $dom->createElementNS(self::METS_NAMESPACE, 'mets:div');
            $objectDiv->setAttribute('LABEL', $uuid);
            $objectDiv->setAttribute('TYPE', 'object');
            $rootDiv->appendChild($objectDiv);

            foreach ($files as $file) {
                $fileId = 'FILE-'.$fileCounter;
                $fileCounter++;

                $isRendition = ($file['isRendition'] === true);
                $subDir      = ($isRendition === true) ? 'rendition' : 'original';
                $filePath    = "objects/{$uuid}/content/{$subDir}/{$file['name']}";

                $fileElement = $dom->createElementNS(self::METS_NAMESPACE, 'mets:file');
                $fileElement->setAttribute('ID', $fileId);
                $fileElement->setAttribute('SIZE', (string) $file['size']);
                $fileElement->setAttribute('MIMETYPE', $file['format']);
                $fileElement->setAttribute('CHECKSUM', $file['checksum']);
                $fileElement->setAttribute('CHECKSUMTYPE', 'SHA-256');

                $fLocat = $dom->createElementNS(self::METS_NAMESPACE, 'mets:FLocat');
                $fLocat->setAttribute('LOCTYPE', 'URL');
                $fLocat->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', $filePath);
                $fileElement->appendChild($fLocat);

                if ($isRendition === true) {
                    $renditionGrp->appendChild($fileElement);
                }

                if ($isRendition === false) {
                    $originalGrp->appendChild($fileElement);
                }

                $fptr = $dom->createElementNS(self::METS_NAMESPACE, 'mets:fptr');
                $fptr->setAttribute('FILEID', $fileId);
                $objectDiv->appendChild($fptr);
            }//end foreach
        }//end foreach

        return $dom->saveXML();
    }//end generateMetsXml()

    /**
     * Generate PREMIS XML preservation metadata.
     *
     * @param string                         $transferId       The transfer list UUID.
     * @param array<int,array<string,mixed>> $objectsWithFiles Objects and their file metadata.
     *
     * @return string The PREMIS XML string.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-20
     */
    private function generatePremisXml(string $transferId, array $objectsWithFiles): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $premis = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:premis');
        $premis->setAttribute('version', '2.0');
        $dom->appendChild($premis);

        $event = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:event');
        $premis->appendChild($event);

        $eventId     = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:eventIdentifier');
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
            $files  = $item['files'];
            $uuid   = $object->getUuid();

            foreach ($files as $file) {
                $premisObject = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:object');
                $premisObject->setAttributeNS(
                    'http://www.w3.org/2001/XMLSchema-instance',
                    'xsi:type',
                    'premis:file'
                );
                $premis->appendChild($premisObject);

                $objId     = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifier');
                $objIdType = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifierType');
                $objIdType->textContent = 'filepath';
                $objId->appendChild($objIdType);

                $subDir     = ($file['isRendition'] === true) ? 'rendition' : 'original';
                $objIdValue = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectIdentifierValue');
                $objIdValue->textContent = "objects/{$uuid}/content/{$subDir}/{$file['name']}";
                $objId->appendChild($objIdValue);
                $premisObject->appendChild($objId);

                $objChar = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:objectCharacteristics');

                $fixity = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:fixity');
                $algo   = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:messageDigestAlgorithm');
                $algo->textContent = 'SHA-256';
                $fixity->appendChild($algo);
                $digest = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:messageDigest');
                $digest->textContent = $file['checksum'];
                $fixity->appendChild($digest);
                $objChar->appendChild($fixity);

                $size = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:size');
                $size->textContent = (string) $file['size'];
                $objChar->appendChild($size);

                $format            = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:format');
                $formatDesignation = $dom->createElementNS(
                    self::PREMIS_NAMESPACE,
                    'premis:formatDesignation'
                );
                $formatName        = $dom->createElementNS(self::PREMIS_NAMESPACE, 'premis:formatName');
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
