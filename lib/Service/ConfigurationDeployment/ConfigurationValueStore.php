<?php

/**
 * ConfigurationValueStore — read and write one configuration address.
 *
 * At the instance layer the runtime value stays in Nextcloud's app config,
 * where `ConfigurationSettingsHandler` and every other reader already look. A
 * second copy would be a second truth, and the one the readers do not consult
 * would be the one this change wrote. The row in `openregister_config_values`
 * therefore carries the PROVENANCE at that layer, not the authority.
 *
 * Below the instance layer there is no app config to speak of, so the row is
 * both.
 *
 * The app-config half is not transactional. Every write therefore returns an
 * undo entry, and {@see restore} puts the previous strings back if the
 * surrounding transaction rolls back. That is the compensating half of D-2:
 * without it a deployment could roll its rows back and leave its app-config
 * values applied, which is the partial state D-2 exists to forbid.
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

use OCA\OpenRegister\Db\ConfigurationValue;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCP\IAppConfig;

/**
 * The live value at one address.
 */
class ConfigurationValueStore {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationValueMapper $values    The layered value rows.
	 * @param IAppConfig               $appConfig Nextcloud's app configuration.
	 * @param string                   $appName   The app the instance keys live under.
	 */
	public function __construct(
		private readonly ConfigurationValueMapper $values,
		private readonly IAppConfig $appConfig,
		private readonly string $appName = 'openregister'
	) {

	}//end __construct()

	/**
	 * Read one address.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference, null at instance level.
	 * @param string      $configKey The configuration key.
	 *
	 * @return ConfigurationSnapshot What the address holds, and where it came from.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function read(string $layer, ?string $layerRef, string $configKey): ConfigurationSnapshot {
		$row = $this->values->findAtAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey);

		if ($layer !== ConfigurationLayer::INSTANCE) {
			if ($row === null) {
				return ConfigurationSnapshot::absent(
					layer: $layer,
					layerRef: $layerRef,
					configKey: $configKey
				);
			}

			return new ConfigurationSnapshot(
				layer: $layer,
				layerRef: $layerRef,
				configKey: $configKey,
				value: $row->readValue(),
				present: true,
				deploymentUuid: $row->getDeploymentUuid(),
				updatedBy: $row->getUpdatedBy()
			);
		}//end if

		// The instance layer: app config is the authority, the row is the
		// provenance. A key app config does not hold is absent even when a
		// provenance row survives, because the reader would see nothing.
		if ($this->appConfig->hasKey($this->appName, $configKey) === false) {
			return ConfigurationSnapshot::absent(
				layer: $layer,
				layerRef: null,
				configKey: $configKey
			);
		}

		return new ConfigurationSnapshot(
			layer: $layer,
			layerRef: null,
			configKey: $configKey,
			value: $this->decode(raw: $this->appConfig->getValueString($this->appName, $configKey, '')),
			present: true,
			deploymentUuid: $row?->getDeploymentUuid(),
			updatedBy: $row?->getUpdatedBy()
		);

	}//end read()

	/**
	 * Write one address, returning what it takes to put it back.
	 *
	 * @param string      $layer          The layer.
	 * @param string|null $layerRef       The layer reference.
	 * @param string      $configKey      The configuration key.
	 * @param mixed       $value          The value to make live.
	 * @param string|null $deploymentUuid The deployment doing the writing.
	 * @param string|null $actor          Who is doing the writing.
	 *
	 * @return array<string, mixed> The undo entry for this write.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function write(
		string $layer,
		?string $layerRef,
		string $configKey,
		mixed $value,
		?string $deploymentUuid,
		?string $actor
	): array {
		$undo = $this->undoEntryFor(layer: $layer, configKey: $configKey);

		$row = $this->values->findAtAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		if ($row === null) {
			$this->values->createFromArray(
				[
					'layer' => $layer,
					'layerRef' => $layerRef,
					'configKey' => $configKey,
					'configValue' => ['value' => $value],
					'deploymentUuid' => $deploymentUuid,
					'updatedBy' => $actor,
				]
			);
		} else {
			$row->setConfigValue(['value' => $value]);
			$row->setDeploymentUuid($deploymentUuid);
			$row->setUpdatedBy($actor);
			$this->values->save($row);
		}

		if ($layer === ConfigurationLayer::INSTANCE) {
			$this->appConfig->setValueString($this->appName, $configKey, $this->encode(value: $value));
		}

		return $undo;

	}//end write()

	/**
	 * Remove one address, returning what it takes to put it back.
	 *
	 * @param string      $layer     The layer.
	 * @param string|null $layerRef  The layer reference.
	 * @param string      $configKey The configuration key.
	 *
	 * @return array<string, mixed> The undo entry for this removal.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function remove(string $layer, ?string $layerRef, string $configKey): array {
		$undo = $this->undoEntryFor(layer: $layer, configKey: $configKey);

		$row = $this->values->findAtAddress(layer: $layer, layerRef: $layerRef, configKey: $configKey);
		if ($row !== null) {
			$this->values->remove($row);
		}

		if ($layer === ConfigurationLayer::INSTANCE) {
			$this->appConfig->deleteKey($this->appName, $configKey);
		}

		return $undo;

	}//end remove()

	/**
	 * Put the app-config side of a run of writes back.
	 *
	 * The row side is put back by the surrounding transaction. This half is
	 * not transactional, so it is compensated by hand, newest entry first.
	 *
	 * @param array<int, array<string, mixed>> $undoEntries The entries returned by write and remove.
	 *
	 * @return integer How many addresses were put back.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function restore(array $undoEntries): int {
		$restored = 0;

		foreach (array_reverse($undoEntries) as $entry) {
			if (($entry['layer'] ?? '') !== ConfigurationLayer::INSTANCE) {
				continue;
			}

			$configKey = (string)($entry['key'] ?? '');
			if ($configKey === '') {
				continue;
			}

			if (($entry['present'] ?? false) === true) {
				$this->appConfig->setValueString($this->appName, $configKey, (string)($entry['raw'] ?? ''));
			} else {
				$this->appConfig->deleteKey($this->appName, $configKey);
			}

			$restored++;
		}//end foreach

		return $restored;

	}//end restore()

	/**
	 * The app-config state of one address, before it is written.
	 *
	 * @param string $layer     The layer.
	 * @param string $configKey The configuration key.
	 *
	 * @return array<string, mixed> The undo entry.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function undoEntryFor(string $layer, string $configKey): array {
		if ($layer !== ConfigurationLayer::INSTANCE) {
			return ['layer' => $layer, 'key' => $configKey, 'present' => false, 'raw' => ''];
		}

		$present = $this->appConfig->hasKey($this->appName, $configKey);

		return [
			'layer' => $layer,
			'key' => $configKey,
			'present' => $present,
			'raw' => match ($present) {
				true => $this->appConfig->getValueString($this->appName, $configKey, ''),
				false => '',
			},
		];

	}//end undoEntryFor()

	/**
	 * Encode a value the way the settings handlers store it.
	 *
	 * Objects and lists go in as JSON, which is what every existing reader
	 * json_decodes. Scalars go in as the string they already are, because a
	 * reader calling getValueBool on `true` would read `"true"` as a string
	 * and get it right, while `"1"` json-encoded to `1` would still be right
	 * and json-encoding a string would wrap it in quotes nothing strips.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The app-config string.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function encode(mixed $value): string {
		if (is_array($value) === true) {
			return (string)json_encode($value);
		}

		if (is_bool($value) === true) {
			return match ($value) {
				true => '1',
				false => '0',
			};
		}

		if ($value === null) {
			return '';
		}

		return (string)$value;

	}//end encode()

	/**
	 * Decode an app-config string back to the value the settings surface uses.
	 *
	 * @param string $raw The stored string.
	 *
	 * @return mixed The decoded value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	private function decode(string $raw): mixed {
		if ($raw === '') {
			return '';
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $raw;

	}//end decode()
}//end class
