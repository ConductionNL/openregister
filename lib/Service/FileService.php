<?php

namespace OCA\OpenRegister\Service;

use Exception;
use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PhpOffice\PhpWord\IOFactory;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;
use Symfony\Component\Yaml\Yaml;
use thiagoalessio\TesseractOCR\TesseractOCR;
use ZipArchive;

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

	/**
	 * Constructor for FileService
	 *
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param IRootFolder $rootFolder The root folder interface
	 * @param IManager $shareManager The share manager interface
	 */
	public function __construct(
		private readonly IUserSession    $userSession,
		private readonly LoggerInterface $logger,
		private readonly IRootFolder     $rootFolder,
		private readonly IManager        $shareManager,
		private readonly IURLGenerator   $urlGenerator,
		private readonly IConfig         $config,
		private readonly RegisterMapper  $registerMapper,
		private readonly SchemaMapper    $schemaMapper,
        private readonly IGroupManager   $groupManager,
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

		if (str_ends_with(strtolower($title), 'register')) {
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
        if ($objectEntity->getFolder() === null) {
            $folderPath = $this->getObjectFolderPath(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );
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
	public function getFolderLink(string $folderPath): string
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
	 * Gets a NextCloud Node object for the given file or folder path.
	 *
	 * @param string $path A path to a file or folder in NextCloud.
	 *
	 * @return Node|null The Node object found for a file or folder. Or null if not found.
	 * @throws NotPermittedException When not allowed to get the user folder.
	 */
	private function getNode(string $path): ?Node
	{
		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

		try {
			return $userFolder->get(path: $path);
		} catch (NotFoundException $e) {
			$this->logger->error(message: $e->getMessage());
			return null;
		}
	}

	/**
	 * Perform a search on all files in a given folder / from a specific path. Find all files where content contains the search term.
	 *
	 * @param string $searchTerm The term to search for in file content.
	 * @param string|null $folderPath The folder to search files in.
	 *
	 * @return array The search results containing matching files.
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function search(string $searchTerm, string $folderPath = null): array {
		$results = [];

		// Get the current user.
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser ? $currentUser->getUID() : 'Guest');

		try {
			$rootFolder = $userFolder->get(self::ROOT_FOLDER);
		} catch(NotFoundException $exception) {
			$rootFolder = $userFolder->newFolder(self::ROOT_FOLDER);
		}

		$searchFolder = $rootFolder;
		if (empty($folderPath) === false) {
			try {
				$searchFolder = $userFolder->get($folderPath);
			} catch(NotFoundException $exception) {
				$this->logger->error(message: 'Could not find this folder to search in: ' . $folderPath);
			}
		}

		try {
			// Recursively search files
			$this->searchFilesContext(folder: $searchFolder, searchTerm: $searchTerm, results: $results);
		} catch (Exception $e) {
			$this->logger->error(message: 'Error during search: ' . $e->getMessage());
		}

		return $this->formatFiles($results);
	}

	/**
	 * Recursively search for files in a folder where the content of the file contain the search term.
	 *
	 * @param Folder $folder The folder to search within.
	 * @param string $searchTerm The term to search for in file content.
	 * @param array &$results The array to store search results.
	 *
	 * @return void
	 * @throws NotFoundException
	 */
	private function searchFilesContext(Folder $folder, string $searchTerm, array &$results): void
	{
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				try {
					$content = $this->decodeFileContent($node);
				} catch (Exception $e) {
					$this->logger->error(message: 'searchFilesByContext error: ' . $e->getMessage());
					continue;
				}

				// Check if the file content contains the search term
				if (str_contains(strtolower($content), strtolower($searchTerm))) {
					$results[] = $node;
				}
			} elseif ($node instanceof Folder) {
				// Recursively search subfolders
				$this->searchFilesContext(folder: $node, searchTerm: $searchTerm, results: $results);
			}
		}
	}

	/**
	 * Decodes the content of a file based on its extension and returns the extracted or formatted text.
	 *
	 * This function supports the following file types:
	 * - DOC/DOCX: Extracts text content using the PhpOffice\PhpWord\IOFactory.
	 * - PDF: Extracts text content using the Smalot\PdfParser\Parser.
	 * - JSON: Decodes the JSON content into an associative array and re-encodes it with pretty printing.
	 * - YAML: Parses the YAML content and converts it back to a YAML string if valid.
	 *
	 * If the file type is unsupported, an error is logged, and the function returns null.
	 *
	 * @param File $file The file whose content needs to be decoded.
	 *
	 * @return string|null The decoded content of the file as a string, or null if decoding fails
	 *                     or the file type is unsupported.
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws NotPermittedException
	 */
	private function decodeFileContent(File $file): ?string
	{
		$content = $file->getContent();

		switch (strtolower($file->getExtension())) {
			case 'txt':
			case 'md':
			case 'log':
				return $content;
			case 'html':
			case 'htm':
				return strip_tags($content);
			case 'docx':
			case 'doc':
				return $this->decodeWordFileContent($content);
			case 'xlsx':
			case 'xls':
				return $this->decodeExcelFileContent($content);
			case 'csv':
				$rows = array_map('str_getcsv', explode(PHP_EOL, $content));
				return json_encode($rows, JSON_PRETTY_PRINT);
			case 'pdf':
				$parser = new Parser();
				$pdf = $parser->parseContent($content);
				return $pdf->getText();
			case 'json':
				$data = json_decode($content, true);
				return json_last_error() === JSON_ERROR_NONE ? json_encode($data, JSON_PRETTY_PRINT) : '';
			case 'yaml':
				$data = Yaml::parse($content);
				return is_array($data) ? Yaml::dump($data) : '';
			case 'xml':
				$xml = simplexml_load_string($content);
				return $xml ? $xml->asXML() : '';
			case 'zip':
				return $this->decodeArchiveContent($content);
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'gif':
				return $this->decodeImageContent($content);
			default:
				$this->logger->error(message: 'Unsupported file extension/type cannot decode it\'s content');
				return null;
		}
	}

	/**
	 * Decodes the content of a Word file and extracts the text from it.
	 *
	 * This function processes the binary content of a Word document (e.g., .docx or .doc),
	 * saves it temporarily to the file system, and uses `PhpOffice\PhpWord\IOFactory`
	 * to load and parse the file. The text content is extracted from the document's sections
	 * and elements and returned as a plain string.
	 *
	 * @param string $content The binary content of the Word file.
	 *
	 * @return string The extracted text content of the Word file.
	 */
	private function decodeWordFileContent(string $content): string
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'word_');
		file_put_contents($tempFile, $content);

		$phpWord = IOFactory::load($tempFile);
		unlink($tempFile);

		$text = '';
		foreach ($phpWord->getSections() as $section) {
			foreach ($section->getElements() as $element) {
				if (method_exists($element, 'getText')) {
					$text .= $element->getText() . "\n";
				}
			}
		}

		return $text;
	}

	/**
	 * Decodes the content of an Excel file and extracts its data as a JSON string.
	 *
	 * This function processes the binary content of an Excel file (e.g., `.xlsx` or `.xls`)
	 * by saving it temporarily to the file system, loading it using `PhpOffice\PhpSpreadsheet`,
	 * and converting the active sheet's data into a structured array. The data is then encoded
	 * as a pretty-printed JSON string.
	 *
	 * @param string $content The binary content of the Excel file.
	 *
	 * @return string The extracted sheet data in JSON format, with cell references as keys.
	 * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception If the file cannot be read or parsed.
	 */
	private function decodeExcelFileContent(string $content): string
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'excel_');
		file_put_contents($tempFile, $content);

		$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempFile);
		unlink($tempFile);

		$sheetData = $spreadsheet->getActiveSheet()->toArray(returnCellRef: true);
		return json_encode($sheetData, JSON_PRETTY_PRINT);
	}

	/**
	 * Decodes the content of a ZIP archive and extracts a list of contained files.
	 *
	 * This function processes the binary content of a ZIP archive by temporarily saving it to the
	 * file system, opening it with PHP's `ZipArchive`, and retrieving the names of all files within
	 * the archive. The extracted file list is returned as a pretty-printed JSON string.
	 *
	 * @param string $content The binary content of the ZIP archive.
	 *
	 * @return string A JSON-encoded array containing the names of files in the archive.
	 * @throws Exception If the archive cannot be opened or processed.
	 */
	private function decodeArchiveContent(string $content): string
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'zip_');
		file_put_contents($tempFile, $content);

		$zip = new ZipArchive();
		if ($zip->open($tempFile) === true) {
			$fileList = [];
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$fileList[] = $zip->getNameIndex($i);
			}
			$zip->close();
		}
		unlink($tempFile);

		return json_encode($fileList, JSON_PRETTY_PRINT);
	}

	/**
	 * Decodes the content of an image file and extracts its metadata.
	 *
	 * This function processes the binary content of an image file (e.g., `.jpg`, `.png`, `.gif`)
	 * by temporarily saving it to the file system, reading its metadata using PHP's `exif_read_data`,
	 * and returning the metadata as a pretty-printed JSON string. If no metadata is found, a
	 * default message is returned.
	 *
	 * @param string $content The binary content of the image file.
	 *
	 * @return string A JSON-encoded string containing the image's metadata, or a message indicating
	 *                that no metadata was found.
	 * @throws Exception If the file cannot be processed or metadata extraction fails.
	 */
	private function decodeImageContent(string $content): string
	{
		// Save the image temporarily
		$tempFile = tempnam(sys_get_temp_dir(), 'img_');
		file_put_contents($tempFile, $content);

		// Use Tesseract OCR to extract text
		$text = (new TesseractOCR($tempFile))
			->lang('nld') // Set language to Dutch ('nld')
			->run();

		// Remove temp file
		unlink($tempFile);

		return $text ?: 'No text detected';
	}

	/**
	 * Formats an array of Node files into an array of metadata arrays.
	 *
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class
	 * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass
	 *
	 * @param Node[] $files Array of Node files to format
	 *
	 * @return array Array of formatted file metadata arrays
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function formatFiles(array $files): array
	{
		$formattedFiles = [];

		foreach($files as $file) {
			// IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare
			$shares = $this->findShares($file);

			$formattedFile = [
				'id'          => $file->getId(),
				'path' 		  => $file->getPath(),
				'title'  	  => $file->getName(),
				'accessUrl'   => count($shares) > 0 ? $this->getShareLink($shares[0]) : null,
				'downloadUrl' => count($shares) > 0 ? $this->getShareLink($shares[0]).'/download' : null,
				'type'  	  => $file->getMimetype(),
				'extension'   => $file->getExtension(),
				'size'		  => $file->getSize(),
				'hash'		  => $file->getEtag(),
				'published'   => (new DateTime())->setTimestamp($file->getCreationTime())->format('c'),
				'modified'    => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
			];

			$formattedFiles[] = $formattedFile;
		}

		return $formattedFiles;
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

		return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file);
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
		$share->setSharedBy(sharedBy: $shareData['userId']);
		$share->setShareOwner(shareOwner: $shareData['userId']);
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
