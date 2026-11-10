<?php

/**
 * ConfigurationDeployment entity — one applied configuration set, recorded.
 *
 * The record is the half of the change that makes a rollback possible: it
 * carries, per changed address, the value that was there before and the value
 * that replaced it. Restoring is then an apply of the `previous` column rather
 * than a guess reconstructed from an audit line.
 *
 * The table is append-only (D-3). A rollback is a NEW row carrying
 * `restoresUuid`, never an edit or a delete of the row it undoes, so the
 * record of the weekend the instance was broken survives the fix.
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
 * Class ConfigurationDeployment
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getSetUuid()
 * @method void setSetUuid(?string $setUuid)
 * @method string|null getAuthor()
 * @method void setAuthor(?string $author)
 * @method string|null getApprover()
 * @method void setApprover(?string $approver)
 * @method string|null getDeployedBy()
 * @method void setDeployedBy(?string $deployedBy)
 * @method DateTime|null getDeployedAt()
 * @method void setDeployedAt(?DateTime $deployedAt)
 * @method string|null getRestoresUuid()
 * @method void setRestoresUuid(?string $restoresUuid)
 * @method array|null getChanges()
 * @method void setChanges(?array $changes)
 * @method integer|null getChangeCount()
 * @method void setChangeCount(?int $changeCount)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ConfigurationDeployment extends Entity implements JsonSerializable {
	use HydrateBySettersTrait;

	/**
	 * Every value in the set is live.
	 *
	 * @var string
	 */
	public const STATE_APPLIED = 'applied';

	/**
	 * A deployment that restored an earlier one.
	 *
	 * @var string
	 */
	public const STATE_ROLLED_BACK = 'rolled-back';

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The name the deployment is known by.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * The draft set this deployment published, when it published one. A
	 * rollback has no set: its values come from the deployment it restores.
	 *
	 * @var string|null
	 */
	protected ?string $setUuid = null;

	/**
	 * The author of the set that was deployed.
	 *
	 * @var string|null
	 */
	protected ?string $author = null;

	/**
	 * The principal who approved the set, when an approval was required.
	 *
	 * @var string|null
	 */
	protected ?string $approver = null;

	/**
	 * Who pressed deploy.
	 *
	 * @var string|null
	 */
	protected ?string $deployedBy = null;

	/**
	 * The moment the values went live.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deployedAt = null;

	/**
	 * The deployment this one restores, when it is a rollback.
	 *
	 * @var string|null
	 */
	protected ?string $restoresUuid = null;

	/**
	 * The values this deployment changed. One entry per address:
	 * layer, layerRef, key, previous, previousPresent, value, removes.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	protected ?array $changes = null;

	/**
	 * How many addresses moved, denormalised so a history list needs no walk
	 * of the changes column.
	 *
	 * @var integer|null
	 */
	protected ?int $changeCount = null;

	/**
	 * One of the STATE_* constants.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'setUuid', type: 'string');
		$this->addType(fieldName: 'author', type: 'string');
		$this->addType(fieldName: 'approver', type: 'string');
		$this->addType(fieldName: 'deployedBy', type: 'string');
		$this->addType(fieldName: 'deployedAt', type: 'datetime');
		$this->addType(fieldName: 'restoresUuid', type: 'string');
		$this->addType(fieldName: 'changes', type: 'json');
		$this->addType(fieldName: 'changeCount', type: 'integer');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this deployment restores an earlier one.
	 *
	 * @return boolean True when it names a deployment it restores.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function isRollback(): bool {
		return $this->restoresUuid !== null;

	}//end isRollback()

	/**
	 * The changed addresses, always an array.
	 *
	 * @return array<int, array<string, mixed>> The changes.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function readChanges(): array {
		return ($this->changes ?? []);

	}//end readChanges()

	/**
	 * Convert the entity to a JSON serialisable array.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$typed = new TypedFieldsJson();
		$json = $typed->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));
		$json = array_merge($json, ['changes' => $this->readChanges(), 'changeCount' => ($this->changeCount ?? 0)]);
		$json = $typed->renamed(json: $json, names: ['restoresUuid' => 'restores']);

		return $typed->inserted(json: $json, entriesAfter: ['restores' => ['isRollback' => $this->isRollback()]]);
	}//end jsonSerialize()
}//end class
