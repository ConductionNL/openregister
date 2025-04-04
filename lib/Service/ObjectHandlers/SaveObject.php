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

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

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


    /**
     * Constructor for SaveObject handler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param FileService        $fileService        File service for managing files.
     * @param IUserSession       $userSession        User session service.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly IUserSession $userSession
    ) {

    }//end __construct()


    /**
     * Saves an object to the database.
     *
     * @param Register|int|string $register The register to save the object to.
     * @param Schema|int|string   $schema   The schema to validate against.
     * @param array               $object   The object data to save.
     * @param int|null            $depth    The depth level for nested saves (default: null).
     *
     * @return ObjectEntity The saved object entity.
     *
     * @throws Exception If there is an error during save.
     */
    public function saveObject(
        Register | int | string $register,
        Schema | int | string $schema,
        array $object,
        ?int $depth=null
    ): ObjectEntity {
        // Create a new object entity with basic properties.
        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid(Uuid::v4()->toRfc4122());

        // Set register ID based on input type.
        $registerId = null;
        if ($register instanceof Register) {
            $registerId = $register->getId();
        } else {
            $registerId = $register;
        }

        $objectEntity->setRegisterId($registerId);

        // Set schema ID based on input type.
        $schemaId = null;
        if ($schema instanceof Schema) {
            $schemaId = $schema->getId();
        } else {
            $schemaId = $schema;
        }

        $objectEntity->setSchemaId($schemaId);

        $objectEntity->setObject($object);
        $objectEntity->setCreatedAt(new DateTime());
        $objectEntity->setUpdatedAt(new DateTime());

        // Set user information if available.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $objectEntity->setCreatedBy($user->getUID());
            $objectEntity->setUpdatedBy($user->getUID());
        }

        // Handle object relations.
        $objectEntity = $this->handleObjectRelations(
            $objectEntity,
            $object,
            $schema->getProperties(),
            $register,
            $schema,
            $depth ?? 0
        );

        // Save the object to database.
        $savedEntity = $this->objectEntityMapper->insert($objectEntity);

        // Handle file properties.
        foreach ($object as $propertyName => $value) {
            if ($this->isFileProperty($value) === true) {
                $this->handleFileProperty($savedEntity, $object, $propertyName);
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
     * Handles object relations during save.
     *
     * @param ObjectEntity $objectEntity The object entity being saved.
     * @param array        $object       The object data.
     * @param array        $properties   The schema properties.
     * @param int          $register     The register ID.
     * @param int          $schema       The schema ID.
     * @param int          $depth        The depth level for nested relations.
     *
     * @return ObjectEntity The object entity with relations handled.
     */
    private function handleObjectRelations(
        ObjectEntity $objectEntity,
        array $object,
        array $properties,
        int $register,
        int $schema,
        int $depth=0
    ): ObjectEntity {
        foreach ($object as $propertyName => $value) {
            if (isset($properties[$propertyName]) === false) {
                continue;
            }

            $property = $properties[$propertyName];
            if ($this->isRelationProperty($property) === true) {
                $objectEntity->setObject(
                    $this->handleProperty($property, $propertyName, $register, $schema, $object, $objectEntity, $depth)
                );
            }
        }

        return $objectEntity;

    }//end handleObjectRelations()


    /**
     * Checks if a property is a relation property.
     *
     * @param array $property The property to check.
     *
     * @return bool Whether the property is a relation property.
     */
    private function isRelationProperty(array $property): bool
    {
        return isset($property['type'])
            && ($property['type'] === 'object' || $property['type'] === 'array')
            && isset($property['items']['$ref']);

    }//end isRelationProperty()


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


}//end class
