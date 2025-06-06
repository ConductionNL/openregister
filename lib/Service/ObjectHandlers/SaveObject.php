<?php
/**
 * OpenRegister SaveObject Handler
 *
 * Handler class responsible for persisting objects to the database.
 * This handler provides methods for:
 * - Creating and updating object entities
 * - Managing object metadata (creation/update timestamps, UUIDs)
 * - Handling object relations and nested objects
 * - Processing file attachments and uploads
 * - Maintaining audit trails (user tracking)
 * - Setting default values and properties
 *
 * @category Handler
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

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Adbar\Dot;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\DoesNotExistException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Handler class for saving objects in the OpenRegister application.
 *
 * This handler is responsible for saving objects to the database,
 * including handling relations, files, and audit trails.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class SaveObject
{

    private const URL_PATH_IDENTIFIER = 'openregister.objects.show';

    private Environment $twig;


    /**
     * Constructor for SaveObject handler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param FileService        $fileService        File service for managing files.
     * @param IUserSession       $userSession        User session service.
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logging changes.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IURLGenerator $urlGenerator,
        ArrayLoader $arrayLoader,
    ) {
        $this->twig = new Environment($arrayLoader);

    }//end __construct()


    /**
     * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
     *
     * @param array  $data   The object data to scan
     * @param string $prefix The current prefix for dot notation (used in recursion)
     *
     * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
     */
    private function scanForRelations(array $data, string $prefix=''): array
    {
        $relations = [];

        foreach ($data as $key => $value) {
            $currentPath = $prefix ? $prefix.'.'.$key : $key;

            if (is_array($value)) {
                // Recursively scan nested arrays
                $relations = array_merge($relations, $this->scanForRelations($value, $currentPath));
            } else if (is_string($value)) {
                // Check for UUID pattern
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
                    $relations[$currentPath] = $value;
                }
                // Check for URL pattern
                else if (filter_var($value, FILTER_VALIDATE_URL)) {
                    $relations[$currentPath] = $value;
                }
            }
        }

        return $relations;

    }//end scanForRelations()


    /**
     * Updates the relations property of an object entity
     *
     * @param ObjectEntity $objectEntity The object entity to update
     * @param array        $data         The object data to scan for relations
     *
     * @return ObjectEntity The updated object entity
     */
    private function updateObjectRelations(ObjectEntity $objectEntity, array $data): ObjectEntity
    {
        // Scan for relations in the object data
        $relations = $this->scanForRelations($data);

        // Set the relations on the object entity
        $objectEntity->setRelations($relations);

        return $objectEntity;

    }//end updateObjectRelations()

    /**
     * Set default values for values that are not in the data array.
     *
     * @param ObjectEntity $objectEntity The objectEntity for which to perform this action.
     * @param Schema $schema The schema the objectEntity belongs to.
     * @param array $data The data that is written to the object.
     *
     * @return array The data object updated with default values from the $schema.
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\SyntaxError
     */
    private function setDefaultValues (ObjectEntity $objectEntity, Schema $schema, array $data): array
    {
        $schemaObject = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true);

        // Convert the properties array to a processable array.
        $properties = array_map(function(string $key, array $property) {
            if (isset($property['default']) === false) {
                $property['default'] = null;
            }
            $property['title'] = $key;
            return $property;
        }, array_keys($schemaObject['properties']), $schemaObject['properties']);

        $defaultValues = array_filter(array_column($properties, 'default', 'title'));

        // Remove all keys from array for which a value has already been set in $data.
        $defaultValues = array_diff_key($defaultValues, $data);

        // Render twig templated default values.
        $renderedDefaultValues = array_map(function(mixed $defaultValue) use ($objectEntity, $data) {
            if (is_string($defaultValue) && str_contains(haystack: $defaultValue, needle: '{{') && str_contains(haystack: $defaultValue, needle: '}}')) {
                return $this->twig->createTemplate($defaultValue)->render($objectEntity->getObjectArray());
            }

            return $defaultValue;
        }, $defaultValues);

        // Add data to the $data array, with the order that values already in $data never get overwritten.
        return array_merge($renderedDefaultValues, $data);
    }//end setDefaultValues

	private function cascadeObjects (ObjectEntity $objectEntity, Schema $schema, array $data): array
	{
		$properties = json_decode(json_encode($schema->getSchemaObject($this->urlGenerator)), associative: true)['properties'];

		$objectProperties = array_filter($properties, function(array $property) {
			return $property['type'] === 'object' && isset($property['$ref']) === true && isset($property['inversedBy']) === true;
		});

		$arrayObjectProperties = array_filter($properties, function(array $property) {
			return $property['type'] === 'array'
				&& (isset($property['$ref']) || isset($property['items']['$ref']))
				&& (isset($property['inversedBy']) === true || isset($property['items']['inversedBy']) === true);
		});

		//@TODO this can be done asynchronous
		foreach($objectProperties as $property => $definition) {
            if (isset($data[$property]) === false || empty($data[$property]) === true) {
                continue;
            }

            $this->cascadeSingleObject($objectEntity, $definition, $data[$property]);
			unset($data[$property]);
		}

		foreach($arrayObjectProperties as $property => $definition) {
            if (isset($data[$property]) === false || empty($data[$property]) === true) {
                continue;
            }

            $this->cascadeMultipleObjects($objectEntity, $definition, $data[$property]);
			unset($data[$property]);
		}


		return $data;
	}

	private function cascadeMultipleObjects(ObjectEntity $objectEntity, array $property, array $propData): void
	{
		if(array_is_list($propData) === false) {
			throw new Exception('Data is not an array of objects');
		}

		if (isset($property['$ref']) === true) {
			$property['items']['$ref'] = $property['$ref'];
		}

		if (isset($property['inversedBy']) === true) {
			$property['items']['inversedBy'] = $property['inversedBy'];
		}

		$propData = array_map(function(array $object) use ($objectEntity, $property) {
			$this->cascadeSingleObject($objectEntity, $property['items'], $object);
			return $object;
		}, $propData);

	}

	private function cascadeSingleObject(ObjectEntity $objectEntity, array $definition, array $object): void
	{
		$objectId = $objectEntity->getUuid();

		$object[$definition['inversedBy']] = $objectId;
		$this->saveObject($objectEntity->getRegister(), $definition['$ref'], $object, $object['id'] ?? $object['@self']['id'] ?? null);
	}


    /**
     * Saves an object.
     *
     * @param Register|int|string|null $register The register containing the object.
     * @param Schema|int|string   $schema   The schema to validate against.
     * @param array               $data     The object data to save.
     * @param string|null         $uuid     The UUID of the object to update (if updating).
     *
     * @return ObjectEntity The saved object entity.
     *
     * @throws Exception If there is an error during save.
     */
    public function saveObject(
        Register | int | string | null $register,
        Schema | int | string $schema,
        array $data,
        ?string $uuid=null
    ): ObjectEntity {
        // Remove the @self property from the data.
        unset($data['@self']);
        unset($data['id']);

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }

        // Find register by schema
        // @todo this will cause saving in unspecified register if a schema is configured in multiple registers
        $registerId = $this->registerMapper->getFirstRegisterWithSchema((int) $schemaId);
        $register = $this->registerMapper->find($registerId);

        // If UUID is provided, try to find and update existing object.
        if ($uuid !== null) {
            try {
                $existingObject = $this->objectEntityMapper->find($uuid);
				$data = $this->cascadeObjects($existingObject, $schema, $data);
				$data = $this->setDefaultValues($existingObject, $schema, $data);
                return $this->updateObject($register, $schema, $data, $existingObject);
            } catch (\Exception $e) {
                // Object not found, proceed with creating new object.
            }
        }

        // Create a new object entity.
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister($registerId);
        $objectEntity->setSchema($schemaId);
        $objectEntity->setCreated(new DateTime());
        $objectEntity->setUpdated(new DateTime());

        
        // Check if '@self' metadata exists and contains published/depublished properties
        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];

            // Extract and set published property if present
            if (array_key_exists('published', $selfData) && !empty($selfData['published'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['published']) === true) {
                        $objectEntity->setPublished(new DateTime($selfData['published']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $objectEntity->setPublished(null);
            }

            // Extract and set depublished property if present
            if (array_key_exists('depublished', $selfData) && !empty($selfData['depublished'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['depublished']) === true) {
                        $objectEntity->setDepublished(new DateTime($selfData['depublished']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $objectEntity->setDepublished(null);
            }
        }

        unset($data['@self'], $data['id']);

        // Set UUID if provided, otherwise generate a new one.
        if ($uuid !== null) {
            $objectEntity->setUuid($uuid);
            // @todo: check if this is a correct uuid.
        } else {
            $objectEntity->setUuid(Uuid::v4());
        }
        $objectEntity->setUri($this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(
            self::URL_PATH_IDENTIFIER, [
                'register' => $register instanceof Register === true ? $register->getSlug() : $registerId,
                'schema' => $schema instanceof Schema === true ? $schema->getSlug() : $schemaId,
                'id' => $objectEntity->getUuid()
            ]
        )));

        // Set default values.
        if ($schema instanceof Schema === false) {
            $schema = $this->schemaMapper->find($schemaId);
        }
		$data = $this->cascadeObjects($objectEntity, $schema, $data);
        $data = $this->setDefaultValues($objectEntity, $schema, $data);
        $objectEntity->setObject($data);


        // Set user information if available.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $objectEntity->setOwner($user->getUID());
        }

        // Update object relations.
        $objectEntity = $this->updateObjectRelations($objectEntity, $data);

        // Save the object to database.
        $savedEntity = $this->objectEntityMapper->insert($objectEntity);

        // Create audit trail for creation.
        $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $savedEntity);
        $savedEntity->setLastLog($log->jsonSerialize());

        // Handle file properties.
        foreach ($data as $propertyName => $value) {
            if ($this->isFileProperty($value) === true) {
                $this->handleFileProperty($savedEntity, $data, $propertyName);
            }
        }

        return $savedEntity;

    }//end saveObject()


    /**
     * Sets default values for an object entity.
     *
     * @param ObjectEntity $objectEntity The object entity to set defaults for.
     *
     * @return ObjectEntity The object entity with defaults set.
     */
    public function setDefaults(ObjectEntity $objectEntity): ObjectEntity
    {
        if ($objectEntity->getCreatedAt() === null) {
            $objectEntity->setCreatedAt(new DateTime());
        }

        if ($objectEntity->getUpdatedAt() === null) {
            $objectEntity->setUpdatedAt(new DateTime());
        }

        if ($objectEntity->getUuid() === null) {
            $objectEntity->setUuid(Uuid::v4()->toRfc4122());
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            if ($objectEntity->getCreatedBy() === null) {
                $objectEntity->setCreatedBy($user->getUID());
            }

            if ($objectEntity->getUpdatedBy() === null) {
                $objectEntity->setUpdatedBy($user->getUID());
            }
        }

        return $objectEntity;

    }//end setDefaults()


    /**
     * Checks if a value represents a file property.
     *
     * @param mixed $value The value to check.
     *
     * @return bool Whether the value is a file property.
     */
    private function isFileProperty($value): bool
    {
        return is_string($value) && strpos($value, 'data:') === 0;

    }//end isFileProperty()


    /**
     * Handles a file property during save.
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        $object       The object data.
     * @param string       $propertyName The name of the file property.
     *
     * @return void
     */
    private function handleFileProperty(ObjectEntity $objectEntity, array $object, string $propertyName): void
    {
        $fileContent = $object[$propertyName];
        $fileName    = $propertyName.'_'.time();
        $this->fileService->addFile($objectEntity, $fileName, $fileContent);

    }//end handleFileProperty()


    /**
     * Updates an existing object.
     *
     * @param Register|int|string $register       The register containing the object.
     * @param Schema|int|string   $schema         The schema to validate against.
     * @param array               $data           The updated object data.
     * @param ObjectEntity        $existingObject The existing object to update.
     *
     * @return ObjectEntity The updated object entity.
     *
     * @throws Exception If there is an error during update.
     */
    public function updateObject(
        Register | int | string $register,
        Schema | int | string $schema,
        array $data,
        ObjectEntity $existingObject
    ): ObjectEntity {
        // Store the old state for audit trail.
        $oldObject = clone $existingObject;

        // Lets filter out the id and @self properties from the old object.
        $oldObjectData = $oldObject->getObject();

        $oldObject->setObject($oldObjectData);

        // Set register ID based on input type.
        $registerId = null;
        if ($register instanceof Register) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
        }

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }
        
        // Check if '@self' metadata exists and contains published/depublished properties
        if (isset($data['@self']) && is_array($data['@self'])) {
            $selfData = $data['@self'];

            // Extract and set published property if present
            if (array_key_exists('published', $selfData) && !empty($selfData['published'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['published']) === true) {
                        $existingObject->setPublished(new DateTime($selfData['published']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $existingObject->setPublished(null);
            }

            // Extract and set depublished property if present
            if (array_key_exists('depublished', $selfData) && !empty($selfData['depublished'])) {
                try {
                    // Convert string to DateTime if it's a valid date string
                    if (is_string($selfData['depublished']) === true) {
                        $existingObject->setDepublished(new DateTime($selfData['depublished']));
                    }
                } catch (Exception $exception) {
                    // Silently ignore invalid date formats
                }
            } else {
                $existingObject->setDepublished(null);
            }
        }

        // Remove @self and id from the data before setting object
        unset($data['@self'], $data['id']);

        // Update the object properties.
        $existingObject->setRegister($registerId);
        $existingObject->setSchema($schemaId);
        $existingObject->setObject($data);
        $existingObject->setUpdated(new DateTime());

        // Update object relations.
        $existingObject = $this->updateObjectRelations($existingObject, $data);

        // Save the object to database.
        $updatedEntity = $this->objectEntityMapper->update($existingObject);

        // Create audit trail for update.
        $log = $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $updatedEntity);
        $updatedEntity->setLastLog($log->jsonSerialize());

        // Handle file properties.
        foreach ($data as $propertyName => $value) {
            if ($this->isFileProperty($value) === true) {
                $this->handleFileProperty($updatedEntity, $data, $propertyName);
            }
        }

        return $updatedEntity;

    }//end updateObject()


}//end class
