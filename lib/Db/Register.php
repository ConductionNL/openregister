<?php

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a Register
 *
 * @property string|null $uuid Unique identifier for the register
 * @property string|null $slug Slug of the register
 * @property string|null $title Title of the register
 * @property string|null $version Version of the register
 * @property string|null $description Description of the register
 * @property array|null $schemas Schemas associated with the register
 * @property string|null $source Source of the register
 * @property string|null $tablePrefix Prefix for database tables
 * @property string|null $folder Nextcloud folder path where register is stored
 * @property DateTime|null $updated Last update timestamp
 * @property DateTime|null $created Creation timestamp
 * @property string|null $owner The Nextcloud user that owns this register
 * @property string|null $application The application name
 * @property string|null $organisation The organisation name
 * @property array|null $authorization JSON object describing authorizations
 * @property DateTime|null $deleted Deletion timestamp
 */
class Register extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?string $slug = null;

    protected ?string $title = null;

    protected ?string $version = null;

    protected ?string $description = null;

    protected ?array $schemas = [];

    protected ?string $source = null;

    protected ?string $tablePrefix = null;

    protected ?string $folder = null;

    // Nextcloud folder path where register is stored
    protected ?DateTime $updated = null;

    protected ?DateTime $created = null;

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
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'schemas', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'tablePrefix', type: 'string');
        $this->addType(fieldName: 'folder', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');

    }//end __construct()


    /**
     * Get the schemas data
     *
     * @return array The schemas data or empty array if null
     */
    public function getSchemas(): array
    {
        return ($this->schemas ?? []);

    }//end getSchemas()


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


    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'slug'          => $this->slug,
            'title'         => $this->title,
            'version'       => $this->version,
            'description'   => $this->description,
            'schemas'       => $this->schemas,
            'source'        => $this->source,
            'tablePrefix'   => $this->tablePrefix,
            'folder'        => $this->folder,
            'updated'       => isset($this->updated) ? $this->updated->format('c') : null,
            'created'       => isset($this->created) ? $this->created->format('c') : null,
            'owner'         => $this->owner,
            'application'   => $this->application,
            'organisation'  => $this->organisation,
            'authorization' => $this->authorization,
            'deleted'       => isset($this->deleted) ? $this->deleted->format('c') : null,
        ];

    }//end jsonSerialize()


}//end class
