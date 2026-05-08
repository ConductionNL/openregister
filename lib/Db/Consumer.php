<?php

/**
 * Consumer Entity for API client authentication.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents an API consumer (client) with authentication configuration.
 *
 * Consumers define how external services authenticate with OpenRegister,
 * supporting JWT, Basic Auth, OAuth2, and API Key methods.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getDomains()
 * @method void setDomains(?array $domains)
 * @method array|null getIps()
 * @method void setIps(?array $ips)
 * @method string|null getAuthorizationType()
 * @method void setAuthorizationType(?string $authorizationType)
 * @method array|null getAuthorizationConfiguration()
 * @method void setAuthorizationConfiguration(?array $authorizationConfiguration)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Consumer extends Entity implements JsonSerializable
{

    /**
     * UUID identifier.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Consumer name / JWT issuer.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Description.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Allowed CORS domains.
     *
     * @var array|null
     */
    protected ?array $domains = [];

    /**
     * Allowed IP addresses.
     *
     * @var array|null
     */
    protected ?array $ips = [];

    /**
     * Authorization type: none, basic, bearer, apiKey, oauth2, jwt.
     *
     * @var string|null
     */
    protected ?string $authorizationType = null;

    /**
     * Authorization config (public key, algorithm, API keys, etc.).
     *
     * @var array|null
     */
    protected ?array $authorizationConfiguration = [];

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Last update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Associated Nextcloud user ID.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * Consumer constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'domains', type: 'json');
        $this->addType(fieldName: 'ips', type: 'json');
        $this->addType(fieldName: 'authorizationType', type: 'string');
        $this->addType(fieldName: 'authorizationConfiguration', type: 'json');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'userId', type: 'string');

    }//end __construct()

    /**
     * Get the allowed domains.
     *
     * @return array The allowed domains or empty array if null
     */
    public function getDomains(): array
    {
        return ($this->domains ?? []);

    }//end getDomains()

    /**
     * Get the allowed IPs.
     *
     * @return array The allowed IPs or empty array if null
     */
    public function getIps(): array
    {
        return ($this->ips ?? []);

    }//end getIps()

    /**
     * Get the authorization configuration.
     *
     * @return array The authorization configuration or empty array if null
     */
    public function getAuthorizationConfiguration(): array
    {
        return ($this->authorizationConfiguration ?? []);

    }//end getAuthorizationConfiguration()

    /**
     * Get the JSON fields of the Consumer entity.
     *
     * @return array An array of field names that are of type 'json'
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array: array_filter(
                array: $this->getFieldTypes(),
                callback: function ($field) {
                    return $field === 'json';
                }
            )
        );

    }//end getJsonFields()

    /**
     * Hydrate the Consumer entity with data from an array.
     *
     * @param array $object The array containing the data to hydrate the entity
     *
     * @return self Returns the hydrated Consumer entity
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array(needle: $key, haystack: $jsonFields) === true && $value === []) {
                $value = [];
            }

            $method = 'set'.ucfirst(string: $key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Skip unknown fields silently.
            }
        }

        return $this;

    }//end hydrate()

    /**
     * Get the created timestamp formatted as ISO 8601 or null.
     *
     * @return string|null The formatted timestamp or null
     */
    private function getCreatedFormatted(): ?string
    {
        if (isset($this->created) === true) {
            return $this->created->format('c');
        }

        return null;

    }//end getCreatedFormatted()

    /**
     * Get the updated timestamp formatted as ISO 8601 or null.
     *
     * @return string|null The formatted timestamp or null
     */
    private function getUpdatedFormatted(): ?string
    {
        if (isset($this->updated) === true) {
            return $this->updated->format('c');
        }

        return null;

    }//end getUpdatedFormatted()

    /**
     * Serialize the Consumer entity to JSON.
     *
     * @return array An array representation of the Consumer entity for JSON serialization
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                         => $this->id,
            'uuid'                       => $this->uuid,
            'name'                       => $this->name,
            'description'                => $this->description,
            'domains'                    => $this->domains,
            'ips'                        => $this->ips,
            'authorizationType'          => $this->authorizationType,
            'authorizationConfiguration' => $this->authorizationConfiguration,
            'userId'                     => $this->userId,
            'created'                    => $this->getCreatedFormatted(),
            'updated'                    => $this->getUpdatedFormatted(),
        ];

    }//end jsonSerialize()
}//end class
