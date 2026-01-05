<?php

/**
 * OpenRegister Download Service
 *
 * Service class for handling download operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - Downloading objects as files.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use InvalidArgumentException;
use JetBrains\PhpStorm\NoReturn;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;

/**
 * DownloadService handles download requests for database entities
 *
 * Service for handling download requests for database entities.
 * This service enables downloading database entities as files in various formats,
 * determined by the `Accept` header of the request. It retrieves the appropriate
 * data from mappers and generates responses or downloadable files.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class DownloadService
{

    /**
     * Register mapper
     *
     * Handles database operations for register entities.
     *
     * @var RegisterMapper Register mapper instance
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * Handles database operations for schema entities.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Generate a downloadable JSON file response
     *
     * Creates a temporary JSON file from provided data and sends it as a download
     * to the client. Sets appropriate HTTP headers for file download and cleans
     * up temporary file after sending.
     *
     * @param string $jsonData The JSON data to create a JSON file with
     * @param string $filename The base filename (without extension).
     *                         .json extension will be added automatically
     *
     * @return never This method exits script execution after sending file
     *
     * @NoReturn
     *
     * @SuppressWarnings(PHPMD.ExitExpression)      Exit is intentional for file download.
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future download functionality
     */
    private function downloadJson(string $jsonData, string $filename): never
    {
        // Step 1: Define the file name and path for the temporary JSON file.
        // Uses system temporary directory for file storage.
        $fileName = $filename.'.json';
        $filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$fileName;

        // Step 2: Create and write the JSON data to the temporary file.
        file_put_contents($filePath, $jsonData);

        // Step 3: Set HTTP headers to trigger file download.
        // Content-Type tells browser this is JSON data.
        // Content-Disposition tells browser to download file with specified name.
        // Content-Length specifies file size for download progress.
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="'.$fileName.'"');
        header('Content-Length: '.filesize($filePath));

        // Step 4: Output the file contents to client.
        readfile($filePath);

        // Step 5: Clean up temporary file after sending.
        unlink($filePath);

        // Step 6: Exit script execution to prevent further output.
        exit;
    }//end downloadJson()

    /**
     * Gets the appropriate mapper based on the object type
     *
     * Returns the correct mapper instance for the specified object type.
     * Used to route download requests to the appropriate data source.
     *
     * @param string $objectType The type of object to retrieve the mapper for
     *                           (e.g., 'schema', 'register')
     *
     * @return RegisterMapper|SchemaMapper The appropriate mapper instance
     *
     * @throws InvalidArgumentException If an unknown object type is provided
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future download functionality
     */
    private function getMapper(string $objectType): RegisterMapper|SchemaMapper
    {
        // Normalize object type to lowercase for case-insensitive matching.
        $objectTypeLower = strtolower($objectType);

        // Return the appropriate mapper based on object type.
        // Match expression provides type-safe routing.
        return match ($objectTypeLower) {
            'schema' => $this->schemaMapper,
            'register' => $this->registerMapper,
            default => throw new InvalidArgumentException("Unknown object type: $objectType"),
        };
    }//end getMapper()
}//end class
