<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use OCP\IURLGenerator;
use stdClass;

/**
 * Class Schema
 *
 * Entity class representing a Schema
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 * @author   Nextcloud GmbH and Nextcloud contributors
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  0.1.48
 *
 * @property string|null $uuid Unique identifier for the schema
 * @property string|null $slug Slug of the schema
 * @property string|null $title Title of the schema
 * @property string|null $version Version of the schema
 * @property string|null $description Description of the schema
 * @property string|null $summary Summary of the schema
 * @property array|null $required Required fields of the schema
 * @property array|null $properties Properties of the schema
 * @property array|null $archive Archive data of the schema
 * @property string|null $source Source of the schema
 * @property bool $hardValidation Whether hard validation is enabled
 * @property DateTime|null $updated Last update timestamp
 * @property DateTime|null $created Creation timestamp
 * @property int $maxDepth Maximum depth of the schema
 * @property string|null $owner The Nextcloud user that owns this schema
 * @property string|null $application The application name
 * @property string|null $organisation The organisation name
 * @property array|null $authorization JSON object describing authorizations
 * @property DateTime|null $deleted Deletion timestamp
 */
class Schema extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?string $slug = null;

    protected ?string $title = null;

    protected ?string $description = null;

    protected ?string $version = null;

    protected ?string $summary = null;

    protected ?array $required = [];

    protected ?array $properties = [];

    protected ?array $archive = [];

    protected ?string $source = null;

    protected bool $hardValidation = false;

    protected ?DateTime $updated = null;

    protected ?DateTime $created = null;

    protected int $maxDepth = 0;

    protected ?string $owner = null;

    protected ?string $application = null;

    protected ?string $organisation = null;

    protected ?array $authorization = [];

    protected ?DateTime $deleted = null;


    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'required', type: 'json');
        $this->addType(fieldName: 'properties', type: 'json');
        $this->addType(fieldName: 'archive', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'hardValidation', type: Types::BOOLEAN);
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'maxDepth', type: Types::INTEGER);
        // @todo this is being missed used so needs a refactor, sub onjects should be based on schema property config
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');

    }//end __construct()


    /**
     * Get the required data
     *
     * @return array The required data or empty array if null
     */
    public function getRequired(): array
    {
        return ($this->required ?? []);

    }//end getRequired()


    /**
     * Get the properties data
     *
     * @return array The properties data or empty array if null
     */
    public function getProperties(): array
    {
        return ($this->properties ?? []);

    }//end getProperties()


    /**
     * Get the archive data
     *
     * @return array The archive data or empty array if null
     */
    public function getArchive(): array
    {
        return ($this->archive ?? []);

    }//end getArchive()


    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
           $this->getFieldTypes(),
           function ($field) {
                return $field === 'json';
           }
           )
        );

    }//end getJsonFields()


    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        if (isset($object['metadata']) === false) {
            $object['metadata'] = [];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
            }
        }

        return $this;

    }//end hydrate()


    /**
     * Serializes the schema to an array
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        $required   = ($this->required ?? []);
        $properties = [];
        if (isset($this->properties) === true) {
            foreach ($this->properties as $title => $property) {
                $title = ($property['title'] ?? $title);
                if (isset($property['required']) === true && $property['required'] === true && in_array($title, $required) === false) {
                    $required[] = $title;
                }

                unset($property['title'], $property['required']);

                $properties[$title] = $property;
            }
        }

        $array = [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'slug'           => $this->slug,
            'title'          => $this->title,
            'description'    => $this->description,
            'version'        => $this->version,
            'summary'        => $this->summary,
            'required'       => $required,
            'properties'     => $properties,
            'archive'        => $this->archive,
            'source'         => $this->source,
            'hardValidation' => $this->hardValidation,
            'updated'        => isset($this->updated) ? $this->updated->format('c') : null,
            'created'        => isset($this->created) ? $this->created->format('c') : null,
            'maxDepth'       => $this->maxDepth,
            'owner'          => $this->owner,
            'application'    => $this->application,
            'organisation'   => $this->organisation,
            'authorization'  => $this->authorization,
            'deleted'        => isset($this->deleted) ? $this->deleted->format('c') : null,
        ];

        $jsonFields = $this->getJsonFields();

        foreach ($array as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === null) {
                $array[$key] = [];
            }
        }

        return $array;

    }//end jsonSerialize()


    /**
     * Creates a decoded JSON Schema object from the information in the schema
     *
     * @return object Decoded JSON Schema object.
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): object
    {
        $data = $this->jsonSerialize();

        foreach ($data['properties'] as $title => $property) {
            // Remove empty fields with array_filter().
            $data['properties'][$title] = array_filter($property);

            if (isset($property['type']) === false) {
                continue;
            }

            if ($property['type'] === 'file') {
                $data['properties'][$title] = ['$ref' => $urlGenerator->getBaseUrl().'/apps/openregister/api/files/schema'];
            }

            if ($property['type'] === 'oneOf') {
                unset($data['properties'][$title]['type']);
                $data['properties'][$title]['oneOf'] = array_map(
                    callback: function (array $item) use ($urlGenerator) {
                        if ($item['type'] === 'file') {
                            unset($item['type']);
                            $item['$ref'] = $urlGenerator->getBaseUrl().'/apps/openregister/api/files/schema';
                        }

                        return $item;
                    },
                    array: $property['oneOf']
                );
            }

            if ($property['type'] === 'array'
                && isset($property['items']['type']) === true
                && $property['items']['type'] === 'oneOf'
            ) {
                unset($data['properties'][$title]['items']['type']);
            }

            // @TODO unset $ref because this cant work yet and will cause errors, the validater will try to find these schemas but they are internal references.
            if ($property['type'] === 'object' && isset($property['$ref']) === true) {
                unset($data['properties'][$title]['$ref']);
            }

            if ($property['type'] === 'array' && isset($property['items']['$ref']) === true) {
                unset($data['properties'][$title]['items']['$ref']);
            }

            // Make object uri properties validateable as string uri and cascaded objects.
            if ($property['type'] === 'object' && isset($property['objectConfiguration']['handling']) === true && $property['objectConfiguration']['handling'] === 'uri') {
                unset($data['properties'][$title]['format'], $data['properties'][$title]['type']);
                $data['properties'][$title]['oneOf'] = [
                    [
                        'type' => 'object',
                    ],
                    [
                        'type'   => 'string',
                        'format' => 'uri',
                    ],
                ];

                // Also if not required type null must be a option
                if (in_array($title, $data['required']) === false) {
                    $data['properties'][$title]['oneOf'][] = [
                        'type' => 'null',
                    ];
                }
            }
        }//end foreach

        unset(
          $data['id'],
          $data['uuid'],
          $data['summary'],
          $data['archive'],
          $data['source'],
            $data['updated'],
          $data['created']
          );

        $data['type'] = 'object';

        // Validator needs this specific $schema
        $data['$schema'] = 'https://json-schema.org/draft/2020-12/schema';
        $data['$id']     = $urlGenerator->getAbsoluteURL($urlGenerator->linkToRoute('openregister.Schemas.show', ['id' => $this->getId()]));

        return json_decode(json_encode($data));

    }//end getSchemaObject()


}//end class
