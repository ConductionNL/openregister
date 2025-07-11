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

use DateTime;
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
 *   storage_id: string|null,
 *   owner: string|null,
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
     * Get all files for a given node (parent) and/or file IDs with share information and owner data.
     *
     * @param int|null   $node The parent node ID (optional)
     * @param array|null $ids  The file IDs to filter (optional)
     *
     * @return array<int, array> List of files as associative arrays with share information and owner data
     *
     * @phpstan-param int|null $node
     * @phpstan-param array<int>|null $ids
     * @phpstan-return list<File>
     */
    public function getFiles(?int $node = null, ?array $ids = null): array
    {
        // Create a new query builder instance
        $qb = $this->db->getQueryBuilder();
        
        // Select all filecache fields, share information, mimetype strings, and owner information
        $qb->select(
                'fc.fileid', 'fc.storage', 'fc.path', 'fc.path_hash', 'fc.parent', 'fc.name',
                'mt.mimetype', 'mp.mimetype as mimepart',
                'fc.size', 'fc.mtime', 'fc.storage_mtime', 'fc.encrypted', 'fc.unencrypted_size',
                'fc.etag', 'fc.permissions', 'fc.checksum',
                's.token as share_token', 's.stime as share_stime',
                'st.id as storage_id'
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
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'));

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
        
        // Fetch all rows manually and process share information and owner data
        while ($row = $result->fetch()) {
            // Add share-related fields
            $row['accessUrl'] = $row['share_token'] ? $this->generateShareUrl($row['share_token']) : null;
            $row['downloadUrl'] = $row['share_token'] ? $this->generateShareUrl($row['share_token']) . '/download' : null;
            $row['published'] = $row['share_stime'] ? (new DateTime())->setTimestamp($row['share_stime'])->format('c') : null;
            
            // Extract owner from storage ID (format is usually "home::username")
            $row['owner'] = null;
            if ($row['storage_id']) {
                if (str_starts_with($row['storage_id'], 'home::')) {
                    $row['owner'] = substr($row['storage_id'], 6); // Remove "home::" prefix
                } else {
                    $row['owner'] = $row['storage_id']; // Fallback to full storage ID
                }
            }
            
            $files[] = $row;
        }
        
        $result->closeCursor();

        // Return the list of files with share information
        return $files;
    }

    /**
     * Get a single file by its fileid with share information and owner data.
     *
     * @param int $fileId The file ID
     *
     * @return array|null The file as an associative array with share information and owner data, or null if not found
     *
     * @phpstan-param int $fileId
     * @phpstan-return File|null
     */
    public function getFile(int $fileId): ?array
    {
        // Create a new query builder instance
        $qb = $this->db->getQueryBuilder();
        
        // Select all filecache fields, share information, mimetype strings, and owner information
        $qb->select(
                'fc.fileid', 'fc.storage', 'fc.path', 'fc.path_hash', 'fc.parent', 'fc.name',
                'mt.mimetype', 'mp.mimetype as mimepart',
                'fc.size', 'fc.mtime', 'fc.storage_mtime', 'fc.encrypted', 'fc.unencrypted_size',
                'fc.etag', 'fc.permissions', 'fc.checksum',
                's.token as share_token', 's.stime as share_stime',
                'st.id as storage_id'
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
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
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
        $file['published'] = $file['share_stime'] ? (new DateTime())->setTimestamp($file['share_stime'])->format('c') : null;

        // Extract owner from storage ID (format is usually "home::username")
        $file['owner'] = null;
        if ($file['storage_id']) {
            if (str_starts_with($file['storage_id'], 'home::')) {
                $file['owner'] = substr($file['storage_id'], 6); // Remove "home::" prefix
            } else {
                $file['owner'] = $file['storage_id']; // Fallback to full storage ID
            }
        }

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

    /**
     * Publish a file by creating a public share directly in the database.
     *
     * @param int    $fileId      The file ID to publish
     * @param string $sharedBy    The user who is sharing the file
     * @param string $shareOwner  The owner of the file
     * @param int    $permissions The permissions for the share (default: 1 = read)
     *
     * @return array The created share information
     *
     * @throws \Exception If the share creation fails
     *
     * @phpstan-param int $fileId
     * @phpstan-param string $sharedBy
     * @phpstan-param string $shareOwner
     * @phpstan-param int $permissions
     * @phpstan-return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
     */
    public function publishFile(int $fileId, string $sharedBy, string $shareOwner, int $permissions = 1): array
    {
        // Check if a public share already exists for this file
        $existingShare = $this->getPublicShare($fileId);
        if ($existingShare !== null) {
            // Return existing share information
            return [
                'id' => $existingShare['id'],
                'token' => $existingShare['token'],
                'accessUrl' => $this->generateShareUrl($existingShare['token']),
                'downloadUrl' => $this->generateShareUrl($existingShare['token']) . '/download',
                'published' => (new DateTime())->setTimestamp($existingShare['stime'])->format('c')
            ];
        }

        // Generate a unique token for the share
        $token = $this->generateShareToken();
        $currentTime = time();

        // Insert the new share into the database
        $qb = $this->db->getQueryBuilder();
        $qb->insert('share')
            ->values([
                'share_type' => $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT), // 3 = public link
                'share_with' => $qb->createNamedParameter(null),
                'password' => $qb->createNamedParameter(null),
                'uid_owner' => $qb->createNamedParameter($shareOwner),
                'uid_initiator' => $qb->createNamedParameter($sharedBy),
                'parent' => $qb->createNamedParameter(null),
                'item_type' => $qb->createNamedParameter('file'),
                'item_source' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'item_target' => $qb->createNamedParameter('/' . $fileId),
                'file_source' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'file_target' => $qb->createNamedParameter('/' . $fileId),
                'permissions' => $qb->createNamedParameter($permissions, IQueryBuilder::PARAM_INT),
                'stime' => $qb->createNamedParameter($currentTime, IQueryBuilder::PARAM_INT),
                'accepted' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'expiration' => $qb->createNamedParameter(null),
                'token' => $qb->createNamedParameter($token),
                'mail_send' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'hide_download' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)
            ]);

        $result = $qb->executeStatement();
        
        if ($result !== 1) {
            throw new \Exception('Failed to create public share in database');
        }

        // Get the ID of the newly created share
        $shareId = $qb->getLastInsertId();

        return [
            'id' => (int) $shareId,
            'token' => $token,
            'accessUrl' => $this->generateShareUrl($token),
            'downloadUrl' => $this->generateShareUrl($token) . '/download',
            'published' => (new DateTime())->setTimestamp($currentTime)->format('c')
        ];
    }

    /**
     * Depublish a file by removing all public shares directly from the database.
     *
     * @param int $fileId The file ID to depublish
     *
     * @return array Information about the deletion operation
     *
     * @throws \Exception If the share deletion fails
     *
     * @phpstan-param int $fileId
     * @phpstan-return array{deleted_shares: int, file_id: int}
     */
    public function depublishFile(int $fileId): array
    {
        // Delete all public shares for this file
        $qb = $this->db->getQueryBuilder();
        $qb->delete('share')
            ->where($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))); // 3 = public link

        $deletedCount = $qb->executeStatement();

        return [
            'deleted_shares' => $deletedCount,
            'file_id' => $fileId
        ];
    }

    /**
     * Get an existing public share for a file.
     *
     * @param int $fileId The file ID
     *
     * @return array|null The share information or null if not found
     *
     * @phpstan-param int $fileId
     * @phpstan-return array{id: int, token: string, stime: int}|null
     */
    private function getPublicShare(int $fileId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'token', 'stime')
            ->from('share')
            ->where($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $share = $result->fetch();
        $result->closeCursor();

        return $share ?: null;
    }

    /**
     * Generate a unique share token.
     *
     * @return string A unique share token
     *
     * @phpstan-return string
     */
    private function generateShareToken(): string
    {
        // Generate a random token similar to how Nextcloud does it
        // Using a combination of letters and numbers, 15 characters long
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        $max = strlen($characters) - 1;
        
        for ($i = 0; $i < 15; $i++) {
            $token .= $characters[random_int(0, $max)];
        }
        
        // Ensure the token is unique by checking if it already exists
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('share')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
        
        $result = $qb->executeQuery();
        $exists = $result->fetch();
        $result->closeCursor();
        
        // If token exists, generate a new one recursively
        if ($exists) {
            return $this->generateShareToken();
        }
        
        return $token;
    }

    /**
     * Set file ownership at database level.
     *
     * @TODO: This is a hack to fix NextCloud file ownership issues on production
     * @TODO: where files exist but can't be accessed due to permission problems.
     * @TODO: This should be removed once the underlying NextCloud rights issue is resolved.
     *
     * @param int    $fileId The file ID to change ownership for
     * @param string $userId The user ID to set as owner
     *
     * @return bool True if ownership was updated successfully, false otherwise
     *
     * @throws \Exception If the ownership update fails
     *
     * @phpstan-param int $fileId
     * @phpstan-param string $userId
     * @phpstan-return bool
     */
    public function setFileOwnership(int $fileId, string $userId): bool
    {
        // Get storage information for this file
        $qb = $this->db->getQueryBuilder();
        $qb->select('storage')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $fileInfo = $result->fetch();
        $result->closeCursor();

        if (!$fileInfo) {
            throw new \Exception("File with ID $fileId not found in filecache");
        }

        $storageId = $fileInfo['storage'];

        // Update the storage owner in the oc_storages table
        $qb = $this->db->getQueryBuilder();
        $qb->update('storages')
            ->set('id', $qb->createNamedParameter("home::$userId"))
            ->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));

        $storageResult = $qb->executeStatement();

        // Also try to update any mounts table if it exists
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('mounts')
                ->set('user_id', $qb->createNamedParameter($userId))
                ->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));

            $qb->executeStatement();
        } catch (\Exception $e) {
            // Mounts table might not exist or might have different structure
            // This is not critical for the ownership fix
        }

        return $storageResult > 0;
    }
}