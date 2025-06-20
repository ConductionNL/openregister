<?php
/**
 * FileMapper
 *
 * This file contains the class for handling read-only file operations
 * on the oc_filecache table with share information from oc_share table.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;

/**
 * Class FileMapper
 *
 * Handles read-only operations for the oc_filecache table with share information.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 * @author   Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://OpenRegister.app
 *
 * @phpstan-type File array{
 *   fileid: int,
 *   storage: int,
 *   path: string,
 *   path_hash: string,
 *   parent: int,
 *   name: string,
 *   mimetype: string,
 *   mimepart: string,
 *   size: int,
 *   mtime: int,
 *   storage_mtime: int,
 *   encrypted: int,
 *   unencrypted_size: int,
 *   etag: string,
 *   permissions: int,
 *   checksum: string,
 *   share_token: string|null,
 *   share_stime: int|null,
 *   accessUrl: string|null,
 *   downloadUrl: string|null,
 *   published: string|null
 * }
 */
class FileMapper extends QBMapper
{
    /**
     * The URL generator for creating share links
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * FileMapper constructor.
     *
     * @param IDBConnection $db The database connection
     * @param IURLGenerator $urlGenerator URL generator for share links
     */
    public function __construct(IDBConnection $db, IURLGenerator $urlGenerator)
    {
        parent::__construct($db, 'filecache');
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Get all files for a given node (parent) and/or file IDs with share information.
     *
     * @param int|null   $node The parent node ID (optional)
     * @param array|null $ids  The file IDs to filter (optional)
     *
     * @return array<int, array> List of files as associative arrays with share information
     *
     * @phpstan-param int|null $node
     * @phpstan-param array<int>|null $ids
     * @phpstan-return list<File>
     */
    public function getFiles(?int $node = null, ?array $ids = null): array
    {
        // Create a new query builder instance
        $qb = $this->db->getQueryBuilder();
        
        // Select all filecache fields, share information, and mimetype strings
        $qb->select(
                'fc.fileid', 'fc.storage', 'fc.path', 'fc.path_hash', 'fc.parent', 'fc.name',
                'mt.mimetype', 'mp.mimetype as mimepart',
                'fc.size', 'fc.mtime', 'fc.storage_mtime', 'fc.encrypted', 'fc.unencrypted_size',
                'fc.etag', 'fc.permissions', 'fc.checksum',
                's.token as share_token', 's.stime as share_stime'
            )
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'mimetypes', 'mp', $qb->expr()->eq('fc.mimepart', 'mp.id'))
            ->leftJoin('fc', 'share', 's', 
                $qb->expr()->andX(
                    $qb->expr()->eq('s.file_source', 'fc.fileid'),
                    $qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)) // 3 = public link
                )
            );

        // Add condition for node/parent if provided
        if ($node !== null) {
            $qb->andWhere($qb->expr()->eq('fc.parent', $qb->createNamedParameter($node, IQueryBuilder::PARAM_INT)));
        }

        // Add condition for file IDs if provided
        if ($ids !== null && count($ids) > 0) {
            $qb->andWhere($qb->expr()->in('fc.fileid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        // Execute the query and fetch all results using proper Nextcloud method
        $result = $qb->executeQuery();
        $files = [];
        
        // Fetch all rows manually and process share information
        while ($row = $result->fetch()) {
            // Add share-related fields
            $row['accessUrl'] = $row['share_token'] ? $this->generateShareUrl($row['share_token']) : null;
            $row['downloadUrl'] = $row['share_token'] ? $this->generateShareUrl($row['share_token']) . '/download' : null;
            $row['published'] = $row['share_stime'] ? (new \DateTime())->setTimestamp($row['share_stime'])->format('c') : null;
            
            $files[] = $row;
        }
        
        $result->closeCursor();

        // Return the list of files with share information
        return $files;
    }

    /**
     * Get a single file by its fileid with share information.
     *
     * @param int $fileId The file ID
     *
     * @return array|null The file as an associative array with share information, or null if not found
     *
     * @phpstan-param int $fileId
     * @phpstan-return File|null
     */
    public function getFile(int $fileId): ?array
    {
        // Create a new query builder instance
        $qb = $this->db->getQueryBuilder();
        
        // Select all filecache fields, share information, and mimetype strings
        $qb->select(
                'fc.fileid', 'fc.storage', 'fc.path', 'fc.path_hash', 'fc.parent', 'fc.name',
                'mt.mimetype', 'mp.mimetype as mimepart',
                'fc.size', 'fc.mtime', 'fc.storage_mtime', 'fc.encrypted', 'fc.unencrypted_size',
                'fc.etag', 'fc.permissions', 'fc.checksum',
                's.token as share_token', 's.stime as share_stime'
            )
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'mimetypes', 'mp', $qb->expr()->eq('fc.mimepart', 'mp.id'))
            ->leftJoin('fc', 'share', 's', 
                $qb->expr()->andX(
                    $qb->expr()->eq('s.file_source', 'fc.fileid'),
                    $qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)) // 3 = public link
                )
            )
            ->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        // Execute the query and fetch the result using proper Nextcloud method
        $result = $qb->executeQuery();
        $file = $result->fetch();
        $result->closeCursor();

        // Return null if file not found
        if ($file === false) {
            return null;
        }

        // Add share-related fields
        $file['accessUrl'] = $file['share_token'] ? $this->generateShareUrl($file['share_token']) : null;
        $file['downloadUrl'] = $file['share_token'] ? $this->generateShareUrl($file['share_token']) . '/download' : null;
        $file['published'] = $file['share_stime'] ? (new \DateTime())->setTimestamp($file['share_stime'])->format('c') : null;

        return $file;
    }

    /**
     * Get all files for a given ObjectEntity by using its folder property as the node id.
     * If the folder property is empty, search oc_filecache for a row where name matches the object's uuid.
     * If one result, use its fileid as node id; if more than one, throw an error; if zero, return empty array.
     *
     * @param ObjectEntity $object The object entity whose folder property is used as node id
     *
     * @return array<int, array> List of files as associative arrays with share information
     *
     * @throws \RuntimeException If more than one node is found for the object's uuid
     *
     * @phpstan-param ObjectEntity $object
     * @phpstan-return list<File>
     */
    public function getFilesForObject(ObjectEntity $object): array
    {
        // Retrieve the folder property from the object entity
        $folder = $object->getFolder();

        // If folder is set, use it as the node id
        if ($folder !== null) {
            $nodeId = (int) $folder;
            return $this->getFiles($nodeId);
        }

        // If folder is not set, search oc_filecache for a node with name equal to the object's uuid
        $uuid = $object->getUuid();
        if ($uuid === null) {
            // If uuid is not set, return empty array
            return [];
        }

        // Create a new query builder instance
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
            ->from('filecache')
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($uuid)));

        // Execute the query and fetch all matching rows using proper Nextcloud method
        $result = $qb->executeQuery();
        $rows = [];
        
        // Fetch all rows manually
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        
        $result->closeCursor();

        // Handle the number of results
        $count = count($rows);
        if ($count === 1) {
            // Use the fileid as the node id
            $nodeId = (int) $rows[0]['fileid'];
            return $this->getFiles($nodeId);
        } elseif ($count > 1) {
            // More than one result found, throw an error
            throw new \RuntimeException('Multiple nodes found in oc_filecache with name equal to object uuid: ' . $uuid);
        } else {
            // No results found, return empty array
            return [];
        }
    }

    /**
     * Generate a share URL from a share token.
     *
     * @param string $token The share token
     *
     * @return string The complete share URL
     *
     * @phpstan-param string $token
     * @phpstan-return string
     */
    private function generateShareUrl(string $token): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        return $baseUrl . '/index.php/s/' . $token;
    }
}