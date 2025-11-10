<?php
/**
 * Class SchemaNotFoundException
 *
 * Exception thrown when a schema cannot be found by slug or ID.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception thrown when a schema cannot be found by slug or ID.
 *
 * @psalm-suppress                 UnusedClass
 * @phpstan-consistent-constructor
 */
class SchemaNotFoundException extends Exception
{


    /**
     * SchemaNotFoundException constructor.
     *
     * @param string         $schemaSlugOrId The schema slug or ID that was not found
     * @param int            $code           The exception code (default 404)
     * @param Exception|null $previous       The previous exception
     *
     * @phpstan-param string $schemaSlugOrId
     * @phpstan-param int $code
     * @phpstan-param Exception|null $previous
     */
    public function __construct(string $schemaSlugOrId, int $code=404, Exception $previous=null)
    {
        $message = "Schema not found: '".$schemaSlugOrId."'";
        parent::__construct($message, $code, $previous);

    }//end __construct()


}//end class
