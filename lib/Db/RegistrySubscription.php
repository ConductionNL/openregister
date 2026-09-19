<?php

/**
 * The registry subscription state for one object (finding B22).
 *
 * One row per object that declared `x-openregister-registry` and requested
 * a subscription, keyed by object uuid (design.md D-2 of
 * `registry-subscriptions`). Holds subscription state and freshness only —
 * never a copy of the subscribed person or company's data. The object
 * OpenRegister already stores, in whichever app's register declared the
 * schema, is the only copy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One object's registry subscription state.
 *
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getRegistry()
 * @method void setRegistry(?string $registry)
 * @method string|null getIdentityValue()
 * @method void setIdentityValue(?string $identityValue)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method DateTime|null getLastUpdateAt()
 * @method void setLastUpdateAt(?DateTime $lastUpdateAt)
 * @method string|null getLastUpdateSource()
 * @method void setLastUpdateSource(?string $lastUpdateSource)
 * @method string|null getRefusalReason()
 * @method void setRefusalReason(?string $refusalReason)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
class RegistrySubscription extends Entity implements JsonSerializable {

	/**
	 * No subscription has ever been requested.
	 */
	public const STATE_NONE = 'none';

	/**
	 * A user requested a subscription; the connector has not confirmed yet.
	 */
	public const STATE_REQUESTED = 'requested';

	/**
	 * The connector confirmed the subscription is live at the source.
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * A user ended the subscription.
	 */
	public const STATE_ENDED = 'ended';

	/**
	 * The connector reported the subscription could not be established.
	 */
	public const STATE_FAILED = 'failed';

	/**
	 * The uuid of the object this row tracks.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register the object lives in.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema the object was saved against.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * The registry id (`brp`, `kvk`, ...) from the schema's
	 * `x-openregister-registry.registry`.
	 *
	 * @var string|null
	 */
	protected ?string $registry = null;

	/**
	 * The object's identity value at the registry (the BSN, KvK number,
	 * ...), read from the property named by `x-openregister-registry.identity`
	 * at request time.
	 *
	 * @var string|null
	 */
	protected ?string $identityValue = null;

	/**
	 * One of the STATE_* constants.
	 *
	 * @var string|null
	 */
	protected ?string $state = self::STATE_NONE;

	/**
	 * When the object's owned properties were last updated by the registry.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastUpdateAt = null;

	/**
	 * The registry's own event reference for the last update.
	 *
	 * @var string|null
	 */
	protected ?string $lastUpdateSource = null;

	/**
	 * The connector's error text when `state` is `failed`.
	 *
	 * @var string|null
	 */
	protected ?string $refusalReason = null;

	/**
	 * When this row was first created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When this row last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updatedAt = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'registry', type: 'string');
		$this->addType(fieldName: 'identityValue', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'lastUpdateAt', type: 'datetime');
		$this->addType(fieldName: 'lastUpdateSource', type: 'string');
		$this->addType(fieldName: 'refusalReason', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * The `@self.registry` render mirror for this row.
	 *
	 * @return array<string, mixed> The shape `registry-subscriptions` REQ 2 names.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function toSelfMirror(): array {
		return [
			'registry' => $this->registry,
			'state' => ($this->state ?? self::STATE_NONE),
			'lastUpdate' => $this->lastUpdateAt?->format('c'),
			'lastUpdateSource' => $this->lastUpdateSource,
			'refusalReason' => $this->refusalReason,
		];
	}//end toSelfMirror()

	/**
	 * Serialise for diagnostics.
	 *
	 * @return array<string, mixed> The row as plain data.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'registry' => $this->registry,
			'identityValue' => $this->identityValue,
			'state' => $this->state,
			'lastUpdateAt' => $this->lastUpdateAt?->format('c'),
			'lastUpdateSource' => $this->lastUpdateSource,
			'refusalReason' => $this->refusalReason,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
