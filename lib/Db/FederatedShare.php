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
use OCA\OpenRegister\Db\FederatedShare\FederatedShareFieldTypes;
use OCP\AppFramework\Db\Entity;

/**
 * FederatedShare entity class.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getDirection()
 * @method void setDirection(?string $direction)
 * @method string|null getRemoteInstanceUrl()
 * @method void setRemoteInstanceUrl(?string $remoteInstanceUrl)
 * @method string|null getRemoteProviderId()
 * @method void setRemoteProviderId(?string $remoteProviderId)
 * @method string|null getShareToken()
 * @method void setShareToken(?string $shareToken)
 * @method string|null getScope()
 * @method void setScope(?string $scope)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getObjectUri()
 * @method void setObjectUri(?string $objectUri)
 * @method array|null getQueryFilter()
 * @method void setQueryFilter(?array $queryFilter)
 * @method string|null getPermissions()
 * @method void setPermissions(?string $permissions)
 * @method string|null getSharedWith()
 * @method void setSharedWith(?string $sharedWith)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class FederatedShare extends Entity implements JsonSerializable {
	use JsonFieldsTrait;
	use OrganisationFieldTrait;
	use HydrateEmptyJsonAsNullTrait;

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
	 * User id of the creator of an outgoing share (empty on shares created before it was recorded).
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

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
	public function __construct() {
		foreach (FederatedShareFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Serialize the entity to a JSON-ready array.
	 *
	 * @return array<string, mixed> The serialized share.
	 */
	public function jsonSerialize(): array {
		// Every stored field except the owner, in registration order.
		$names = array_diff(array_keys($this->getFieldTypes()), ['owner']);

		return (new TypedFieldsJson())->pick(values: get_object_vars($this), names: $names);
	}//end jsonSerialize()
}//end class
