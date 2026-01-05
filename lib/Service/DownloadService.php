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
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateField) Reserved for future implementation
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
}//end class
