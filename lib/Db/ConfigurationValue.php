<?php

/**
 * ConfigurationValue entity — one live configuration value at one address.
 *
 * Two jobs. Below the instance layer it IS the value: a register, a bundle or
 * a subject has nowhere else to keep one. At the instance layer the runtime
 * value stays in Nextcloud's app config, where every existing reader already
 * looks, and this row is the PROVENANCE: which deployment last moved the
 * value, and who. That is the third of the explainer's three answers, and
 * without a row for it a value can only be reported as "unknown", which is
 * the answer REQ-CAD-003 exists to forbid.
 *
 * A key with no row has not been deployed. That is a fact, not a gap: the
 * explainer reports it as predating the first deployment.
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
 * Class ConfigurationValue
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getLayer()
 * @method void setLayer(?string $layer)
 * @method string|null getLayerRef()
 * @method void setLayerRef(?string $layerRef)
 * @method string|null getConfigKey()
 * @method void setConfigKey(?string $configKey)
 * @method array|null getConfigValue()
 * @method void setConfigValue(?array $configValue)
 * @method string|null getDeploymentUuid()
 * @method void setDeploymentUuid(?string $deploymentUuid)
 * @method string|null getBundleUuid()
 * @method void setBundleUuid(?string $bundleUuid)
 * @method string|null getUpdatedBy()
 * @method void setUpdatedBy(?string $updatedBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ConfigurationValue extends Entity implements JsonSerializable {

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * One of the ConfigurationLayer constants.
	 *
	 * @var string|null
	 */
	protected ?string $layer = null;

	/**
	 * Which register, bundle or subject this value belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $layerRef = null;

	/**
	 * The configuration key.
	 *
	 * @var string|null
	 */
	protected ?string $configKey = null;

	/**
	 * The value, wrapped so a scalar survives a JSON column:
	 * ['value' => mixed].
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $configValue = null;

	/**
	 * The deployment that last wrote this value. Null means the value was
	 * never moved by a deployment.
	 *
	 * @var string|null
	 */
	protected ?string $deploymentUuid = null;

	/**
	 * The bundle this value was inherited from, when it was. Reserved for
	 * the bundle binding; null on every value written directly.
	 *
	 * @var string|null
	 */
	protected ?string $bundleUuid = null;

	/**
	 * Who last wrote it.
	 *
	 * @var string|null
	 */
	protected ?string $updatedBy = null;

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
		$this->addType(fieldName: 'layer', type: 'string');
		$this->addType(fieldName: 'layerRef', type: 'string');
		$this->addType(fieldName: 'configKey', type: 'string');
		$this->addType(fieldName: 'configValue', type: 'json');
		$this->addType(fieldName: 'deploymentUuid', type: 'string');
		$this->addType(fieldName: 'bundleUuid', type: 'string');
		$this->addType(fieldName: 'updatedBy', type: 'string');
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
	 * The value, unwrapped.
	 *
	 * @return mixed The configured value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function readValue(): mixed {
		return ($this->configValue['value'] ?? null);

	}//end readValue()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised value.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'layer' => $this->layer,
			'layerRef' => $this->layerRef,
			'key' => $this->configKey,
			'value' => $this->readValue(),
			'deployment' => $this->deploymentUuid,
			'bundle' => $this->bundleUuid,
			'updatedBy' => $this->updatedBy,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
