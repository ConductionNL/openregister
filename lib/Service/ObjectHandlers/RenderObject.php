<?php
/**
 * OpenRegister RenderObject Handler
 *
 * Handler class responsible for transforming objects into their presentational format.
 * This handler provides methods for:
 * - Converting objects to their JSON representation
 * - Handling property extensions and nested objects
 * - Managing depth control for nested rendering
 * - Applying field filtering and selection
 * - Formatting object properties for display
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\IURLGenerator;

/**
 * Handler class for rendering objects in the OpenRegister application.
 *
 * This handler is responsible for transforming objects into their presentational format,
 * including handling of extensions, depth control, and field filtering.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class RenderObject
{


    /**
     * Constructor for RenderObject handler.
     *
     * @param IURLGenerator $urlGenerator URL generator service.
     * @param FileService   $fileService  File service for managing files.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly FileService $fileService
    ) {

    }//end __construct()


    /**
     * Renders an entity with optional extensions and filters.
     *
     * @param array      $entity The entity to render.
     * @param array|null $extend Properties to extend the entity with.
     * @param int        $depth  The depth level for nested rendering.
     * @param array|null $filter Filters to apply to the rendered entity.
     * @param array|null $fields Specific fields to include in the output.
     *
     * @return array The rendered entity.
     *
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedAssignment
     */
    public function renderEntity(array $entity, ?array $extend=[], int $depth=0, ?array $filter=[], ?array $fields=[]): array
    {
        // Convert entity to array if it's an object.
        if ($entity instanceof JsonSerializable) {
            $entity = $entity->jsonSerialize();
        }

        // Apply field filtering if specified.
        if (empty($fields) === false) {
            $filteredEntity = [];
            foreach ($fields as $field) {
                if (isset($entity[$field]) === true) {
                    $filteredEntity[$field] = $entity[$field];
                }
            }

            $entity = $filteredEntity;
        }

        // Apply filters if specified.
        if (empty($filter) === false) {
            foreach ($filter as $key => $value) {
                if (isset($entity[$key]) === true && $entity[$key] !== $value) {
                    return [];
                }
            }
        }

        // Handle extensions if depth limit not reached.
        if (empty($extend) === false && $depth < 10) {
            foreach ($extend as $key => $value) {
                if (isset($entity[$key]) === true) {
                    if (is_array($value) === true) {
                        $entity[$key] = $this->renderEntity($entity[$key], $value, $depth + 1);
                    }
                }
            }
        }

        return $entity;

    }//end renderEntity()


    /**
     * Gets the string before a dot in a given input.
     *
     * @param string $input The input string to process.
     *
     * @return string The substring before the first dot.
     */
    private function getStringBeforeDot(string $input): string
    {
        $dotPosition = strpos($input, '.');
        if ($dotPosition === false) {
            return $input;
        }

        return substr($input, 0, $dotPosition);

    }//end getStringBeforeDot()


    /**
     * Gets the string after the last slash in a given input.
     *
     * @param string $input The input string to process.
     *
     * @return string The substring after the last slash.
     */
    private function getStringAfterLastSlash(string $input): string
    {
        $lastSlashPosition = strrpos($input, '/');
        if ($lastSlashPosition === false) {
            return $input;
        }

        return substr($input, $lastSlashPosition + 1);

    }//end getStringAfterLastSlash()


}//end class
