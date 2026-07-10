<?php
/**
 * OpenRegister FederatedShare entity.
 *
 * A FederatedShare records one cross-instance sharing relationship for
 * OpenRegister data: either an OUTGOING grant (this organisation shares a
 * register / schema / object / query with an organisation on another Nextcloud
 * instance) or an INCOMING grant (a remote instance shared data with an
 * organisation here). The scoped bearer `shareToken` authorises the federated
 * read/write endpoint, and `remoteInstanceUrl` is the peer's OpenRegister base
 * URL that a FederatedObjectSourceProvider proxies against.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * FederatedShare entity class.
 *
 * @method string|null      getUuid()
 * @method void             setUuid(?string $uuid)
 * @method string|null      getDirection()
 * @method void             setDirection(?string $direction)
 * @method string|null      getRemoteInstanceUrl()
 * @method void             setRemoteInstanceUrl(?string $remoteInstanceUrl)
 * @method string|null      getRemoteProviderId()
 * @method void             setRemoteProviderId(?string $remoteProviderId)
 * @method string|null      getShareToken()
 * @method void             setShareToken(?string $shareToken)
 * @method string|null      getScope()
 * @method void             setScope(?string $scope)
 * @method string|null      getRegister()
 * @method void             setRegister(?string $register)
 * @method string|null      getSchema()
 * @method void             setSchema(?string $schema)
 * @method string|null      getObjectUri()
 * @method void             setObjectUri(?string $objectUri)
 * @method array|null       getQueryFilter()
 * @method void             setQueryFilter(?array $queryFilter)
 * @method string|null      getPermissions()
 * @method void             setPermissions(?string $permissions)
 * @method string|null      getSharedWith()
 * @method void             setSharedWith(?string $sharedWith)
 * @method string|null      getStatus()
 * @method void             setStatus(?string $status)
 * @method DateTime|null    getCreated()
 * @method void             setCreated(?DateTime $created)
 * @method DateTime|null    getUpdated()
 * @method void             setUpdated(?DateTime $updated)
 */
class FederatedShare extends Entity implements JsonSerializable
{

    /**
     * Public UUID of the share.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * Direction: 'outgoing' (we shared out) or 'incoming' (shared with us).
     *
     * @var string|null
     */
    protected ?string $direction = null;

    /**
     * Base URL of the peer OpenRegister instance (e.g. https://fed2.example).
     *
     * @var string|null
     */
    protected ?string $remoteInstanceUrl = null;

    /**
     * OCM provider id issued by the remote for this share (nullable).
     *
     * @var string|null
     */
    protected ?string $remoteProviderId = null;

    /**
     * Scoped bearer token authorising the federated serving endpoint.
     *
     * @var string|null
     */
    protected ?string $shareToken = null;

    /**
     * Share scope: 'register' | 'schema' | 'object' | 'query'.
     *
     * @var string|null
     */
    protected ?string $scope = null;

    /**
     * Register id or slug the share covers (nullable for object shares).
     *
     * @var string|null
     */
    protected ?string $register = null;

    /**
     * Schema id or slug the share covers (nullable for register-wide shares).
     *
     * @var string|null
     */
    protected ?string $schema = null;

    /**
     * Canonical object URI for an object-scope share (nullable otherwise).
     *
     * @var string|null
     */
    protected ?string $objectUri = null;

    /**
     * Query filter for a 'query'-scope (rule/flow) share (nullable otherwise).
     *
     * @var array|null
     */
    protected ?array $queryFilter = null;

    /**
     * Granted permissions: 'read' or 'read-write'.
     *
     * @var string|null
     */
    protected ?string $permissions = null;

    /**
     * Local owning organisation UUID.
     *
     * @var string|null
     */
    protected ?string $organisation = null;

    /**
     * Target federated address ('org-slug@host').
     *
     * @var string|null
     */
    protected ?string $sharedWith = null;

    /**
     * Lifecycle status: 'pending' | 'accepted' | 'declined' | 'revoked'.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * Last-updated timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * Constructor: registers field types.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'direction', type: 'string');
        $this->addType(fieldName: 'remoteInstanceUrl', type: 'string');
        $this->addType(fieldName: 'remoteProviderId', type: 'string');
        $this->addType(fieldName: 'shareToken', type: 'string');
        $this->addType(fieldName: 'scope', type: 'string');
        $this->addType(fieldName: 'register', type: 'string');
        $this->addType(fieldName: 'schema', type: 'string');
        $this->addType(fieldName: 'objectUri', type: 'string');
        $this->addType(fieldName: 'queryFilter', type: 'json');
        $this->addType(fieldName: 'permissions', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'sharedWith', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
    }//end __construct()

    /**
     * Get the JSON field names on this entity.
     *
     * @return string[] List of JSON field names.
     *
     * @psalm-return list<string>
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                static function ($field) {
                    return $field === 'json';
                }
            )
        );
    }//end getJsonFields()

    /**
     * Get the local owning organisation UUID.
     *
     * @return string|null The organisation UUID.
     */
    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }//end getOrganisation()

    /**
     * Set the local owning organisation UUID.
     *
     * @param string|null $organisation The organisation UUID.
     *
     * @return void
     */
    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
        $this->markFieldUpdated(attribute: 'organisation');
    }//end setOrganisation()

    /**
     * Hydrate the entity from an array.
     *
     * @param array<string, mixed> $object The data array to hydrate from.
     *
     * @return static This instance for chaining.
     */
    public function hydrate(array $object): static
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === []) {
                $value = null;
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialize the entity to a JSON-ready array.
     *
     * @return array<string, mixed> The serialized share.
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'direction'         => $this->direction,
            'remoteInstanceUrl' => $this->remoteInstanceUrl,
            'remoteProviderId'  => $this->remoteProviderId,
            'shareToken'        => $this->shareToken,
            'scope'             => $this->scope,
            'register'          => $this->register,
            'schema'            => $this->schema,
            'objectUri'         => $this->objectUri,
            'queryFilter'       => $this->queryFilter,
            'permissions'       => $this->permissions,
            'organisation'      => $this->organisation,
            'sharedWith'        => $this->sharedWith,
            'status'            => $this->status,
            'created'           => $created,
            'updated'           => $updated,
        ];
    }//end jsonSerialize()
}//end class
