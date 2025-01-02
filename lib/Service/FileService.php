<?php

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use OCP\Share\IShare;
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
	 * Constructor for FileService
	 *
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param IRootFolder $rootFolder The root folder interface
	 * @param IManager $shareManager The share manager interface
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder $rootFolder,
		private readonly IManager $shareManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
	) {}

	/**
	 * Get the name for the folder used for storing files of objects of a specific Schema.
	 *
	 * @param Schema $schema The Schema to get the folder name for.
	 *
	 * @return string The name the folder for this Schema should have.
	 */
	public function getSchemaFolderName(Schema $schema): string
	{
		return "({$schema->getUuid()}) {$schema->getTitle()}";
	}

	/**
	 * Get the name for the folder used for storing files of the given object.
	 *
	 * @param ObjectEntity $objectEntity The Object to get the folder name for.
	 *
	 * @return string The name the folder for this object should have.
	 */
	public function getObjectFolderName(ObjectEntity $objectEntity): string
	{
		// @todo check if property Title or Name exists and use that as object title
		$objectTitle = 'object';

		return "({$objectEntity->getUuid()}) $objectTitle";
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

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userId = $currentUser ? $currentUser->getUID() : 'Guest';
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
			$shares = $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file);
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
	private function createShare(array $shareData) :IShare
	{
		// Create a new share
		$share = $this->shareManager->newShare();
		$share->setTarget(target: '/'.$shareData['path']);
		$share->setNodeId(fileId: $shareData['file']->getId());
		$share->setNodeType(type: 'file');
		$share->setShareType(shareType: $shareData['shareType']);
		if ($shareData['permissions'] !== null) {
			$share->setPermissions(permissions: $shareData['permissions']);
		}
		$share->setSharedBy(sharedBy: $shareData['userId']);
		$share->setShareOwner(shareOwner: $shareData['userId']);
		$share->setShareTime(shareTime: new DateTime());
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

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userId = $currentUser ? $currentUser->getUID() : 'Guest';
		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: $userId);
		} catch (NotPermittedException) {
			$this->logger->error("Can't create share link for $path because user (folder) for user $userId couldn't be found");

			return "User (folder) couldn't be found";
		}

		try {
			// Note: if we ever want to create share links for folders instead of files, just remove this try catch and only use setTarget, not setNodeId.
			$file = $userFolder->get(path: $path);
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
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

		// Check if folder exists and if not create it.
		try {
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
	 * Uploads a file to NextCloud. Will create a new file if it doesn't exist yet.
	 *
	 * @param mixed $content The content of the file.
	 * @param string $filePath Path (from root) where to save the file. NOTE: this should include the name and extension/format of the file as well! (example.pdf)
	 *
	 * @return bool True if successful.
	 * @throws Exception In case we can't write to file because it is not permitted.
	 */
	public function uploadFile(mixed $content, string $filePath): bool
	{
		$filePath = trim(string: $filePath, characters: '/');

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

		// Check if file exists and create it if not.
		try {
			try {
				$userFolder->get(path: $filePath);
			} catch (NotFoundException $e) {
				$userFolder->newFile(path: $filePath);
				$file = $userFolder->get(path: $filePath);

				$file->putContent(data: $content);

				return true;
			}

			// File already exists.
			$this->logger->warning("File $filePath already exists.");
			return false;

		} catch (NotPermittedException|GenericFileException|LockedException $e) {
			$this->logger->error("Can't create file $filePath: " . $e->getMessage());

			throw new Exception("Can't write to file $filePath");
		}
	}

	/**
	 * Overwrites an existing file in NextCloud.
	 *
	 * @param mixed $content The content of the file.
	 * @param string $filePath Path (from root) where to save the file. NOTE: this should include the name and extension/format of the file as well! (example.pdf)
	 * @param bool $createNew Default = false. If set to true this function will create a new file if it doesn't exist yet.
	 *
	 * @return bool True if successful.
	 * @throws Exception In case we can't write to file because it is not permitted.
	 */
	public function updateFile(mixed $content, string $filePath, bool $createNew = false): bool
	{
		$filePath = trim(string: $filePath, characters: '/');

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

		// Check if file exists and overwrite it if it does.
		try {
			try {
				$file = $userFolder->get(path: $filePath);

				$file->putContent(data: $content);

				return true;
			} catch (NotFoundException $e) {
				if ($createNew === true) {
					$userFolder->newFile(path: $filePath);
					$file = $userFolder->get(path: $filePath);

					$file->putContent(data: $content);

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

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

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
	}

}
