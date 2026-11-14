<?php

/**
 * InstanceSetSerialiser: an instance's registers, schemas, objects and configuration as one portable set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use Throwable;

/**
 * Serialises the registers the caller can read, each through the existing
 * configuration export with its schemas and objects, plus the app's own
 * configuration, into one portable set (D-5).
 *
 * Secrets are left out and the set says so: `secrets.excluded` is always
 * true and `secrets.paths` lists every entry that was left out, so a set
 * without a credential is never read as an instance that had none. Schema
 * definitions are kept whole (a property named `password` is a field, not a
 * secret); the filter runs over the configuration, the register itself and
 * every other export section, objects included.
 *
 * Each register carries its own outcome: one that fails to export is
 * reported with its error and the others are still serialised.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */
class InstanceSetSerialiser {

	/**
	 * The set's format name, so a loader can refuse what is not one.
	 */
	public const FORMAT = 'openregister-instance-set';

	/**
	 * The set's format version.
	 */
	public const VERSION = 1;

	/**
	 * The app whose configuration the set carries.
	 */
	private const APP = 'openregister';

	/**
	 * Export sections kept whole: definitions, not values.
	 *
	 * @var list<string>
	 */
	private const DEFINITION_SECTIONS = ['schemas'];

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registerMapper Lists the registers to serialise.
	 * @param ExportHandler           $exportHandler  Exports one register with its schemas and objects.
	 * @param InstanceAppConfigReader $configReader   Reads the app configuration without secrets.
	 * @param InstanceSecretFilter    $filter         Leaves secret values out of the exports.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly ExportHandler $exportHandler,
		private readonly InstanceAppConfigReader $configReader,
		private readonly InstanceSecretFilter $filter,
	) {
	}//end __construct()

	/**
	 * The portable set.
	 *
	 * @return array{
	 *     format: string,
	 *     version: int,
	 *     registers: array<string, array<string, mixed>>,
	 *     outcome: list<array{register: string, status: string, objects?: int, error?: string}>,
	 *     configuration: array<string, mixed>,
	 *     secrets: array{excluded: true, paths: list<string>, note: string}
	 * }
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
	 */
	public function serialise(): array {
		$registers = [];
		$outcome   = [];
		$excluded  = [];
		foreach ($this->registerMapper->findAll() as $register) {
			$name = $this->nameOf(register: $register);
			try {
				$export = $this->exportHandler->exportConfig(input: $register, includeObjects: true);
			} catch (Throwable $error) {
				$outcome[] = ['register' => $name, 'status' => 'failed', 'error' => $error->getMessage()];
				continue;
			}

			[$registers[$name], $inner] = $this->withoutSecrets(export: $export, path: 'registers.'.$name);
			$excluded  = array_merge($excluded, $inner);
			$outcome[] = [
				'register' => $name,
				'status'   => 'exported',
				'objects'  => $this->objectCount(export: $export),
			];
		}

		[$configuration, $configExcluded] = $this->configReader->read(app: self::APP);

		return [
			'format'        => self::FORMAT,
			'version'       => self::VERSION,
			'registers'     => $registers,
			'outcome'       => $outcome,
			'configuration' => $configuration,
			'secrets'       => [
				'excluded' => true,
				'paths'    => array_merge($excluded, $configExcluded),
				'note'     => 'Secret values were left out of this set; supply them again after loading it.',
			],
		];
	}//end serialise()

	/**
	 * One register export without secrets, its schema definitions kept whole.
	 *
	 * @param array<string, mixed> $export The register's configuration export.
	 * @param string               $path   The export's path in the set.
	 *
	 * @return array{0: array<string, mixed>, 1: list<string>} The cleaned export and the excluded paths.
	 */
	private function withoutSecrets(array $export, string $path): array {
		$components = $export['components'] ?? null;
		unset($export['components']);
		[$clean, $excluded] = $this->filter->strip(node: $export, path: $path);
		if (is_array($components) === false) {
			return [$clean, $excluded];
		}

		$clean['components'] = [];
		foreach ($components as $section => $entries) {
			if (in_array($section, self::DEFINITION_SECTIONS, true) === true || is_array($entries) === false) {
				$clean['components'][$section] = $entries;
				continue;
			}

			[$clean['components'][$section], $inner] = $this->filter->strip(node: $entries, path: $path.'.components.'.$section);
			$excluded = array_merge($excluded, $inner);
		}

		return [$clean, $excluded];
	}//end withoutSecrets()

	/**
	 * How many objects an export carries, across its schemas.
	 *
	 * @param array<string, mixed> $export The register's configuration export.
	 *
	 * @return int
	 */
	private function objectCount(array $export): int {
		$objects = $export['components']['objects'] ?? [];
		if (is_array($objects) === false) {
			return 0;
		}

		return count($objects);
	}//end objectCount()

	/**
	 * The name a register is filed under in the set: its slug, else its id.
	 *
	 * @param Register $register The register.
	 *
	 * @return string
	 */
	private function nameOf(Register $register): string {
		$slug = $register->getSlug();
		if ($slug !== null && $slug !== '') {
			return $slug;
		}

		return (string) $register->getId();
	}//end nameOf()
}//end class
