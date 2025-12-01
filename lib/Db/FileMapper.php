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
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
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
 *
 * @method array insert(Entity $entity)
 * @method array update(Entity $entity)
 * @method array insertOrUpdate(Entity $entity)
 * @method array delete(Entity $entity)
 * @method array find(int|string $id)
 * @method array findEntity(IQueryBuilder $query)
 * @method File[] findAll(int|null $limit = null, int|null $offset = null)
 * @method File[] findEntities(IQueryBuilder $query)
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
     * Constructor
     *
     * @param IDBConnection $db           Database connection
     * @param IURLGenerator $urlGenerator URL generator
     */
    public function __construct(
        IDBConnection $db,
        IURLGenerator $urlGenerator
    ) {
        parent::__construct($db, 'openregister_files', File::class);
        $this->urlGenerator = $urlGenerator;
    }//end __construct()


    /**
     * Get all files for a given node (parent) and/or file IDs with share information and owner data.
     *
     * @param int|null   $node The parent node ID (optional)
     * @param array|null $ids  The file IDs to filter (optional)
     *
     * @return array<int, array> List of files as associative arrays with share information and owner data
     *
     * @phpstan-param  int|null $node
     * @phpstan-param  array<int>|null $ids
     * @phpstan-return list<File>
     */
    public function getFiles(?int $node=null, ?array $ids=null): array
    {
        // Create a new query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Select all filecache fields, share information, mimetype strings, and owner information.
        $qb->select(
                'fc.fileid',
                'fc.storage',
                'fc.path',
                'fc.path_hash',
                'fc.parent',
                'fc.name',
                'mt.mimetype',
                'mp.mimetype as mimepart',
                'fc.size',
                'fc.mtime',
                'fc.storage_mtime',
                'fc.encrypted',
                'fc.unencrypted_size',
                'fc.etag',
                'fc.permissions',
                'fc.checksum',
                's.token as share_token',
                's.stime as share_stime',
                'st.id as storage_id'
            )
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'mimetypes', 'mp', $qb->expr()->eq('fc.mimepart', 'mp.id'))
            ->leftJoin(
                    'fc',
                    'share',
                    's',
                $qb->expr()->andX(
                    $qb->expr()->eq('s.file_source', 'fc.fileid'),
                    $qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))
        // 3 = public link.
                )
            )
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'));

        // Add condition for node/parent if provided.
        if ($node !== null) {
            $qb->andWhere($qb->expr()->eq('fc.parent', $qb->createNamedParameter($node, IQueryBuilder::PARAM_INT)));
        }

        // Add condition for file IDs if provided.
        if ($ids !== null && count($ids) > 0) {
            $qb->andWhere($qb->expr()->in('fc.fileid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        // Execute the query and fetch all results using proper Nextcloud method.
        $result = $qb->executeQuery();
        $files  = [];

        // Fetch all rows manually and process share information and owner data.
        $row = $result->fetch();
        while ($row !== false) {
            // Add share-related fields (public URLs if shared).
            if (empty($row['share_token']) === false) {
                $row['accessUrl']   = $this->generateShareUrl($row['share_token']);
                $row['downloadUrl'] = $this->generateShareUrl($row['share_token']).'/download';
            } else {
                // Add authenticated URLs for non-shared files (requires login).
                $row['accessUrl']   = $this->generateAuthenticatedAccessUrl($row['fileid']);
                $row['downloadUrl'] = $this->generateAuthenticatedDownloadUrl($row['fileid']);
            }

            if (empty($row['share_stime']) === false) {
                $row['published'] = (new DateTime())->setTimestamp($row['share_stime'])->format('c');
            } else {
                $row['published'] = null;
            }

            // Extract owner from storage ID (format is usually "home::username").
            $row['owner'] = null;
            if (empty($row['storage_id']) === false) {
                if (str_starts_with($row['storage_id'], 'home::') === true) {
                    $row['owner'] = substr($row['storage_id'], 6);
                    // Remove "home::" prefix.
                } else {
                    $row['owner'] = $row['storage_id'];
                    // Fallback to full storage ID.
                }
            }

            $files[] = $row;
            $row     = $result->fetch();
        }//end while

        $result->closeCursor();

        // Return the list of files with share information.
        return $files;

    }//end getFiles()


    /**
     * Get a single file by its fileid with share information and owner data.
     *
     * @param int $fileId The file ID
     *
     * @return array|null The file as an associative array with share information and owner data, or null if not found
     *
     * @phpstan-param  int $fileId
     * @phpstan-return File|null
     */
    public function getFile(int $fileId): ?array
    {
        // Create a new query builder instance.
        $qb = $this->db->getQueryBuilder();

        // Select all filecache fields, share information, mimetype strings, and owner information.
        $qb->select(
                'fc.fileid',
                'fc.storage',
                'fc.path',
                'fc.path_hash',
                'fc.parent',
                'fc.name',
                'mt.mimetype',
                'mp.mimetype as mimepart',
                'fc.size',
                'fc.mtime',
                'fc.storage_mtime',
                'fc.encrypted',
                'fc.unencrypted_size',
                'fc.etag',
                'fc.permissions',
                'fc.checksum',
                's.token as share_token',
                's.stime as share_stime',
                'st.id as storage_id'
            )
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'mimetypes', 'mp', $qb->expr()->eq('fc.mimepart', 'mp.id'))
            ->leftJoin(
                    'fc',
                    'share',
                    's',
                $qb->expr()->andX(
                    $qb->expr()->eq('s.file_source', 'fc.fileid'),
                    $qb->expr()->eq('s.share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT))
        // 3 = public link.
                )
            )
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        // Execute the query and fetch the result using proper Nextcloud method.
        $result = $qb->executeQuery();
        $file   = $result->fetch();
        $result->closeCursor();

        // Return null if file not found.
        if ($file === false) {
            return null;
        }

        // Add share-related fields (public URLs if shared).
        if (empty($file['share_token']) === false) {
            $file['accessUrl']   = $this->generateShareUrl($file['share_token']);
            $file['downloadUrl'] = $this->generateShareUrl($file['share_token']).'/download';
        } else {
            // Add authenticated URLs for non-shared files (requires login).
            $file['accessUrl']   = $this->generateAuthenticatedAccessUrl($file['fileid']);
            $file['downloadUrl'] = $this->generateAuthenticatedDownloadUrl($file['fileid']);
        }

        if (empty($file['share_stime']) === false) {
            $file['published'] = (new DateTime())->setTimestamp($file['share_stime'])->format('c');
        } else {
            $file['published'] = null;
        }

        // Extract owner from storage ID (format is usually "home::username").
        $file['owner'] = null;
        if (empty($file['storage_id']) === false) {
            if (str_starts_with($file['storage_id'], 'home::') === true) {
                $file['owner'] = substr($file['storage_id'], 6);
                // Remove "home::" prefix.
            } else {
                $file['owner'] = $file['storage_id'];
                // Fallback to full storage ID.
            }
        }

        return $file;

    }//end getFile()


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
     * @phpstan-param  ObjectEntity $object
     * @phpstan-return list<File>
     */
    public function getFilesForObject(ObjectEntity $object): array
    {
        // Retrieve the folder property from the object entity.
        $folder = $object->getFolder();

        // If folder is set, use it as the node id.
        if ($folder !== null) {
            $nodeId = (int) $folder;
            return $this->getFiles($nodeId);
        }

        // If folder is not set, search oc_filecache for a node with name equal to the object's uuid.
        $uuid = $object->getUuid();
        if ($uuid === null) {
            // If uuid is not set, return empty array.
            return [];
        }

        // Create a new query builder instance.
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
            ->from('filecache')
            ->where($qb->expr()->eq('name', $qb->createNamedParameter($uuid)));

        // Execute the query and fetch all matching rows using proper Nextcloud method.
        $result = $qb->executeQuery();
        $rows   = [];

        // Fetch all rows manually.
        $row = $result->fetch();
        while ($row !== false) {
            $rows[] = $row;
            $row    = $result->fetch();
        }

        $result->closeCursor();

        // Handle the number of results.
        $count = count($rows);
        if ($count === 1) {
            // Use the fileid as the node id.
            $nodeId = (int) $rows[0]['fileid'];
            return $this->getFiles($nodeId);
        } else if ($count > 1) {
            // Multiple folders found with same UUID - pick the oldest one (lowest fileid).
            // TODO: Add nightly cron job to cleanup orphaned folders and logs.
            usort(
                    $rows,
                    function ($a, $b) {
                        return (int) $a['fileid'] - (int) $b['fileid'];
                    }
                    );
            $oldestNodeId = (int) $rows[0]['fileid'];
            return $this->getFiles($oldestNodeId);
        } else {
            // No results found, return empty array.
            return [];
        }

    }//end getFilesForObject()


    /**
     * Generate a share URL from a share token.
     *
     * @param string $token The share token
     *
     * @return string The complete share URL
     *
     * @phpstan-param  string $token
     * @phpstan-return string
     */
    private function generateShareUrl(string $token): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        return $baseUrl.'/index.php/s/'.$token;

    }//end generateShareUrl()


    /**
     * Generate an authenticated access URL for a file (requires login).
     *
     * This URL uses Nextcloud's file preview/access endpoint which requires
     * the user to be authenticated to access the file.
     *
     * @param int $fileId The file ID
     *
     * @return string The authenticated access URL
     *
     * @phpstan-param  int $fileId
     * @phpstan-return string
     */
    private function generateAuthenticatedAccessUrl(int $fileId): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        return $baseUrl.'/index.php/core/preview?fileId='.$fileId.'&x=1920&y=1080&a=1';

    }//end generateAuthenticatedAccessUrl()


    /**
     * Generate an authenticated download URL for a file (requires login).
     *
     * This URL uses Nextcloud's direct download endpoint which requires
     * the user to be authenticated to download the file.
     *
     * @param int $fileId The file ID
     *
     * @return string The authenticated download URL
     *
     * @phpstan-param  int $fileId
     * @phpstan-return string
     */
    private function generateAuthenticatedDownloadUrl(int $fileId): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        return $baseUrl.'/index.php/apps/openregister/api/files/'.$fileId.'/download';

    }//end generateAuthenticatedDownloadUrl()


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
     * @phpstan-param  int $fileId
     * @phpstan-param  string $sharedBy
     * @phpstan-param  string $shareOwner
     * @phpstan-param  int $permissions
     * @phpstan-return array{id: int, token: string, accessUrl: string, downloadUrl: string, published: string}
     */
    public function publishFile(int $fileId, string $sharedBy, string $shareOwner, int $permissions=1): array
    {
        // Check if a public share already exists for this file.
        $existingShare = $this->getPublicShare($fileId);
        if ($existingShare !== null) {
            // Return existing share information.
            return [
                'id'          => $existingShare['id'],
                'token'       => $existingShare['token'],
                'accessUrl'   => $this->generateShareUrl($existingShare['token']),
                'downloadUrl' => $this->generateShareUrl($existingShare['token']).'/download',
                'published'   => (new DateTime())->setTimestamp($existingShare['stime'])->format('c'),
            ];
        }

        // Generate a unique token for the share.
        $token       = $this->generateShareToken();
        $currentTime = time();

        // Insert the new share into the database.
        $qb = $this->db->getQueryBuilder();
        $qb->insert('share')
            ->values(
                    [
                        'share_type'    => $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT),
        // 3 = public link.
                        'share_with'    => $qb->createNamedParameter(null),
                        'password'      => $qb->createNamedParameter(null),
                        'uid_owner'     => $qb->createNamedParameter($shareOwner),
                        'uid_initiator' => $qb->createNamedParameter($sharedBy),
                        'parent'        => $qb->createNamedParameter(null),
                        'item_type'     => $qb->createNamedParameter('file'),
                        'item_source'   => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'item_target'   => $qb->createNamedParameter('/'.$fileId),
                        'file_source'   => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'file_target'   => $qb->createNamedParameter('/'.$fileId),
                        'permissions'   => $qb->createNamedParameter($permissions, IQueryBuilder::PARAM_INT),
                        'stime'         => $qb->createNamedParameter($currentTime, IQueryBuilder::PARAM_INT),
                        'accepted'      => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'expiration'    => $qb->createNamedParameter(null),
                        'token'         => $qb->createNamedParameter($token),
                        'mail_send'     => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'hide_download' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    ]
                    );

        $result = $qb->executeStatement();

        if ($result !== 1) {
            throw new \Exception('Failed to create public share in database');
        }

        // Get the ID of the newly created share.
        $shareId = $qb->getLastInsertId();

        return [
            'id'          => $shareId,
            'token'       => $token,
            'accessUrl'   => $this->generateShareUrl($token),
            'downloadUrl' => $this->generateShareUrl($token).'/download',
            'published'   => (new DateTime())->setTimestamp($currentTime)->format('c'),
        ];

    }//end publishFile()


    /**
     * Depublish a file by removing all public shares directly from the database.
     *
     * @param int $fileId The file ID to depublish
     *
     * @return array Information about the deletion operation
     *
     * @throws \Exception If the share deletion fails
     *
     * @phpstan-param  int $fileId
     * @phpstan-return array{deleted_shares: int, file_id: int}
     */
    public function depublishFile(int $fileId): array
    {
        // Delete all public shares for this file.
        $qb = $this->db->getQueryBuilder();
        $qb->delete('share')
            ->where($qb->expr()->eq('file_source', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(3, IQueryBuilder::PARAM_INT)));
        // 3 = public link.
        $deletedCount = $qb->executeStatement();

        return [
            'deleted_shares' => $deletedCount,
            'file_id'        => $fileId,
        ];

    }//end depublishFile()


    /**
     * Get an existing public share for a file.
     *
     * @param int $fileId The file ID
     *
     * @return array|null The share information or null if not found
     *
     * @phpstan-param  int $fileId
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
        $share  = $result->fetch();
        $result->closeCursor();

        if ($share === false) {
            return null;
        }

        return $share;

    }//end getPublicShare()


    /**
     * Generate a unique share token.
     *
     * @return string A unique share token
     *
     * @phpstan-return string
     */
    private function generateShareToken(): string
    {
        // Generate a random token similar to how Nextcloud does it.
        // Using a combination of letters and numbers, 15 characters long.
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token      = '';
        $max        = strlen($characters) - 1;

        for ($i = 0; $i < 15; $i++) {
            $token .= $characters[random_int(0, $max)];
        }

        // Ensure the token is unique by checking if it already exists.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('share')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

        $result = $qb->executeQuery();
        $exists = $result->fetch();
        $result->closeCursor();

        // If token exists, generate a new one recursively.
        if ($exists !== false) {
            return $this->generateShareToken();
        }

        return $token;

    }//end generateShareToken()


    /**
     * Count all files in the Nextcloud installation
     *
     * @return int Total number of files in oc_filecache
     *
     * @phpstan-return int
     */
    public function countAllFiles(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('fc.fileid', 'count'))
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)));
        // Exclude directories.
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['count'] ?? 0);

    }//end countAllFiles()


    /**
     * Get total storage size of all files in the Nextcloud installation
     *
     * @return int Total size in bytes of all files in oc_filecache
     *
     * @phpstan-return int
     */
    public function getTotalFilesSize(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('SUM(fc.size)'), 'total_size')
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->where($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)));
        // Exclude directories.
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return (int) ($row['total_size'] ?? 0);

    }//end getTotalFilesSize()


    /**
     * Find files in Nextcloud that are not tracked in the extraction system yet
     *
     * This queries oc_filecache for files that don't have a corresponding record
     * in oc_openregister_file_texts. These are "untracked" files that need to be
     * added to the extraction system.
     *
     * Only includes user files from home:: storages, excluding:
     * - Directories
     * - System files (appdata, previews, etc)
     * - Trashed files
     * - External/temporary storages
     *
     * @param int $limit Maximum number of untracked files to return
     *
     * @return array List of untracked files with basic metadata
     *
     * @phpstan-param  int $limit
     * @phpstan-return list<array{fileid: int, path: string, name: string, mimetype: string, size: int, mtime: int, checksum: string|null}>
     */
    public function findUntrackedFiles(int $limit=100): array
    {
        $qb = $this->db->getQueryBuilder();

        // Select files from oc_filecache that don't exist in oc_openregister_file_texts.
        $qb->select(
                'fc.fileid',
                'fc.path',
                'fc.name',
                'mt.mimetype',
                'fc.size',
                'fc.mtime',
                'fc.checksum'
            )
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->leftJoin('fc', 'openregister_file_texts', 'ft', $qb->expr()->eq('fc.fileid', 'ft.file_id'))
            ->where($qb->expr()->isNull('ft.id'))
        // No corresponding record in file_texts.
            ->andWhere($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)))
        // Exclude directories.
            ->andWhere($qb->expr()->like('st.id', $qb->createNamedParameter('home::%', IQueryBuilder::PARAM_STR)))
        // Only user home storages.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%files_trashbin%', IQueryBuilder::PARAM_STR)))
        // Exclude trash.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('appdata_%', IQueryBuilder::PARAM_STR)))
        // Exclude system appdata.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%files_versions%', IQueryBuilder::PARAM_STR)))
        // Exclude file versions.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%cache%', IQueryBuilder::PARAM_STR)))
        // Exclude cache.
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%thumbnails%', IQueryBuilder::PARAM_STR)))
        // Exclude thumbnails.
            ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%', IQueryBuilder::PARAM_STR)))
        // Only files in 'files/' directory.
            ->andWhere($qb->expr()->gt('fc.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
        // Exclude empty files.
            ->setMaxResults($limit)
            ->orderBy('fc.fileid', 'ASC');

        $result = $qb->executeQuery();
        $files  = [];

        $row = $result->fetch();
        while ($row !== false) {
            $files[] = $row;
            $row     = $result->fetch();
        }

        $result->closeCursor();

        return $files;

    }//end findUntrackedFiles()


    /**
     * Count untracked files
     *
     * Count files that exist in Nextcloud but haven't been tracked in file_texts table
     *
     * @return int Number of untracked files
     */
    public function countUntrackedFiles(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Same query as findUntrackedFiles but with COUNT.
        $qb->select($qb->createFunction('COUNT(DISTINCT fc.fileid) as count'))
            ->from('filecache', 'fc')
            ->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->leftJoin('fc', 'openregister_file_texts', 'ft', $qb->expr()->eq('fc.fileid', 'ft.file_id'))
            ->where($qb->expr()->isNull('ft.id'))
            ->andWhere($qb->expr()->neq('mt.mimetype', $qb->createNamedParameter('httpd/unix-directory', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->like('st.id', $qb->createNamedParameter('home::%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%files_trashbin%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('appdata_%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%files_versions%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%cache%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->notLike('fc.path', $qb->createNamedParameter('%thumbnails%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%', IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gt('fc.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countUntrackedFiles()


    /**
     * Set file ownership at database level.
     *
     * @param int    $fileId The file ID to change ownership for
     * @param string $userId The user ID to set as owner
     *
     * @return bool True if ownership was updated successfully, false otherwise
     *
     * @throws \Exception If the ownership update fails
     *
     * @TODO: This is a hack to fix NextCloud file ownership issues on production
     * @TODO: where files exist but can't be accessed due to permission problems.
     * @TODO: This should be removed once the underlying NextCloud rights issue is resolved.
     */
    public function setFileOwnership(int $fileId, string $userId): bool
    {
        // Get storage information for this file.
        $qb = $this->db->getQueryBuilder();
        $qb->select('storage')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

        $result   = $qb->executeQuery();
        $fileInfo = $result->fetch();
        $result->closeCursor();

        if ($fileInfo === false || $fileInfo === null) {
            throw new \Exception("File with ID $fileId not found in filecache");
        }

        $storageId = $fileInfo['storage'];

        // Update the storage owner in the oc_storages table.
        $qb = $this->db->getQueryBuilder();
        $qb->update('storages')
            ->set('id', $qb->createNamedParameter("home::$userId"))
            ->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));

        $storageResult = $qb->executeStatement();

        // Also try to update any mounts table if it exists.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('mounts')
                ->set('user_id', $qb->createNamedParameter($userId))
                ->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));

            $qb->executeStatement();
        } catch (\Exception $e) {
            // Mounts table might not exist or might have different structure.
            // This is not critical for the ownership fix.
        }

        return $storageResult > 0;

    }//end setFileOwnership()


}//end class
