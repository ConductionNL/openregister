<?php

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Service for handling file operations in OpenRegister.
 *
 * This service provides functionalities for managing files and folders within the NextCloud environment,
 * including creation, deletion, sharing, and file updates. It integrates with NextCloud's file and
 * sharing APIs to provide seamless file management for the application.
 */
class FileService
{
	const ROOT_FOLDER = 'Open Registers';
    const APP_GROUP = 'openregister';
    const APP_USER = 'OpenRegister';
    const FILE_TAG_TYPE = 'files';
	/**
	 * Constructor for FileService
	 *
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param IRootFolder $rootFolder The root folder interface
	 * @param IManager $shareManager The share manager interface
	 */
	public function __construct(
		private readonly IUserSession    		$userSession,
		private readonly IUserManager    		$userManager,
		private readonly LoggerInterface 		$logger,
		private readonly IRootFolder     		$rootFolder,
		private readonly IManager        		$shareManager,
		private readonly IURLGenerator   		$urlGenerator,
		private readonly IConfig         		$config,
		private readonly RegisterMapper  		$registerMapper,
		private readonly SchemaMapper    		$schemaMapper,
        private readonly IGroupManager   		$groupManager,
        private readonly ISystemTagManager      $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
	)
	{
	}

	/**
	 * Creates a folder for a Register (used for storing files of Schemas/Objects).
	 *
	 * @param Register|int $register The Register to create the folder for.
	 *
	 * @return string The path to the folder.
	 * @throws Exception In case we can't create the folder because it is not permitted.
	 */
	public function createRegisterFolder(Register|int $register): string
	{
		if (is_int($register) === true) {
			$register = $this->registerMapper->find($register);
		}

		$registerFolderName = $this->getRegisterFolderName($register);
		// @todo maybe we want to use ShareLink here for register->folder as well?
		$register->setFolder($this::ROOT_FOLDER . "/$registerFolderName");
		$this->registerMapper->update($register);

		$folderPath = $this::ROOT_FOLDER . "/$registerFolderName";
		$this->createFolder(folderPath: $folderPath);

		return $folderPath;
	}

	/**
	 * Get the name for the folder of a Register (used for storing files of Schemas/Objects).
	 *
	 * @param Register $register The Register to get the folder name for.
	 *
	 * @return string The name the folder for this Register should have.
	 */
	private function getRegisterFolderName(Register $register): string
	{
		$title = $register->getTitle();

		if (str_ends_with(haystack: strtolower(rtrim($title)), needle: 'register')) {
			return $title;
		}

		return "$title Register";
	}

	/**
	 * Creates a folder for a Schema (used for storing files of Objects).
	 *
	 * @param Register|int $register The Register to create the schema folder for.
	 * @param Schema|int $schema The Schema to create the folder for.
	 *
	 * @return string The path to the folder.
	 * @throws Exception In case we can't create the folder because it is not permitted.
	 */
	public function createSchemaFolder(Register|int $register, Schema|int $schema): string
	{
		if (is_int($register) === true) {
			$register = $this->registerMapper->find($register);
		}

		if (is_int($schema) === true) {
			$schema = $this->schemaMapper->find($schema);
		}
		// @todo we could check here if Register contains/has Schema else throw Exception.

		$registerFolderName = $this->getRegisterFolderName($register);
		// @todo maybe we want to use ShareLink here for register->folder as well?
		$register->setFolder($this::ROOT_FOLDER . "/$registerFolderName");
		$this->registerMapper->update($register);

		$schemaFolderName = $this->getSchemaFolderName($schema);

		$folderPath = $this::ROOT_FOLDER . "/$registerFolderName/$schemaFolderName";
		$this->createFolder(folderPath: $folderPath);

		return $folderPath;
	}

	/**
	 * Get the name for the folder used for storing files of objects of a specific Schema.
	 *
	 * @param Schema $schema The Schema to get the folder name for.
	 *
	 * @return string The name the folder for this Schema should have.
	 */
	private function getSchemaFolderName(Schema $schema): string
	{
		return $schema->getTitle();
	}

	/**
	 * Creates a folder for an Object (used for storing files of this Object).
	 *
	 * @param ObjectEntity $objectEntity The Object to create the folder for.
	 * @param Register|int|null $register The Register to create the Object folder for.
	 * @param Schema|int|null $schema The Schema to create the Object folder for.
	 *
	 * @return Node|null The NextCloud Node object of the folder. Or null if something went wrong creating the folder.
	 * @throws Exception In case we can't create the folder because it is not permitted.
	 */
	public function createObjectFolder(
		ObjectEntity      $objectEntity,
		Register|int|null $register = null,
		Schema|int|null   $schema = null,
		string            $folderPath = null
	): ?Node
	{
		if ($folderPath === null) {
			$folderPath = $this->getObjectFolderPath(objectEntity: $objectEntity, register: $register, schema: $schema);
		}
		$this->createFolder(folderPath: $folderPath);

		// @todo Do we want to use ShareLink here?
		// @todo ^If so, we need to update these functions to be able to create shareLinks for folders as well (not only files)
		$objectEntity->setFolder($folderPath);

//		// Create or find ShareLink
//		$share = $this->fileService->findShare(path: $filePath);
//		if ($share !== null) {
//			$shareLink = $this->fileService->getShareLink($share);
//		} else {
//			$shareLink = $this->fileService->createShareLink(path: $filePath);
//		}

		return $this->getNode($folderPath);
	}

	/**
	 * Gets the NextCloud Node object for the folder of an Object.
	 *
	 * @param ObjectEntity $objectEntity The Object to get the folder for.
	 * @param Register|int|null $register The Register to get the Object folder for.
	 * @param Schema|int|null $schema The Schema to get the Object folder for.
	 *
	 * @return Node|null The NextCloud Node object of the folder. Or null if something went wrong getting / creating the folder.
	 * @throws Exception In case we can't create the folder because it is not permitted.
	 */
	public function getObjectFolder(
		ObjectEntity      $objectEntity,
		Register|int|null $register = null,
		Schema|int|null   $schema = null
	): ?Node
	{
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
	}

	/**
	 * Gets the path to the folder of an object.
	 *
	 * @param ObjectEntity $objectEntity The Object to get the folder path for.
	 * @param Register|int|null $register The Register to get the Object folder path for (must match Object->register).
	 * @param Schema|int|null $schema The Schema to get the Object folder path for (must match Object->schema).
	 *
	 * @return string The path to the folder.
	 * @throws Exception If something went wrong getting the path, a mismatch in object register/schema & function parameters register/schema for example.
	 */
	private function getObjectFolderPath(
		ObjectEntity      $objectEntity,
		Register|int|null $register = null,
		Schema|int|null   $schema = null
	): string
	{
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
		$register->setFolder($this::ROOT_FOLDER . "/$registerFolderName");
		$this->registerMapper->update($register);

		$schemaFolderName = $this->getSchemaFolderName($schema);
		$objectFolderName = $this->getObjectFolderName($objectEntity);

		return $this::ROOT_FOLDER . "/$registerFolderName/$schemaFolderName/$objectFolderName";
	}

	/**
	 * Get the name for the folder used for storing files of the given object.
	 *
	 * @param ObjectEntity $objectEntity The Object to get the folder name for.
	 *
	 * @return string The name the folder for this object should have.
	 */
	private function getObjectFolderName(ObjectEntity $objectEntity): string
	{
		// @todo check if property Title or Name exists and use that as object title
		$objectTitle = 'object';

//		return "{$objectEntity->getUuid()} ($objectTitle)";
		return $objectEntity->getUuid();
	}

	/**
	 * Returns a link to the given folder path.
	 *
	 * @param string $folderPath The path to a folder in NextCloud.
	 *
	 * @return string The share link needed to get the file or folder for the given IShare object.
	 */
	private function getFolderLink(string $folderPath): string
	{
		$folderPath = str_replace('%2F', '/', urlencode($folderPath));
		return $this->getCurrentDomain() . "/index.php/apps/files/files?dir=$folderPath";
	}

	/**
	 * Returns a share link for the given IShare object.
	 *
	 * @param IShare $share An IShare object we are getting the share link for.
	 *
	 * @return string The share link needed to get the file or folder for the given IShare object.
	 */
	public function getShareLink(IShare $share): string
	{
		return $this->getCurrentDomain() . '/index.php/s/' . $share->getToken();
	}

	/**
	 * Gets and returns the current host / domain with correct protocol.
	 *
	 * @return string The current http/https domain url.
	 */
	private function getCurrentDomain(): string
	{
		$baseUrl = $this->urlGenerator->getBaseUrl();
		$trustedDomains = $this->config->getSystemValue('trusted_domains');

		if (isset($trustedDomains[1]) === true) {
			$baseUrl = str_replace(search: 'localhost', replace: $trustedDomains[1], subject: $baseUrl);
		}

		return $baseUrl;
	}
	/**
	 * Gets or creates the OpenCatalogi user for file operations
	 *
	 * @return IUser The OpenCatalogi user
	 * @throws Exception If OpenCatalogi user cannot be created
	 */
	private function getUser(): IUser
	{
		$openCatalogiUser = $this->userManager->get(self::APP_USER);

		if (!$openCatalogiUser) {
			// Create OpenCatalogi user if it doesn't exist
			$password = bin2hex(random_bytes(16)); // Generate random password
			$openCatalogiUser = $this->userManager->createUser(self::APP_USER, $password);

			if (!$openCatalogiUser) {
				throw new \Exception('Failed to create OpenCatalogi user account.');
			}

			// Add user to OpenCatalogi group
			$group = $this->groupManager->get(self::APP_GROUP);
			if (!$group) {
				$group = $this->groupManager->createGroup(self::APP_GROUP);
			}
			$group->addUser($openCatalogiUser);
		}

		return $openCatalogiUser;
	}

	/**
	 * Gets a NextCloud Node object for the given file or folder path.
	 */
	private function getNode(string $path): ?Node
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());
			return $userFolder->get(path: $path);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->error(message: $e->getMessage());
			return null;
		}
	}

	/**
	 * Formats a single Node file into a metadata array.
	 *
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
	 *
	 * @param Node $file The Node file to format
	 * @return array The formatted file metadata array
	 */
	public function formatFile(Node $file): array
	{
		// IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare
		$shares = $this->findShares($file);

		// Get base metadata array
		$metadata = [
			'id'          => $file->getId(),
			'path'        => $file->getPath(),
			'title'       => $file->getName(),
			'accessUrl'   => count($shares) > 0 ? $this->getShareLink($shares[0]) : null,
			'downloadUrl' => count($shares) > 0 ? $this->getShareLink($shares[0]).'/download' : null,
			'type'        => $file->getMimetype(),
			'extension'   => $file->getExtension(),
			'size'        => $file->getSize(),
			'hash'        => $file->getEtag(),
			'published'   => count($shares) > 0 ? $shares[0]->getShareTime()->format('c') : null,
			'modified'    => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
			'labels'      => $this->getFileTags(fileId: $file->getId())
		];

		// Process labels that contain ':' to add as separate metadata fields
		$remainingLabels = [];
		foreach ($metadata['labels'] as $label) {
			if (strpos($label, ':') !== false) {
				list($key, $value) = explode(':', $label, 2);
				$key = trim($key);
				$value = trim($value);

				// Skip if key exists in base metadata
				if (isset($metadata[$key])) {
					$remainingLabels[] = $label;
					continue;
				}

				// If key already exists as array, append value
				if (isset($metadata[$key]) && is_array($metadata[$key])) {
					$metadata[$key][] = $value;
				}
				// If key exists but not as array, convert to array with both values
				elseif (isset($metadata[$key])) {
					$metadata[$key] = [$metadata[$key], $value];
				}
				// If key doesn't exist, create new entry
				else {
					$metadata[$key] = $value;
				}
			} else {
				$remainingLabels[] = $label;
			}
		}

		// Update labels array to only contain non-processed labels
		$metadata['labels'] = $remainingLabels;

		return $metadata;
	}

	/**
	 * Formats an array of Node files into an array of metadata arrays.
	 *
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
	 *
	 * @param Node[] $files Array of Node files to format
	 * @param array $requestParams Optional request parameters
	 *
	 * @return array Array of formatted file metadata arrays
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function formatFiles(array $files, ?array $requestParams = []): array
	{
		
		// Extract specific parameters
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

		// Ensure order and extend are arrays
		if (is_string($order) === true) {
			$order = array_map('trim', explode(',', $order));
		}
		if (is_string($extend) === true) {
			$extend = array_map('trim', explode(',', $extend));
		}

		// Remove unnecessary parameters from filters
		$filters = $requestParams;
		unset($filters['_route']); // TODO: Investigate why this is here and if it's needed
		unset($filters['_extend'], $filters['_limit'], $filters['_offset'], $filters['_order'], $filters['_page'], $filters['_search']);
		unset($filters['extend'], $filters['limit'], $filters['offset'], $filters['order'], $filters['page']);

		$formattedFiles = [];

		// Apply offset and limit to files array if specified
		$files = array_slice($files, $offset, $limit);

		foreach($files as $file) {
			$formattedFiles[] = $this->formatFile($file);
		}

		// @todo search
		$total   = count($formattedFiles);
		$pages   = $limit !== null ? ceil($total/$limit) : 1;

		return [
			'results' => $formattedFiles,			
			'total' => $total,
			'page' => $page ?? 1,
			'pages' => $pages,
		];
	}

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
	}

	/**
	 * @param Node $file
	 * @param int $shareType
	 * @return IShare[]
	 */
	public function findShares(Node $file, int $shareType = 3): array
	{
		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userId = $currentUser ? $currentUser->getUID() : 'Guest';

		return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
	}

	/**
	 * Try to find a IShare object with given $path & $shareType.
	 *
	 * @param string $path The path to a file we are trying to find a IShare object for.
	 * @param int|null $shareType The shareType of the share we are trying to find.
	 *
	 * @return IShare|null An IShare object or null.
	 */
	public function findShare(string $path, ?int $shareType = 3): ?IShare
	{
		$path = trim(string: $path, characters: '/');
		$userId = $this->getUser()->getUID();

		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: $userId);
		} catch (NotPermittedException) {
			$this->logger->error("Can't find share for $path because user (folder) for user $userId couldn't be found");
			return null;
		}

		try {
			// Note: if we ever want to find shares for folders instead of files, this should work for folders as well?
			$file = $userFolder->get(path: $path);
		} catch (NotFoundException $e) {
			$this->logger->error("Can't find share for $path because file doesn't exist");

			return null;
		}

		if ($file instanceof File) {
			$shares = $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
			if (count($shares) > 0) {
				return $shares[0];
			}
		}

		return null;
	}

	/**
	 * Creates a IShare object using the $shareData array data.
	 *
	 * @param array $shareData The data to create a IShare with, should contain 'path', 'file', 'shareType', 'permissions' and 'userid'.
	 *
	 * @return IShare The Created IShare object.
	 * @throws Exception
	 */
	private function createShare(array $shareData): IShare
	{
		$userId = $this->getUser()->getUID();

		// Create a new share
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
	}

	/**
	 * Creates and returns a share link for a file (or folder).
	 * (https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html#create-a-new-share)
	 *
	 * @param string $path Path (from root) to the file/folder which should be shared.
	 * @param int|null $shareType 0 = user; 1 = group; 3 = public link; 4 = email; 6 = federated cloud share; 7 = circle; 10 = Talk conversation
	 * @param int|null $permissions 1 = read; 2 = update; 4 = create; 8 = delete; 16 = share; 31 = all (default: 31, for public shares: 1)
	 *
	 * @return string The share link.
	 * @throws Exception In case creating the share(link) fails.
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
			$this->logger->error("Can't create share link for $path because user (folder) for user $userId couldn't be found");
			return "User (folder) couldn't be found";
		}

		try {
            $file = $this->rootFolder->get($path);
//			$file = $userFolder->get(path: $path);
		} catch (NotFoundException $e) {
			$this->logger->error("Can't create share link for $path because file doesn't exist");

			return 'File not found at '.$path;
		}

		try {
			$share = $this->createShare([
				'path' => $path,
				'file' => $file,
				'shareType' => $shareType,
				'permissions' => $permissions,
				'userId' => $userId
			]);
			return $this->getShareLink($share);
		} catch (Exception $exception) {
			$this->logger->error("Can't create share link for $path: " . $exception->getMessage());

			throw new Exception('Can\'t create share link');
		}
	}

	/**
	 * Creates a new folder in NextCloud, unless it already exists.
	 *
	 * @param string $folderPath Path (from root) to where you want to create a folder, include the name of the folder. (/Media/exampleFolder)
	 *
	 * @return bool True if successfully created a new folder.
	 * @throws Exception In case we can't create the folder because it is not permitted.
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
			} catch(NotFoundException $exception) {
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
					'userId' => $this->userSession->getUser()->getUID(),
					'sharedWith' => self::APP_GROUP
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
			$this->logger->error("Can't create folder $folderPath: " . $e->getMessage());

			throw new Exception("Can\'t create folder $folderPath");
		}
	}

	/**
	 * Overwrites an existing file in NextCloud.
	 *
	 * @param mixed $content The content of the file.
	 * @param string $filePath Path (from root) where to save the file. NOTE: this should include the name and extension/format of the file as well! (example.pdf)
	 * @param bool $createNew Default = false. If set to true this function will create a new file if it doesn't exist yet.
	 * @param array $tags Optional array of tags to attach to the file.
	 * @return bool True if successful.
	 * @throws Exception In case we can't write to file because it is not permitted.
	 */
	public function updateFile(mixed $content, string $filePath, bool $createNew = false, array $tags = []): bool
	{
		$filePath = trim(string: $filePath, characters: '/');

		try {
			$userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

			// Check if file exists and overwrite it if it does.
			try {
				try {
					$file = $userFolder->get(path: $filePath);

					$file->putContent(data: $content);

					// Add tags to the file if provided
					if (empty($tags) === false) {
						$this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
					}

					return true;
				} catch (NotFoundException $e) {
					if ($createNew === true) {
						$userFolder->newFile(path: $filePath);
						$file = $userFolder->get(path: $filePath);

						$file->putContent(data: $content);

						// Add tags to the file if provided
						if (empty($tags) === false) {
							$this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
						}

						$this->logger->info("File $filePath did not exist, created a new file for it.");
						return true;
					}
				}

				// File already exists.
				$this->logger->warning("File $filePath already exists.");
				return false;

			} catch (NotPermittedException|GenericFileException|LockedException $e) {
				$this->logger->error("Can't create file $filePath: " . $e->getMessage());

				throw new Exception("Can't write to file $filePath");
			}
		} catch (NotPermittedException $e) {
			$this->logger->error("Can't create file $filePath: " . $e->getMessage());

			throw new Exception("Can't write to file $filePath");
		}
	}

	/**
	 * Deletes a file from NextCloud.
	 *
	 * @param string $filePath Path (from root) to the file you want to delete.
	 *
	 * @return bool True if successful.
	 * @throws Exception In case deleting the file is not permitted.
	 */
	public function deleteFile(string $filePath): bool
	{
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
				$this->logger->error("Can't delete file $filePath: " . $e->getMessage());

				throw new Exception("Can't delete file $filePath");
			}
		} catch (NotPermittedException $e) {
			$this->logger->error("Can't delete file $filePath: " . $e->getMessage());

			throw new Exception("Can't delete file $filePath");
		}
	}



	/**
	 * Attach tags to a file.
	 *
	 * @param string $fileId The fileId.
	 * @param array $tags Tags to associate with the file.
	 */
	private function attachTagsToFile(string $fileId, array $tags): void
	{
        $tagIds = [];
		foreach ($tags as $key => $tagName) {
            try {
                $tag = $this->systemTagManager->getTag(tagName: $tagName, userVisible: true, userAssignable: true);
            } catch (TagNotFoundException $exception) {
                $tag = $this->systemTagManager->createTag(tagName: $tagName, userVisible: true, userAssignable: true);
            }

            $tagIds[] = $tag->getId();
		}

        $this->systemTagMapper->assignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $tagIds);
	}


	/**
	 * Adds a new file to an object's folder with the OpenCatalogi user as owner
	 *
	 * @param ObjectEntity $objectEntity The object entity to add the file to
	 * @param string $fileName The name of the file to create
	 * @param string $content The content to write to the file
	 * @param bool $share Whether to create a share link for the file
	 * @param array $tags Optional array of tags to attach to the file
	 *
	 * @return File The created file
	 * @throws NotPermittedException If file creation fails due to permissions
	 * @throws Exception If file creation fails for other reasons
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

			// Set the OpenCatalogi user as the current user
			$currentUser = $this->userSession->getUser();
			$this->userSession->setUser($this->getUser());

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

			$this->userSession->setUser($currentUser);

			return $file;

		} catch (NotPermittedException $e) {
			$this->logger->error("Permission denied creating file $fileName: " . $e->getMessage());
			throw new NotPermittedException("Cannot create file $fileName: " . $e->getMessage());
		} catch (\Exception $e) {
			$this->logger->error("Failed to create file $fileName: " . $e->getMessage());
			throw new \Exception("Failed to create file $fileName: " . $e->getMessage());
		}
	}
}
