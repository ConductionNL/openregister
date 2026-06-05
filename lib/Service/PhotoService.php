<?php

/**
 * PhotoService — Photos integration service for OpenRegister.
 *
 * Provides a filtered view of an object's file attachments limited to image
 * MIME types, with lazy EXIF extraction cached in the openregister_file_links
 * table. Implements AD-1 (Photos is a filtered Files view) and AD-2 (lazy EXIF).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\PhotosProvider;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for the Photos integration.
 *
 * Delegates file listing and folder access to FileService, then applies
 * image MIME filtering. EXIF data is extracted on first access per file
 * and cached in openregister_file_links.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PhotoService
{

    /**
     * App name used for config keys.
     */
    private const APP_NAME = 'openregister';

    /**
     * Config key for the GPS-strip admin setting.
     */
    public const CONFIG_STRIP_GPS = 'photos_strip_gps';

    /**
     * Constructor.
     *
     * @param FileService     $fileService File service for NC folder access.
     * @param IDBConnection   $db          Database connection for link table.
     * @param IConfig         $config      Nextcloud app config.
     * @param LoggerInterface $logger      Logger.
     */
    public function __construct(
        private readonly FileService $fileService,
        private readonly IDBConnection $db,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * List image files attached to an object.
     *
     * Returns the same files as the Files integration but filtered to
     * image/* MIME types (AD-1: Photos is a filtered view of Files).
     *
     * @param ObjectEntity|string $object Object entity or object UUID.
     *
     * @return File[] Array of Nextcloud File nodes that are images.
     */
    public function getPhotos(ObjectEntity|string $object): array
    {
        try {
            $folder = $this->fileService->getObjectFolder(objectEntity: $object);
            if ($folder instanceof Folder === false) {
                return [];
            }

            $nodes  = $folder->getDirectoryListing();
            $photos = [];

            foreach ($nodes as $node) {
                if ($node instanceof File === false) {
                    continue;
                }

                if ($this->isImageMime(mimeType: $node->getMimeType()) === true) {
                    $photos[] = $node;
                }
            }

            return $photos;
        } catch (Exception $e) {
            $this->logger->warning(
                message: 'PhotoService: failed to list photos for object',
                context: ['exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getPhotos()

    /**
     * Get a specific photo by file ID, with EXIF.
     *
     * Validates the file is an image attached to the object, then enriches
     * with EXIF data (lazy, cached in openregister_file_links per AD-2).
     *
     * @param ObjectEntity|string $object Object entity or UUID.
     * @param int                 $fileId Nextcloud file ID.
     *
     * @return array<string, mixed>|null Photo array with exif key, or null if not found.
     */
    public function getPhotoWithExif(ObjectEntity|string $object, int $fileId): ?array
    {
        try {
            $file = $this->fileService->getFile(object: $object, file: $fileId);
            if ($file === null || $this->isImageMime(mimeType: $file->getMimeType()) === false) {
                return null;
            }

            $objectUuid = $this->resolveObjectUuid(object: $object);
            $exifData   = $this->getOrExtractExif(
                file: $file,
                objectUuid: $objectUuid,
                fileId: $fileId
            );

            return $this->formatPhoto(file: $file, exif: $exifData);
        } catch (Exception $e) {
            $this->logger->warning(
                message: 'PhotoService: failed to get photo with EXIF',
                context: ['fileId' => $fileId, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end getPhotoWithExif()

    /**
     * Format a photo file into the API response array.
     *
     * @param File                     $file Photo file node.
     * @param array<string,mixed>|null $exif EXIF data or null.
     *
     * @return array<string, mixed> Formatted photo array.
     */
    public function formatPhoto(File $file, ?array $exif=null): array
    {
        return [
            'id'       => $file->getId(),
            'name'     => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size'     => $file->getSize(),
            'mtime'    => $file->getMTime(),
            'etag'     => $file->getEtag(),
            'exif'     => $exif,
        ];
    }//end formatPhoto()

    /**
     * Check whether GPS-strip admin setting is enabled.
     *
     * @return bool True when GPS data must be stripped at link time.
     */
    public function isGpsStripEnabled(): bool
    {
        // phpcs:disable CustomSn.Functions.NamedParameters -- IConfig uses positional params (__call magic)
        return $this->config->getAppValue(self::APP_NAME, self::CONFIG_STRIP_GPS, 'false') === 'true';
        // phpcs:enable
    }//end isGpsStripEnabled()

    /**
     * Get or lazily extract and cache EXIF for a file.
     *
     * On first access the EXIF is read from the file and stored in
     * openregister_file_links. Subsequent calls return the cached value.
     * GPS data is stripped from the cache if the admin setting is enabled
     * (AD-2: lazy EXIF; spec requirement: optional GPS stripping).
     *
     * @param File   $file       Photo file node.
     * @param string $objectUuid Object UUID.
     * @param int    $fileId     Nextcloud file ID.
     *
     * @return array<string,mixed>|null EXIF data or null.
     */
    public function getOrExtractExif(File $file, string $objectUuid, int $fileId): ?array
    {
        $cached = $this->loadCachedExif(objectUuid: $objectUuid, fileId: $fileId);
        if ($cached !== null) {
            return $cached;
        }

        $exif = $this->extractExifFromFile(file: $file);
        if ($exif === null) {
            return null;
        }

        if ($this->isGpsStripEnabled() === true) {
            $exif = $this->stripGpsFromExif(exif: $exif);
        }

        $this->cacheExif(
            objectUuid: $objectUuid,
            fileId: $fileId,
            exif: $exif,
            gpsStripped: $this->isGpsStripEnabled()
        );

        return $exif;
    }//end getOrExtractExif()

    /**
     * Extract EXIF data from a file using PHP's exif_read_data().
     *
     * Only attempts extraction for JPEG and TIFF files; returns null for
     * other formats or if the exif extension is unavailable.
     *
     * @param File $file Photo file node.
     *
     * @return array<string,mixed>|null Raw EXIF array or null.
     */
    public function extractExifFromFile(File $file): ?array
    {
        if (function_exists('exif_read_data') === false) {
            return null;
        }

        $mimeType = $file->getMimeType();
        if (in_array($mimeType, ['image/jpeg', 'image/tiff'], true) === false) {
            return null;
        }

        try {
            $stream = $file->fopen('r');
            if ($stream === false) {
                return null;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'or_exif_');
            if ($tmpFile === false) {
                fclose($stream);
                return null;
            }

            $tmpStream = fopen($tmpFile, 'w');
            if ($tmpStream === false) {
                fclose($stream);
                unlink($tmpFile);
                return null;
            }

            stream_copy_to_stream($stream, $tmpStream);
            fclose($stream);
            fclose($tmpStream);

            $exif = @exif_read_data(filename: $tmpFile, sections: 'ANY_TAG', arrays: false, thumbnail: false);
            unlink($tmpFile);

            if ($exif === false) {
                return null;
            }

            return $this->sanitizeExif(exif: $exif);
        } catch (Exception $e) {
            $this->logger->debug(
                message: 'PhotoService: EXIF extraction failed',
                context: ['file' => $file->getName(), 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end extractExifFromFile()

    /**
     * Remove GPS keys from an EXIF array (does not modify the original file).
     *
     * @param array<string,mixed> $exif Raw EXIF array.
     *
     * @return array<string,mixed> EXIF array without GPS data.
     */
    public function stripGpsFromExif(array $exif): array
    {
        $gpsKeys = [
            'GPS',
            'GPSLatitude',
            'GPSLongitude',
            'GPSAltitude',
            'GPSLatitudeRef',
            'GPSLongitudeRef',
            'GPSAltitudeRef',
            'GPSTimeStamp',
            'GPSDateStamp',
            'GPSMapDatum',
            'GPSMeasureMode',
            'GPSImgDirection',
            'GPSImgDirectionRef',
            'GPSDestLatitude',
            'GPSDestLongitude',
            'GPSDestBearing',
            'GPSSpeed',
            'GPSTrack',
            'GPSStatus',
            'GPSDOP',
            'GPSSatellites',
            'GPSVersionID',
        ];

        foreach ($gpsKeys as $key) {
            unset($exif[$key]);
        }

        return $exif;
    }//end stripGpsFromExif()

    /**
     * Determine whether a MIME type is a recognised image type.
     *
     * @param string $mimeType File MIME type.
     *
     * @return bool True if the MIME type is an image.
     */
    public function isImageMime(string $mimeType): bool
    {
        return in_array($mimeType, PhotosProvider::IMAGE_MIME_TYPES, true) === true
            || str_starts_with($mimeType, 'image/') === true;
    }//end isImageMime()

    /**
     * Load cached EXIF from openregister_file_links.
     *
     * @param string $objectUuid Object UUID.
     * @param int    $fileId     Nextcloud file ID.
     *
     * @return array<string,mixed>|null Decoded EXIF or null if not cached.
     */
    private function loadCachedExif(string $objectUuid, int $fileId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('exif_metadata')
                ->from('openregister_file_links')
                ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
                ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false || empty($row['exif_metadata']) === true) {
                return null;
            }

            $decoded = json_decode(json: $row['exif_metadata'], associative: true);
            return is_array($decoded) === true ? $decoded : null;
        } catch (Exception $e) {
            $this->logger->debug(
                message: 'PhotoService: EXIF cache load failed',
                context: ['exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end loadCachedExif()

    /**
     * Persist EXIF data to openregister_file_links (insert or update).
     *
     * @param string              $objectUuid  Object UUID.
     * @param int                 $fileId      Nextcloud file ID.
     * @param array<string,mixed> $exif        EXIF data to cache.
     * @param bool                $gpsStripped Whether GPS was stripped.
     *
     * @return void
     */
    private function cacheExif(string $objectUuid, int $fileId, array $exif, bool $gpsStripped): void
    {
        try {
            $json = json_encode(value: $exif, flags: JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $now  = new DateTime();

            $checkQb  = $this->db->getQueryBuilder();
            $existing = $checkQb
                ->select('id')
                ->from('openregister_file_links')
                ->where($checkQb->expr()->eq('object_uuid', $checkQb->createNamedParameter($objectUuid)))
                ->andWhere(
                    $checkQb->expr()->eq('file_id', $checkQb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
                )
                ->executeQuery()
                ->fetch();

            if ($existing === false) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('openregister_file_links')
                    ->values(
                            [
                                'object_uuid'   => $qb->createNamedParameter($objectUuid),
                                'file_id'       => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                                'exif_metadata' => $qb->createNamedParameter($json),
                                'gps_stripped'  => $qb->createNamedParameter($gpsStripped, IQueryBuilder::PARAM_BOOL),
                                'created'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                                'updated'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
                            ]
                            )
                    ->executeStatement();
            } else {
                $qb = $this->db->getQueryBuilder();
                $qb->update('openregister_file_links')
                    ->set('exif_metadata', $qb->createNamedParameter($json))
                    ->set('gps_stripped', $qb->createNamedParameter($gpsStripped, IQueryBuilder::PARAM_BOOL))
                    ->set('updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
                    ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
                    ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            }//end if
        } catch (Exception $e) {
            $this->logger->warning(
                message: 'PhotoService: failed to cache EXIF',
                context: ['exception' => $e->getMessage()]
            );
        }//end try
    }//end cacheExif()

    /**
     * Resolve the UUID string from an object entity or UUID string.
     *
     * @param ObjectEntity|string $object Object or UUID.
     *
     * @return string UUID string.
     */
    private function resolveObjectUuid(ObjectEntity|string $object): string
    {
        if (is_string($object) === true) {
            return $object;
        }

        return $object->getUuid() ?? (string) $object->getId();
    }//end resolveObjectUuid()

    /**
     * Sanitize EXIF array to only include scalar and array values.
     *
     * Removes binary thumbnail data and converts non-UTF-8 strings.
     *
     * @param array<string,mixed> $exif Raw EXIF from exif_read_data().
     *
     * @return array<string,mixed> Sanitized EXIF safe for JSON encoding.
     */
    private function sanitizeExif(array $exif): array
    {
        $safe = [];
        foreach ($exif as $key => $value) {
            if (is_string($key) === false) {
                continue;
            }

            // Skip binary thumbnail data.
            if (str_starts_with(strtolower($key), 'thumbnail') === true) {
                continue;
            }

            if (is_scalar($value) === true) {
                if (is_string($value) === true && mb_detect_encoding($value, 'UTF-8', true) === false) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
                }

                $safe[$key] = $value;
            } else if (is_array($value) === true) {
                $safe[$key] = $value;
            }
        }//end foreach

        return $safe;
    }//end sanitizeExif()
}//end class
