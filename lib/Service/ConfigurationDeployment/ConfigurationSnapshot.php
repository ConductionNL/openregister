<?php

/**
 * ConfigurationSnapshot — what one address holds, and where it came from.
 *
 * `present` is not the same as a non-null value. A key that was never set and
 * a key explicitly set to null are different facts, and a rollback that
 * cannot tell them apart recreates a key the earlier state did not have.
 *
 * `deploymentUuid` being null is also a fact rather than a gap: it means no
 * deployment ever moved this value, which is what REQ-CAD-003 asks to be
 * reported as predating the first deployment.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
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

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use JsonSerializable;

/**
 * One address, read.
 */
final class ConfigurationSnapshot implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string      $layer          The layer read.
	 * @param string|null $layerRef       The layer reference, null at instance level.
	 * @param string      $configKey      The configuration key.
	 * @param mixed       $value          The value held, or null when none is.
	 * @param boolean     $present        Whether the address holds a value at all.
	 * @param string|null $deploymentUuid The deployment that last moved it.
	 * @param string|null $updatedBy      Who last moved it.
	 */
	public function __construct(
		public readonly string $layer,
		public readonly ?string $layerRef,
		public readonly string $configKey,
		public readonly mixed $value,
		public readonly bool $present,
		public readonly ?string $deploymentUuid = null,
		public readonly ?string $updatedBy = null
	) {

	}//end __construct()

	/**
	 * An address holding nothing.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference.
	 * @param string      $configKey The configuration key.
	 *
	 * @return self The empty snapshot.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public static function absent(string $layer, ?string $layerRef, string $configKey): self {
		return new self(
			layer: $layer,
			layerRef: $layerRef,
			configKey: $configKey,
			value: null,
			present: false
		);

	}//end absent()

	/**
	 * Whether the value was last moved by a deployment.
	 *
	 * @return boolean True when a deployment owns this value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function hasProvenance(): bool {
		return $this->deploymentUuid !== null;

	}//end hasProvenance()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised snapshot.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'layer' => $this->layer,
			'layerRef' => $this->layerRef,
			'key' => $this->configKey,
			'value' => $this->value,
			'present' => $this->present,
			'deployment' => $this->deploymentUuid,
			'updatedBy' => $this->updatedBy,
		];

	}//end jsonSerialize()
}//end class
