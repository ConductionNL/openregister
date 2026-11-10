<?php

/**
 * OpenRegister RecordTemplate
 *
 * A named set of values a user keeps for starting a new record of one schema,
 * private, shared with groups or public, like a saved view.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A record template: values for a new record of one schema.
 *
 * The values are data, not a half-saved object (design D-2): a record started
 * from a template is saved through the normal save path, so validation,
 * defaults and RBAC apply there once.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class RecordTemplate extends Entity implements JsonSerializable {

	/**
	 * The template's own uuid.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The name a user picks it by.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * What the template is for.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * The register id the template's schema lives in.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema id a record started from it is made in.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * The values a new record starts with, keyed by property name.
	 *
	 * Stored as `template_values`: VALUES is an SQL keyword.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $templateValues = [];

	/**
	 * The user who saved it.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * Whether everyone who may create in the schema may use it.
	 *
	 * @var boolean
	 */
	protected bool $isPublic = false;

	/**
	 * The groups it is shared with, as `{group, mode}` like a saved view.
	 *
	 * @var array<int, array{group: string, mode: string}>|null
	 */
	protected ?array $sharedWith = [];

	/**
	 * When it was saved.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When it last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * The access the CALLER holds on it, for this request only.
	 *
	 * Transient: it belongs to the pair (template, caller), not to the template.
	 *
	 * @var string|null
	 */
	private ?string $access = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'templateValues', type: 'json');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'isPublic', type: 'boolean');
		$this->addType(fieldName: 'sharedWith', type: 'json');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end __construct()

	/**
	 * The values a new record starts with.
	 *
	 * @return array<string, mixed> The values, empty when none are kept.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function getValues(): array {
		return ($this->templateValues ?? []);
	}//end getValues()

	/**
	 * Set the values a new record starts with.
	 *
	 * @param array<string, mixed> $values The values, keyed by property name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function setValues(array $values): void {
		$this->templateValues = $values;
		$this->markFieldUpdated(attribute: 'templateValues');
	}//end setValues()

	/**
	 * The groups it is shared with.
	 *
	 * @return array<int, array{group: string, mode: string}> The shares, empty when shared with nobody.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function getSharedWith(): array {
		return ($this->sharedWith ?? []);
	}//end getSharedWith()

	/**
	 * Set the groups it is shared with.
	 *
	 * @param array<int, array{group: string, mode: string}> $sharedWith The shares.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function setSharedWith(array $sharedWith): void {
		$this->sharedWith = $sharedWith;
		$this->markFieldUpdated(attribute: 'sharedWith');
	}//end setSharedWith()

	/**
	 * The access the caller holds, when somebody resolved it.
	 *
	 * @return string|null One of owner, write, read, or null when unresolved.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function getAccess(): ?string {
		return $this->access;
	}//end getAccess()

	/**
	 * Record the access a caller holds, for this request only.
	 *
	 * @param string|null $access The resolved access.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function setAccess(?string $access): void {
		$this->access = $access;
	}//end setAccess()

	/**
	 * The template as the API answers it.
	 *
	 * @return array<string, mixed> The template, with `@self.access` for the caller.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'register' => $this->register,
			'schema' => $this->schema,
			'values' => $this->getValues(),
			'owner' => $this->owner,
			'isPublic' => $this->isPublic,
			'sharedWith' => $this->getSharedWith(),
			// Absent rather than guessed when nobody resolved it, as on a view.
			'@self' => ['access' => $this->access],
			'created' => $this->created?->format(format: 'c'),
			'updated' => $this->updated?->format(format: 'c'),
		];
	}//end jsonSerialize()
}//end class
