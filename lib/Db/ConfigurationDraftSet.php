<?php

/**
 * ConfigurationDraftSet entity — a set of pending configuration values.
 *
 * A set is what gets deployed. It carries the author, the optional approval,
 * and the state that says whether it is still being edited, cleared for
 * deployment, already deployed, or abandoned. The pending values themselves
 * live in {@see ConfigurationDraft}, one row per address, so a set that
 * touches nine values is nine small rows rather than one blob nobody can
 * query.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ConfigurationDraftSet
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 * @method string|null getDeploymentUuid()
 * @method void setDeploymentUuid(?string $deploymentUuid)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ConfigurationDraftSet extends Entity implements JsonSerializable {

	/**
	 * Still being edited. Values may be added, replaced and removed.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * Approved by a principal, and cleared for deployment.
	 *
	 * @var string
	 */
	public const STATE_APPROVED = 'approved';

	/**
	 * Deployed. The set is closed and its values are live.
	 *
	 * @var string
	 */
	public const STATE_DEPLOYED = 'deployed';

	/**
	 * Abandoned without ever being deployed.
	 *
	 * @var string
	 */
	public const STATE_DISCARDED = 'discarded';

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The name an operator recognises the set by.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * What the set is for, in the author's own words.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * One of the STATE_* constants.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * The author of the set.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * The principal who approved it, when an approval was given.
	 *
	 * @var string|null
	 */
	protected ?string $approvedBy = null;

	/**
	 * The moment of approval.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $approvedAt = null;

	/**
	 * The deployment that published this set, once it has one.
	 *
	 * @var string|null
	 */
	protected ?string $deploymentUuid = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-update timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'approvedBy', type: 'string');
		$this->addType(fieldName: 'approvedAt', type: 'datetime');
		$this->addType(fieldName: 'deploymentUuid', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Hydrate the entity from an array.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore properties this entity does not carry.
			}
		}

		return $this;

	}//end hydrate()

	/**
	 * Whether the set may still be edited.
	 *
	 * @return boolean True while the set is open.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function isEditable(): bool {
		return $this->state === self::STATE_OPEN;

	}//end isEditable()

	/**
	 * Whether the set has been approved.
	 *
	 * @return boolean True once an approver is recorded.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function isApproved(): bool {
		return ($this->state === self::STATE_APPROVED && $this->approvedBy !== null);

	}//end isApproved()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised set.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'state' => $this->state,
			'createdBy' => $this->createdBy,
			'approvedBy' => $this->approvedBy,
			'approvedAt' => $this->approvedAt?->format(DateTime::ATOM),
			'deploymentUuid' => $this->deploymentUuid,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
