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
 * Service for handling download requests for database entities.
 *
 * This service enables downloading database entities as files in various formats,
 * determined by the `Accept` header of the request. It retrieves the appropriate
 * data from mappers and generates responses or downloadable files.
 */
class DownloadService
{




    /**
     * Generate a downloadable json file response.
     *
     * @param string $jsonData The json data to create a json file with.
     * @param string $filename The filename, .json will be added after this filename in this function.
     *
     * @return never
     */
    private function downloadJson(string $jsonData, string $filename)
    {
        // Define the file name and path for the temporary JSON file.
        $fileName = $filename.'.json';
        $filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$fileName;

        // Create and write the JSON data to the file.
        file_put_contents($filePath, $jsonData);

        // Set headers to download the file.
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="'.$fileName.'"');
        header('Content-Length: '.filesize($filePath));

        // Output the file contents.
        readfile($filePath);

        // Clean up: delete the temporary file.
        unlink($filePath);

        // Ensure no further script execution.
        exit;

    }//end downloadJson()


    /**
     * Gets the appropriate mapper based on the object type.
     *
     * @param string $objectType The type of object to retrieve the mapper for.
     *
     * @throws InvalidArgumentException If an unknown object type is provided.
     * @throws Exception
     *
     * @return mixed The appropriate mapper.
     */
    private function getMapper(string $objectType): mixed
    {
        $objectTypeLower = strtolower($objectType);

        // If the source is internal, return the appropriate mapper based on the object type.
        return match ($objectTypeLower) {
            'schema' => $this->schemaMapper,
            'register' => $this->registerMapper,
            default => throw new InvalidArgumentException("Unknown object type: $objectType"),
        };

    }//end getMapper()


}//end class
