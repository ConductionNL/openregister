<?php
/**
 * OpenRegister FileService
 *
 * Service class for handling file operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - CRUD operations on files
 * - Managing relations and linked data (extending objects with related sub-objects)
 * - Audit trails and data aggregation
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git-id>
 *
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Service for handling file operations in OpenRegister.
 *
 * This service provides functionalities for managing files and folders within the NextCloud environment,
 * including creation, deletion, sharing, and file updates. It integrates with NextCloud's file and
 * sharing APIs to provide seamless file management for the application.
 */
class FileService
{
    /**
     * Root folder name for all OpenRegister files
     */
    const ROOT_FOLDER = 'Open Registers';
    
    /**
     * Application group name
     */
    const APP_GROUP = 'openregister';
    
    /**
     * Application user name
     */
    const APP_USER = 'OpenRegister';
    
    /**
     * File tag type identifier
     */
    const FILE_TAG_TYPE = 'files';

    /**
     * Constructor for FileService
     *
     * @param IUserSession           $userSession        The user session
     * @param IUserManager           $userManager        The user manager
     * @param LoggerInterface        $logger             The logger interface
     * @param IRootFolder            $rootFolder         The root folder interface
     * @param IManager               $shareManager       The share manager interface
     * @param IURLGenerator          $urlGenerator       URL generator service
     * @param IConfig                $config             Configuration service
     * @param RegisterMapper         $registerMapper     Register data mapper
     * @param SchemaMapper           $schemaMapper       Schema data mapper
     * @param IGroupManager          $groupManager       Group manager service
     * @param ISystemTagManager      $systemTagManager   System tag manager
     * @param ISystemTagObjectMapper $systemTagMapper    System tag object mapper
     * @param ObjectEntityMapper     $objectEntityMapper Object entity mapper
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly IManager $shareManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IGroupManager $groupManager,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly ObjectEntityMapper $objectEntityMapper
    ) {
    }

    /**
     * Creates a folder for a Register (used for storing files of Schemas/Objects).
     *
     * @param Register|int $register The Register to create the folder for
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return string The path to the folder
     */
    public function createRegisterFolder(Register|int $register): string
    {
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        $registerFolderName = $this->getRegisterFolderName($register);
        // @todo maybe we want to use ShareLink here for register->folder as well?
        $register->setFolder($this::ROOT_FOLDER."/$registerFolderName");
        $this->registerMapper->update($register);

        $folderPath = $this::ROOT_FOLDER."/$registerFolderName";
        $this->createFolder(folderPath: $folderPath);

        return $folderPath;
    }//end createRegisterFolder

    /**
     * Get the name for the folder of a Register (used for storing files of Schemas/Objects).
     *
     * @param Register $register The Register to get the folder name for
     *
     * @return string The name the folder for this Register should have
     */
    private function getRegisterFolderName(Register $register): string
    {
        $title = $register->getTitle();

        if (str_ends_with(haystack: strtolower(rtrim($title)), needle: 'register')) {
            return $title;
        }

        return "$title Register";
    }//end getRegisterFolderName

    /**
     * Creates a folder for a Schema (used for storing files of Objects).
     *
     * @param Register|int $register The Register to create the schema folder for
     * @param Schema|int   $schema   The Schema to create the folder for
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return string The path to the folder
     */
    public function createSchemaFolder(Register|int $register, Schema|int $schema): string
    {
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        if (is_int($schema) === true) {
            $schema = $this->schemaMapper->find($schema);
        }
        /** @todo we could check here if Register contains/has Schema else throw Exception. */

        $registerFolderName = $this->getRegisterFolderName($register);
        // @todo maybe we want to use ShareLink here for register->folder as well?
        $register->setFolder($this::ROOT_FOLDER."/$registerFolderName");
        $this->registerMapper->update($register);

        $schemaFolderName = $this->getSchemaFolderName($schema);

        $folderPath = $this::ROOT_FOLDER."/$registerFolderName/$schemaFolderName";
        $this->createFolder(folderPath: $folderPath);

        return $folderPath;
    } //end createSchemaFolder

    /**
     * Get the name for the folder used for storing files of objects of a specific Schema.
     *
     * @param Schema $schema The Schema to get the folder name for
     *
     * @return string The name the folder for this Schema should have
     */
    private function getSchemaFolderName(Schema $schema): string
    {
        return $schema->getTitle();
    }//end getSchemaFolderName

    /**
     * Creates a folder for an Object (used for storing files of this Object).
     *
     * @param ObjectEntity      $objectEntity The Object to create the folder for
     * @param Register|int|null $register     The Register to create the Object folder for (optional)
     * @param Schema|int|null   $schema       The Schema to create the Object folder for (optional)
     * @param string|null       $folderPath   Optional path to use instead of generating one
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return Node|null The NextCloud Node object of the folder, or null if creation failed
     */
    public function createObjectFolder(
        ObjectEntity      $objectEntity,
        Register|int|null $register = null,
        Schema|int|null   $schema = null,
        string            $folderPath = null
    ): ?Node {
        if ($folderPath === null) {
            $folderPath = $this->getObjectFolderPath(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );
        }
        $this->createFolder(folderPath: $folderPath);

        // @todo Do we want to use ShareLink here?
        // @todo ^If so, we need to update these functions to be able to create shareLinks for folders as well (not only files)
        $objectEntity->setFolder($folderPath);

        // Create or find ShareLink
        // $share = $this->fileService->findShare(path: $filePath);
        // if ($share !== null) {
        //     $shareLink = $this->fileService->getShareLink($share);
        // } else {
        //     $shareLink = $this->fileService->createShareLink(path: $filePath);
        // }

        return $this->getNode($folderPath);
    }//end createObjectFolder

    /**
     * Gets the NextCloud Node object for the folder of an Object.
     *
     * @param ObjectEntity      $objectEntity The Object to get the folder for
     * @param Register|int|null $register     The Register to get the Object folder for (optional)
     * @param Schema|int|null   $schema       The Schema to get the Object folder for (optional)
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return Node|null The NextCloud Node object of the folder, or null if getting/creating failed
     */
    public function getObjectFolder(
        ObjectEntity      $objectEntity,
        Register|int|null $register = null,
        Schema|int|null   $schema = null
    ): ?Node {
        if (empty($objectEntity->getFolder()) === true) {
            $folderPath = $this->getObjectFolderPath(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );
            $objectEntity->setFolder($folderPath);
            $this->objectEntityMapper->update($objectEntity);
        } else {
            $folderPath = $objectEntity->getFolder();
        }

        $node = $this->getNode($folderPath);

        if ($node === null) {
            return $this->createObjectFolder(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema,
                folderPath: $folderPath
            );
        }
        return $node;
    }//end getObjectFolder

    /**
     * Gets the path to the folder of an object.
     *
     * @param ObjectEntity      $objectEntity The Object to get the folder path for
     * @param Register|int|null $register     The Register to get the Object folder path for (must match Object->register)
     * @param Schema|int|null   $schema       The Schema to get the Object folder path for (must match Object->schema)
     *
     * @throws Exception If something went wrong getting the path, a mismatch in object register/schema & function parameters
     *
     * @return string The path to the folder
     */
    private function getObjectFolderPath(
        ObjectEntity      $objectEntity,
        Register|int|null $register = null,
        Schema|int|null   $schema = null
    ): string {
        $objectRegister = (int)$objectEntity->getRegister();
        if ($register === null) {
            $register = $objectRegister;
        }
        if (is_int($register) === true) {
            if ($register !== $objectRegister) {
                $message = "Mismatch in Object->Register ($objectRegister) & Register given in function: getObjectFolderPath() ($register)";
                $this->logger->error(message: $message);
                throw new Exception(message: $message);
            }
            $register = $this->registerMapper->find($register);
        }

        $objectSchema = (int)$objectEntity->getSchema();
        if ($schema === null) {
            $schema = $objectSchema;
        }
        if (is_int($schema) === true) {
            if ($schema !== $objectSchema) {
                $message = "Mismatch in Object->Schema ($objectSchema) & Schema given in function: getObjectFolderPath() ($schema)";
                $this->logger->error(message: $message);
                throw new Exception(message: $message);
            }
            $schema = $this->schemaMapper->find($schema);
        }

        $registerFolderName = $this->getRegisterFolderName($register);
        // @todo maybe we want to use ShareLink here for register->folder as well?
        $register->setFolder($this::ROOT_FOLDER."/$registerFolderName");
        $this->registerMapper->update($register);

        $schemaFolderName = $this->getSchemaFolderName($schema);
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        return $this::ROOT_FOLDER."/$registerFolderName/$schemaFolderName/$objectFolderName";
    }//end getObjectFolderPath

    /**
     * Get the name for the folder used for storing files of the given object.
     *
     * @param ObjectEntity $objectEntity The Object to get the folder name for
     *
     * @return string The name the folder for this object should have
     */
    private function getObjectFolderName(ObjectEntity $objectEntity): string
    {
        /** @todo check if property Title or Name exists and use that as object title. */
        $objectTitle = 'object';

        // return "{$objectEntity->getUuid()} ($objectTitle)";
        return $objectEntity->getUuid();
    }//end getObjectFolderName

    /**
     * Returns a link to the given folder path.
     *
     * @param string $folderPath The path to a folder in NextCloud
     *
     * @return string The URL to access the folder through the web interface
     */
    private function getFolderLink(string $folderPath): string
    {
        $folderPath = str_replace('%2F', '/', urlencode($folderPath));
        return $this->getCurrentDomain()."/index.php/apps/files/files?dir=$folderPath";
    } //end getFolderLink

    /**
     * Returns a share link for the given IShare object.
     *
     * @param IShare $share An IShare object we are getting the share link for
     *
     * @return string The share link needed to get the file or folder for the given IShare object
     */
    public function getShareLink(IShare $share): string
    {
        return $this->getCurrentDomain().'/index.php/s/'.$share->getToken();
    }//end getShareLink

    /**
     * Gets and returns the current host/domain with correct protocol.
     *
     * @return string The current http/https domain URL
     */
    private function getCurrentDomain(): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        $trustedDomains = $this->config->getSystemValue('trusted_domains');

        if (isset($trustedDomains[1]) === true) {
            $baseUrl = str_replace(search: 'localhost', replace: $trustedDomains[1], subject: $baseUrl);
        }

        return $baseUrl;
    }//end getCurrentDomain

    /**
     * Gets or creates the OpenCatalogi user for file operations.
     *
     * @throws Exception If OpenCatalogi user cannot be created
     *
     * @return IUser The OpenCatalogi user
     */
    private function getUser(): IUser
    {
        $openCatalogiUser = $this->userManager->get(self::APP_USER);

        if (!$openCatalogiUser) {
            // Create OpenCatalogi user if it doesn't exist.
            $password = bin2hex(random_bytes(16)); // Generate random password.
            $openCatalogiUser = $this->userManager->createUser(self::APP_USER, $password);

            if (!$openCatalogiUser) {
                throw new \Exception('Failed to create OpenCatalogi user account.');
            }

            // Add user to OpenCatalogi group.
            $group = $this->groupManager->get(self::APP_GROUP);
            if (!$group) {
                $group = $this->groupManager->createGroup(self::APP_GROUP);
            }
            $group->addUser($openCatalogiUser);
        }

        return $openCatalogiUser;
    }//end getUser

    /**
     * Gets a NextCloud Node object for the given file or folder path.
     */
    public function getNode(string $path): ?Node
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());
            return $userFolder->get(path: $path);
        } catch (NotFoundException|NotPermittedException $e) {
            $this->logger->error(message: $e->getMessage());
            return null;
        }
    }//end getNode

    /**
     * Formats a single Node file into a metadata array.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node $file The Node file to format
     *
     * @return array The formatted file metadata array
     */
    public function formatFile(Node $file): array
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        // Get base metadata array.
        $metadata = [
            'id' => $file->getId(),
            'path' => $file->getPath(),
            'title' => $file->getName(),
            'accessUrl' => count($shares) > 0 ? $this->getShareLink($shares[0]) : null,
            'downloadUrl' => count($shares) > 0 ? $this->getShareLink($shares[0]).'/download' : null,
            'type' => $file->getMimetype(),
            'extension' => $file->getExtension(),
            'size' => $file->getSize(),
            'hash' => $file->getEtag(),
            'published' => count($shares) > 0 ? $shares[0]->getShareTime()->format('c') : null,
            'modified' => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
            'labels' => $this->getFileTags(fileId: $file->getId()),
        ];

        // Process labels that contain ':' to add as separate metadata fields.
        $remainingLabels = [];
        foreach ($metadata['labels'] as $label) {
            if (strpos($label, ':') !== false) {
                list($key, $value) = explode(':', $label, 2);
                $key = trim($key);
                $value = trim($value);

                // Skip if key exists in base metadata.
                if (isset($metadata[$key])) {
                    $remainingLabels[] = $label;
                    continue;
                }

                // If key already exists as array, append value.
                if (isset($metadata[$key]) && is_array($metadata[$key])) {
                    $metadata[$key][] = $value;
                }
                // If key exists but not as array, convert to array with both values.
                elseif (isset($metadata[$key])) {
                    $metadata[$key] = [$metadata[$key], $value];
                }
                // If key doesn't exist, create new entry.
                else {
                    $metadata[$key] = $value;
                }
            } else {
                $remainingLabels[] = $label;
            }
        }

        // Update labels array to only contain non-processed labels.
        $metadata['labels'] = $remainingLabels;

        return $metadata;
    } //end formatFile

    /**
     * Formats an array of Node files into an array of metadata arrays.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node[] $files Array of Node files to format
     * @param array $requestParams Optional request parameters
     *
     * @throws InvalidPathException
     * @throws NotFoundException
     *
     * @return array Array of formatted file metadata arrays
     */
    public function formatFiles(array $files, ?array $requestParams = []): array
    {
        // Extract specific parameters.
        $limit = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
        $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? 0;
        $order = $requestParams['order'] ?? $requestParams['_order'] ?? [];
        $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $page = $requestParams['page'] ?? $requestParams['_page'] ?? null;
        $search = $requestParams['_search'] ?? null;

        if ($page !== null && isset($limit)) {
            $page = (int) $page;
            $offset = $limit * ($page - 1);
        }

        // Ensure order and extend are arrays.
        if (is_string($order) === true) {
            $order = array_map('trim', explode(',', $order));
        }
        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Remove unnecessary parameters from filters.
        $filters = $requestParams;
        unset($filters['_route']); // TODO: Investigate why this is here and if it's needed.
        unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);
        unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

        $formattedFiles = [];

        // Counts total before slicing.
        $total = count($files);

        // Apply offset and limit to files array if specified.
        $files = array_slice($files, $offset, $limit);

        foreach ($files as $file) {
            $formattedFiles[] = $this->formatFile($file);
        }

        /** @todo search. */
        $pages = $limit !== null ? ceil($total / $limit) : 1;

        return [
            'results' => $formattedFiles,
            'total' => $total,
            'page' => $page ?? 1,
            'pages' => $pages,
        ];
    }//end formatFiles

    /**
    * Get the tags associated with a file.
    *
    * @param string $fileId The ID of the file.
    *
    * @return array The list of tags associated with the file.
    */
    private function getFileTags(string $fileId): array
    {
        $tagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: $this::FILE_TAG_TYPE);
        if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
            return [];
        }

        $tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds[$fileId]);

        $tagNames = array_map(static function ($tag) {
            return $tag->getName();
        }, $tags);

        return array_values($tagNames);
    }//end getFileTags

    /**
     * Finds shares associated with a file or folder.
     *
     * @param Node $file      The Node file or folder to find shares for
     * @param int  $shareType The type of share to look for (default: 3 for public link)
     *
     * @return IShare[] Array of shares associated with the file
     */
    public function findShares(Node $file, int $shareType = 3): array
    {
        // Get the current user.
        $currentUser = $this->userSession->getUser();
        $userId = $currentUser ? $currentUser->getUID() : 'Guest';

        return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
    }//end findShares

    /**
     * Try to find a IShare object with given $path & $shareType.
     *
     * @param string   $path      The path to a file we are trying to find a IShare object for
     * @param int|null $shareType The shareType of the share we are trying to find (default: 3 for public link)
     *
     * @return IShare|null An IShare object if found, null otherwise
     */
    public function findShare(string $path, ?int $shareType = 3): ?IShare
    {
        $path = trim(string: $path, characters: '/');
        $userId = $this->getUser()->getUID();

        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
        } catch (NotPermittedException) {
            $this->logger->error("Can't find share for $path because user (folder) for user $userId couldn't be found.");
            return null;
        }

        try {
            // Note: if we ever want to find shares for folders instead of files, this should work for folders as well?
            $file = $userFolder->get(path: $path);
        } catch (NotFoundException $e) {
            $this->logger->error("Can't find share for $path because file doesn't exist.");

            return null;
        }

        if ($file instanceof File) {
            $shares = $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
            if (count($shares) > 0) {
                return $shares[0];
            }
        }

        return null;
    }//end findShare

    /**
     * Creates a IShare object using the $shareData array data.
     *
     * @param array $shareData The data to create a IShare with, should contain 'path', 'file', 'shareType', 'permissions' and 'userid'
     *
     * @throws Exception If creating the share fails
     *
     * @return IShare The Created IShare object
     */
    private function createShare(array $shareData): IShare
    {
        $userId = $this->getUser()->getUID();

        // Create a new share.
        $share = $this->shareManager->newShare();
        $share->setTarget(target: '/'.$shareData['path']);
        if (empty($shareData['file']) === false) {
            $share->setNodeId(fileId: $shareData['file']->getId());
        }
        if (empty($shareData['nodeId']) === false) {
            $share->setNodeId(fileId: $shareData['nodeId']);
        }
        $share->setNodeType(type: $shareData['nodeType'] ?? 'file');
        $share->setShareType(shareType: $shareData['shareType']);
        if ($shareData['permissions'] !== null) {
            $share->setPermissions(permissions: $shareData['permissions']);
        }
        $share->setSharedBy(sharedBy: $userId);
        $share->setShareOwner(shareOwner: $userId);
        $share->setShareTime(shareTime: new DateTime());
        if (empty($shareData['sharedWith']) === false) {
            $share->setSharedWith(sharedWith: $shareData['sharedWith']);
        }
        $share->setStatus(status: $share::STATUS_ACCEPTED);

        return $this->shareManager->createShare(share: $share);
    }//end createShare

    /**
     * Creates and returns a share link for a file (or folder).
     *
     * @see https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html#create-a-new-share.
     *
     * @param string   $path        Path (from root) to the file/folder which should be shared
     * @param int|null $shareType   The share type (0=user, 1=group, 3=public link, 4=email, etc.)
     * @param int|null $permissions Permissions (1=read, 2=update, 4=create, 8=delete, 16=share, 31=all)
     *
     * @throws Exception If creating the share link fails
     *
     * @return string The share link
     */
    public function createShareLink(string $path, ?int $shareType = 3, ?int $permissions = null): string
    {
        $path = trim(string: $path, characters: '/');
        if ($permissions === null) {
            $permissions = 31;
            if ($shareType === 3) {
                $permissions = 1;
            }
        }

        $userId = $this->getUser()->getUID();

        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
        } catch (NotPermittedException) {
            $this->logger->error("Can't create share link for $path because user (folder) for user $userId couldn't be found.");
            return "User (folder) couldn't be found.";
        }

        try {
            $file = $this->rootFolder->get($path);
            // $file = $userFolder->get(path: $path);
        } catch (NotFoundException $e) {
            $this->logger->error("Can't create share link for $path because file doesn't exist.");

            return 'File not found at '.$path;
        }

        try {
            $share = $this->createShare([
                'path' => $path,
                'file' => $file,
                'shareType' => $shareType,
                'permissions' => $permissions,
            ]);
            return $this->getShareLink($share);
        } catch (Exception $exception) {
            $this->logger->error("Can't create share link for $path: ".$exception->getMessage());

            throw new Exception('Can\'t create share link.');
        }
    }//end createShareLink

    /**
     * Deletes all share links for a file or folder.
     *
     * @param Node $file The file or folder whose shares should be deleted
     *
     * @throws Exception If the shares cannot be deleted
     *
     * @return Node The file with shares deleted
     *
     * @psalm-return Node
     *
     * @phpstan-return Node
     */
    public function deleteShareLinks(Node $file): Node
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        foreach ($shares as $share) {
            try {
                $this->shareManager->deleteShare($share);
                $this->logger->info("Successfully deleted share for path: $share->getPath().");
            } catch (Exception $e) {
                $this->logger->error("Failed to delete share for path $share->getPath(): ".$e->getMessage());
                throw new Exception("Failed to delete share for path $share->getPath(): ".$e->getMessage());
            }
        }

        return $file;
    }//end deleteShareLinks

    /**
     * Creates a new folder in NextCloud, unless it already exists.
     *
     * @param string $folderPath Path (from root) to where you want to create a folder, include the name of the folder
     *
     * @throws Exception If creating the folder is not permitted
     *
     * @return bool True if successfully created a new folder, false if folder already exists
     */
    public function createFolder(string $folderPath): bool
    {
        $folderPath = trim(string: $folderPath, characters: '/');

        // Get the current user.
        $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

        // Check if folder exists and if not create it.
        try {
            // First, check if the root folder exists, and if not, create it and share it with the openregister group.
            try {
                $userFolder->get(self::ROOT_FOLDER);
            } catch (NotFoundException $exception) {
                $rootFolder = $userFolder->newFolder(self::ROOT_FOLDER);

                if ($this->groupManager->groupExists(self::APP_GROUP) === false) {
                    $this->groupManager->createGroup(self::APP_GROUP);
                }

                $this->createShare([
                    'path' => self::ROOT_FOLDER,
                    'nodeId' => $rootFolder->getId(),
                    'nodeType' => $rootFolder->getType() === 'file' ? $rootFolder->getType() : 'folder',
                    'shareType' => 1,
                    'permissions' => 31,
                    'sharedWith' => self::APP_GROUP,
                ]);
            }

            try {
                $userFolder->get(path: $folderPath);
            } catch (NotFoundException $e) {
                $userFolder->newFolder(path: $folderPath);

                return true;
            }

            // Folder already exists.
            $this->logger->info("This folder already exits $folderPath");
            return false;

        } catch (NotPermittedException $e) {
            $this->logger->error("Can't create folder $folderPath: ".$e->getMessage());

            throw new Exception("Can\'t create folder $folderPath");
        }
    }//end createFolder()

    /**
     * Overwrites an existing file in NextCloud.
     *
     * @param string $filePath The path (from root) where to save the file, including filename and extension
     * @param mixed  $content  Optional content of the file. If null, only metadata like tags will be updated
     * @param array  $tags     Optional array of tags to attach to the file
     *
     * @throws Exception If the file doesn't exist or if file operations fail
     *
     * @return File The updated file
     */
    public function updateFile(string $filePath, mixed $content = null, array $tags = []): File
    {
        // @todo: this can update any file, we might want to check if the file is in the object folder first
        $filePath = trim(string: $filePath, characters: '/');

        try {
            $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

            // Check if file exists and delete it if it does.
            try {
                try {
                    $file = $userFolder->get(path: $filePath);

                    // If content is not null, update the file content
                    if ($content !== null) {
                        try {
                            $file->putContent(data: $content);
                        } catch (NotPermittedException $e) {
                            $this->logger->error("Can't write content to file: ".$e->getMessage());
                            throw new Exception("Can't write content to file: ".$e->getMessage());
                        }
                    }

                    $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);

                    return $file;
                } catch (NotFoundException $e) {
                    // File does not exist.
                    $this->logger->warning("File $filePath does not exist.");

                    throw new Exception("File $filePath does not exist");
                }
            } catch (NotPermittedException|InvalidPathException $e) {
                $this->logger->error("Can't update file $filePath: ".$e->getMessage());

                throw new Exception("Can't update file $filePath");
            }
        } catch (NotPermittedException $e) {
            $this->logger->error("Can't update file $filePath: ".$e->getMessage());

            throw new Exception("Can't update file $filePath");
        }
    }//end updateFile()

    /**
     * Constructs a file path for a specific object.
     *
     * @param string|ObjectEntity $object   The object entity or object UUID
     * @param string              $filePath The relative file path within the object folder
     *
     * @return string The complete file path
     */
    public function getObjectFilePath(string|ObjectEntity $object, string $filePath): string
    {
        return $object->getFolder().'/'.$filePath;
    }//end getObjectFilePath()

    /**
     * Deletes a file from NextCloud.
     *
     * @param string $filePath Path (from root) to the file you want to delete
     *
     * @throws Exception If deleting the file is not permitted
     *
     * @return bool True if successful, false if the file didn't exist
     */
    public function deleteFile(string $filePath): bool
    {
        // @todo: this can delete any file, we might want to check if the file is in the object folder first
        $filePath = trim(string: $filePath, characters: '/');

        try {
            $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

            // Check if file exists and delete it if it does.
            try {
                try {
                    $file = $userFolder->get(path: $filePath);
                    $file->delete();

                    return true;
                } catch (NotFoundException $e) {
                    // File does not exist.
                    $this->logger->warning("File $filePath does not exist.");

                    return false;
                }
            } catch (NotPermittedException|InvalidPathException $e) {
                $this->logger->error("Can't delete file $filePath: ".$e->getMessage());

                throw new Exception("Can't delete file $filePath");
            }
        } catch (NotPermittedException $e) {
            $this->logger->error("Can't delete file $filePath: ".$e->getMessage());

            throw new Exception("Can't delete file $filePath");
        }
    }//end deleteFile()

    /**
     * Attach tags to a file.
     *
     * @param string $fileId The file ID
     * @param array  $tags   Tags to associate with the file
     *
     * @return void
     */
    private function attachTagsToFile(string $fileId, array $tags = []): void
    {
        // Get all existing tags for the file and convert to array of just the IDs
        $oldTagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: $this::FILE_TAG_TYPE);
        if (isset($oldTagIds[$fileId]) === false || empty($oldTagIds[$fileId]) === true) {
            $oldTagIds = [];
        } else {
            $oldTagIds = $oldTagIds[$fileId];
        }

        // Create new tags if they don't exist
        $newTagIds = [];
        foreach ($tags as $key => $tagName) {
            // Skip empty tag names
            if (empty($tagName)) {
                continue;
            }

            try {
                $tag = $this->systemTagManager->getTag(tagName: $tagName, userVisible: true, userAssignable: true);
            } catch (Exception $exception) {
                $tag = $this->systemTagManager->createTag(tagName: $tagName, userVisible: true, userAssignable: true);
            }

            $newTagIds[] = $tag->getId();
        }

        // Only assign new tags if we have any
        if (empty($newTagIds) === false) {
            $this->systemTagMapper->assignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $newTagIds);
        }

        // Find tags that exist in old tags but not in new tags (tags to be removed)
        $tagsToRemove = array_diff($oldTagIds ?? [], $newTagIds ?? []);
        // Remove any keys with value 0 from tags to remove array
        $tagsToRemove = array_filter($tagsToRemove, function ($value) {
            return $value !== 0;
        });

        // Remove old tags that aren't in new tags
        if (empty($tagsToRemove) === false) {
            $this->systemTagMapper->unassignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $tagsToRemove);
        }

        // @todo Let's check if there are now existing tags without files (orphans) that need to be deleted
    }//end attachTagsToFile()

    /**
     * Adds a new file to an object's folder with the OpenCatalogi user as owner.
     *
     * @param ObjectEntity $objectEntity The object entity to add the file to
     * @param string       $fileName     The name of the file to create
     * @param string       $content      The content to write to the file
     * @param bool         $share        Whether to create a share link for the file
     * @param array        $tags         Optional array of tags to attach to the file
     *
     * @throws NotPermittedException If file creation fails due to permissions
     * @throws Exception If file creation fails for other reasons
     *
     * @return File The created file
     */
    public function addFile(ObjectEntity $objectEntity, string $fileName, string $content, bool $share = false, array $tags = []): File
    {
        try {
            // Create new file in the folder
            $folder = $this->getObjectFolder(
                objectEntity: $objectEntity,
                register: $objectEntity->getRegister(),
                schema: $objectEntity->getSchema()
            );

            /**
             * @var File $file
             */
            $file = $folder->newFile($fileName);

            // Write content to the file
            $file->putContent($content);

            // Create a share link for the file if requested
            if ($share === true) {
                $this->createShareLink(path: $file->getPath());
            }

            // Add tags to the file if provided
            if (empty($tags) === false) {
                $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
            }

            return $file;

        } catch (NotPermittedException $e) {
            $this->logger->error("Permission denied creating file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot create file $fileName: ".$e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error("Failed to create file $fileName: ".$e->getMessage());
            throw new \Exception("Failed to create file $fileName: ".$e->getMessage());
        }
    }//end addFile()

    /**
     * Retrieves all available tags in the system.
     *
     * This method fetches all tags that are visible and assignable by users
     * from the system tag manager.
     *
     * @throws \Exception If there's an error retrieving the tags
     *
     * @return array An array of tag names
     *
     * @psalm-return array<int, string>
     *
     * @phpstan-return array<int, string>
     */
    public function getAllTags(): array
    {
        try {
            // Get all tags that are visible and assignable by users
            $tags = $this->systemTagManager->getAllTags(visibilityFilter: true);

            // Extract just the tag names
            $tagNames = array_map(static function ($tag) {
                return $tag->getName();
            }, $tags);

            // Return sorted array of tag names
            sort($tagNames);
            return array_values($tagNames);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve tags: '.$e->getMessage());
            throw new \Exception('Failed to retrieve tags: '.$e->getMessage());
        }
    }//end getAllTags()

    /**
     * Get files for object
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param ObjectEntity|string $object The object or object ID to fetch files for
     *
     * @return Node[] The files found
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function getFiles(ObjectEntity|string $object): array
    {
        // If string ID provided, try to find the object entity
        if (is_string($object)) {
            $object = $this->objectEntityMapper->find($object);
        }

        $folder = $this->getObjectFolder(
            objectEntity: $object,
            register: $object->getRegister(),
            schema: $object->getSchema()
        );

        $files = [];
        if ($folder instanceof \OCP\Files\Folder === true) {
            $files = $folder->getDirectoryListing();
        }

        return $files;
    }
    
    /**
     * Get files for object
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
     *
     * @param ObjectEntity|string $object The object or object ID to fetch files for
     *
     * @return Node[] The files found
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function getFile(ObjectEntity|string $object, string $filePath): File
    {
        // If string ID provided, try to find the object entity
        if (is_string($object)) {
            $object = $this->objectEntityMapper->find($object);
        }

        $folder = $this->getObjectFolder(
            objectEntity: $object,
            register: $object->getRegister(),
            schema: $object->getSchema()
        );


		if ($folder instanceof Folder === true) {
			try {
				return $folder->get($filePath);
			} catch (NotFoundException $e) {
				return null;
			}
		}

        return $null;
    }

    

	/**
	 * Publish a file by creating a public share link
	 **
	 * @param ObjectEntity|string $object The object or object ID
	 * @param string $filePath Path to the file to publish
	 * @return \OCP\Files\File The published file
	 * @throws Exception If file publishing fails
	 */
	public function publishFile(ObjectEntity|string $object, string $filePath): \OCP\Files\File
	{
		// If string ID provided, try to find the object entity
		if (is_string($object)) {
			$object = $this->objectEntityMapper->find($object);
		}

		// Get the file node
		$fullPath = $this->getObjectFilePath($object, $filePath);
		$file = $this->getNode($fullPath);

		if (!$file instanceof \OCP\Files\File) {
			throw new Exception('File not found');
		}

		$shareLink = $this->createShareLink(path: $file->getPath());

		return $file;
	}

	/**
	 * Unpublish a file by removing its public share link
	 *
	 * @param ObjectEntity|string $object The object or object ID
	 * @param string $filePath Path to the file to unpublish
	 * @return \OCP\Files\File The unpublished file
	 * @throws Exception If file unpublishing fails
	 */
	public function unpublishFile(ObjectEntity|string $object, string $filePath): \OCP\Files\File
	{
		// If string ID provided, try to find the object entity
		if (is_string($object)) {
			$object = $this->objectEntityMapper->find($object);
		}

		// Get the file node
		$fullPath = $this->getObjectFilePath($object, $filePath);
		$file = $this->getNode($fullPath);


		if (!$file instanceof \OCP\Files\File) {
			throw new Exception('File not found');
		}

		$this->deleteShareLinks(file: $file);

		return $file;
	}

}//end class
