<?php

/**
 * A whole configuration SET as a shareable configuration type.
 *
 * Where {@see RegisterSchemaShareableConfigType} shares one register, a
 * configuration set is an app's worth of configuration at once — registers,
 * schemas, objects, views, flows, sources and mappings — the same multi-entity
 * bundle OpenBuild ships as an "app". OpenRegister already models this as a
 * {@see \OCA\OpenRegister\Db\Configuration}, and `ConfigurationService` already
 * exports it as one portable, slug-keyed OpenAPI document and imports it back.
 * This type exposes that through the federated-config seam, so a config set
 * travels — signed, broker-published, discoverable — the same way a single flow
 * or a marked schema does.
 *
 * A set is published as one file in a repo; a repo may hold many such files (many
 * sets), which is exactly the "config-set repo" shape. The repo may also carry an
 * app's own scaffolding (info.xml, bundle) — this type only reads/writes the
 * configuration document, never the surrounding app files.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config\Types;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\ConfigurationService;
use Throwable;
use UnexpectedValueException;

/**
 * Serialises and installs a whole configuration set through the engine.
 */
class ConfigSetShareableConfigType implements IShareableConfigType {
	/**
	 * Constructor.
	 *
	 * @param ConfigurationMapper $configurationMapper Loads the configuration set to share.
	 * @param ConfigurationService $configService Exports and imports the set.
	 */
	public function __construct(
		private readonly ConfigurationMapper $configurationMapper,
		private readonly ConfigurationService $configService,
	) {

	}//end __construct()

	/**
	 * The type id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.configset';
	}//end getId()

	/**
	 * The display name.
	 *
	 * @return string The name.
	 */
	public function getDisplayName(): string {
		return 'Configuration set';
	}//end getDisplayName()

	/**
	 * The discovery topic.
	 *
	 * @return string The topic.
	 */
	public function getTopic(): string {
		return 'openregister-configset';
	}//end getTopic()

	/**
	 * Package a whole configuration set into a portable bundle.
	 *
	 * `$selection` is `{configuration: id}` (a Configuration id) or `{app: appId}`
	 * (the configuration an app owns), plus optional `{includeObjects?: bool}`.
	 *
	 * @param array $selection `{configuration|app, includeObjects?}`.
	 *
	 * @return array The exported multi-entity configuration bundle.
	 *
	 * @throws UnexpectedValueException When no set is named or found.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function serialise(array $selection): array {
		$configuration = $this->resolve(selection: $selection);

		return $this->configService->exportConfig(
			input: $configuration,
			includeObjects: (bool)($selection['includeObjects'] ?? false)
		);

	}//end serialise()

	/**
	 * Install a configuration set into this instance.
	 *
	 * Uses the generic JSON import (idempotent, slug-matched) so a set spanning
	 * several registers/schemas/objects/views installs as one unit.
	 *
	 * @param array $bundle A configuration bundle produced by this type.
	 *
	 * @return array The import result.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function deserialise(array $bundle): array {
		return $this->configService->importFromJson(
			data: $bundle,
			version: (string)($bundle['info']['version'] ?? '1.0.0'),
			force: false
		);

	}//end deserialise()

	/**
	 * Resolve the Configuration a selection points at.
	 *
	 * @param array $selection `{configuration|app}`.
	 *
	 * @return \OCA\OpenRegister\Db\Configuration The configuration set.
	 *
	 * @throws UnexpectedValueException When nothing is named or found.
	 */
	private function resolve(array $selection) {
		$id = trim((string)($selection['configuration'] ?? ''));
		if ($id !== '' && ctype_digit($id) === true) {
			try {
				return $this->configurationMapper->find(id: (int)$id);
			} catch (Throwable $e) {
				throw new UnexpectedValueException(sprintf('No configuration set "%s".', $id));
			}
		}

		$app = trim((string)($selection['app'] ?? ''));
		if ($app !== '') {
			$found = $this->configurationMapper->findByApp(app: $app);
			if ($found !== []) {
				return $found[0];
			}

			throw new UnexpectedValueException(sprintf('No configuration set for app "%s".', $app));
		}

		throw new UnexpectedValueException('Sharing a configuration set needs a configuration id or an app.');
	}//end resolve()
}//end class
