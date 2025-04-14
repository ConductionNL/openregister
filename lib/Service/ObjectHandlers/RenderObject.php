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
     * This method takes an ObjectEntity and applies extensions and filters to it.
     * It maintains the object's structure while allowing for property extension
     * and filtering based on the provided parameters.
     *
     * @param ObjectEntity $entity The entity to render
     * @param array|string|null $extend Properties to extend the entity with, can be an array or a comma-separated string
     * @param int $depth The depth level for nested rendering
     * @param array|null $filter Filters to apply to the rendered entity
     * @param array|null $fields Specific fields to include in the output
     * @param array|null $registers An array of registers where the key is the id
     * @param array|null $schemas An array of schemas where the key is the id
     *
     * @return ObjectEntity The rendered entity with applied extensions and filters
     *
     * @throws \InvalidArgumentException If the entity fails validation
     *
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedAssignment
     */
    public function renderEntity(
        ObjectEntity $entity,
        array|string|null $extend = [],
        int $depth = 0,
        ?array $filter = [],
        ?array $fields = [],
        ?array $registers = null,
        ?array $schemas = null
    ): ObjectEntity {
        // Convert extend to an array if it's a string
        if (is_string($extend)) {
            $extend = explode(',', $extend);
        }

        // Get the object data as an array for manipulation
        $objectData = $entity->getObject();

        // Apply field filtering if specified
        if (!empty($fields)) {
            $filteredData = [];
            foreach ($fields as $field) {
                if (isset($objectData[$field])) {
                    $filteredData[$field] = $objectData[$field];
                }
            }

            $objectData = $filteredData;
            $entity->setObject($objectData);
        }

        // Apply filters if specified
        if (!empty($filter)) {
            foreach ($filter as $key => $value) {
                if (isset($objectData[$key]) && $objectData[$key] !== $value) {
                    // If filter doesn't match, clear the object data
                    $entity->setObject([]);
                    return $entity;
                }
            }
        }

        // Handle extensions if depth limit not reached
        if (!empty($extend) && $depth < 10) {
            foreach ($extend as $key => $value) {
                if (isset($objectData[$key])) {
                    if (is_array($value)) {
                        // Recursively handle nested objects
                        if ($objectData[$key] instanceof ObjectEntity) {
                            $objectData[$key] = $this->renderEntity(
                                $objectData[$key],
                                $value,
                                $depth + 1,
                                $filter,
                                $fields,
                                $registers,
                                $schemas
                            );
                        }
                    }
                }
            }

            $entity->setObject($objectData);
        }

        // Add register and schema context to @self if specified in extend
        if (!empty($extend) && (in_array('@self.register', $extend) || in_array('@self.schema', $extend))) {
            $self = $objectData['@self'] ?? [];
            $entityRegister = $entity->getRegister();
            $entitySchema = $entity->getSchema();

            if (in_array('@self.register', $extend) && $registers !== null && isset($registers[$entityRegister])) {
                $self['register'] = $registers[$entityRegister]->jsonSerialize();
            }

            if (in_array('@self.schema', $extend) && $schemas !== null && isset($schemas[$entitySchema])) {
                $self['schema'] = $schemas[$entitySchema]->jsonSerialize();
            }

            $objectData['@self'] = $self;
            //@todo: lets also extend application, owner and organisation
            $entity->setObject($objectData);
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
