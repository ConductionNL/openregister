<?php

/**
 * ConfigurationDraft entity — one pending value beside one live value.
 *
 * D-1 of the change: staging by cloning the configuration produces two truths
 * and a merge nobody wants. A draft is one pending value against one live
 * value, keyed the same way, so the deployment is an apply and the rollback is
 * an apply of what was there before.
 *
 * The address is (layer, layerRef, configKey). `baseValue` is the live value
 * as it stood when the draft was written: it is what makes a stale draft
 * detectable, so a set approved against one reading of the instance cannot be
 * deployed onto a different one.
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
 * Class ConfigurationDraft
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSetUuid()
 * @method void setSetUuid(?string $setUuid)
 * @method string|null getLayer()
 * @method void setLayer(?string $layer)
 * @method string|null getLayerRef()
 * @method void setLayerRef(?string $layerRef)
 * @method string|null getConfigKey()
 * @method void setConfigKey(?string $configKey)
 * @method array|null getBaseValue()
 * @method void setBaseValue(?array $baseValue)
 * @method array|null getDraftValue()
 * @method void setDraftValue(?array $draftValue)
 * @method boolean|null getBasePresent()
 * @method void setBasePresent(?bool $basePresent)
 * @method boolean|null getRemoves()
 * @method void setRemoves(?bool $removes)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ConfigurationDraft extends Entity implements JsonSerializable {

	/**
	 * The serialised fields, in response order; the key and the two values are renamed on the way out.
	 *
	 * @var list<string>
	 */
	private const JSON_FIELDS = [
		'id',
		'uuid',
		'setUuid',
		'layer',
		'layerRef',
		'configKey',
		'baseValue',
		'basePresent',
		'draftValue',
		'removes',
		'createdBy',
		'created',
		'updated',
	];
	use HydrateBySettersTrait;

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The set this pending value belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $setUuid = null;

	/**
	 * One of the ConfigurationLayer constants.
	 *
	 * @var string|null
	 */
	protected ?string $layer = null;

	/**
	 * Which register, bundle or subject the value belongs to. Null at the
	 * instance layer, which has exactly one address per key.
	 *
	 * @var string|null
	 */
	protected ?string $layerRef = null;

	/**
	 * The configuration key, spelled exactly as the live value is keyed.
	 *
	 * @var string|null
	 */
	protected ?string $configKey = null;

	/**
	 * The live value as it stood when the draft was written, wrapped so a
	 * scalar survives a JSON column: ['value' => mixed].
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $baseValue = null;

	/**
	 * The pending value, wrapped the same way.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $draftValue = null;

	/**
	 * Whether the address held a value at all when the draft was written.
	 * A key that did not exist is not the same as a key holding null.
	 *
	 * @var boolean|null
	 */
	protected ?bool $basePresent = null;

	/**
	 * Whether this draft removes the value rather than replacing it.
	 *
	 * @var boolean|null
	 */
	protected ?bool $removes = null;

	/**
	 * Who wrote the draft.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

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
		$this->addType(fieldName: 'setUuid', type: 'string');
		$this->addType(fieldName: 'layer', type: 'string');
		$this->addType(fieldName: 'layerRef', type: 'string');
		$this->addType(fieldName: 'configKey', type: 'string');
		$this->addType(fieldName: 'baseValue', type: 'json');
		$this->addType(fieldName: 'draftValue', type: 'json');
		$this->addType(fieldName: 'basePresent', type: 'boolean');
		$this->addType(fieldName: 'removes', type: 'boolean');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * The pending value, unwrapped.
	 *
	 * @return mixed The value the draft would make live.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function readDraftValue(): mixed {
		return ($this->draftValue['value'] ?? null);

	}//end readDraftValue()

	/**
	 * The live value recorded when the draft was written, unwrapped.
	 *
	 * @return mixed The value the draft was taken against.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function readBaseValue(): mixed {
		return ($this->baseValue['value'] ?? null);

	}//end readBaseValue()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised draft.
	 */
	public function jsonSerialize(): array {
		$typed = new TypedFieldsJson();
		$json = array_merge($typed->pick(values: get_object_vars($this), names: self::JSON_FIELDS), $this->servedValues());

		return $typed->renamed(json: $json, names: ['configKey' => 'key', 'baseValue' => 'base', 'draftValue' => 'value']);
	}//end jsonSerialize()

	/**
	 * The served values that are not the stored column as is: the unwrapped base and
	 * draft values, and the flags as false when unset.
	 *
	 * @return array{baseValue: mixed, basePresent: bool, draftValue: mixed, removes: bool}
	 */
	private function servedValues(): array {
		return [
			'baseValue' => $this->readBaseValue(),
			'basePresent' => ($this->basePresent ?? false),
			'draftValue' => $this->readDraftValue(),
			'removes' => ($this->removes ?? false),
		];
	}//end servedValues()
}//end class
