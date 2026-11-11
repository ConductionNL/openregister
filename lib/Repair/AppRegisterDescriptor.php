<?php

/**
 * OpenRegister app register descriptor
 *
 * One register descriptor OpenRegister ships under its own app path: read
 * and decoded with a warning naming the register when it is missing or not
 * JSON, and imported idempotently (force: false).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\Migration\IOutput;

/**
 * A shipped register descriptor, named for the warnings a seed step prints about it.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class AppRegisterDescriptor {

	/**
	 * Name the descriptor's register.
	 *
	 * @param RegisterDescriptorImport $descriptors Importer, app path and log for the descriptor.
	 * @param string                   $label       The register as the warnings name it, e.g. `Flow-timers`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly RegisterDescriptorImport $descriptors,
		private readonly string $label,
	) {
	}//end __construct()

	/**
	 * Read and decode the descriptor, or warn why it cannot be imported.
	 *
	 * @param string  $relativePath The descriptor's path under the openregister app.
	 * @param IOutput $output       Output interface for status messages.
	 *
	 * @return array|null The decoded descriptor, or null after the warning.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function read(string $relativePath, IOutput $output): ?array {
		$path = ($this->descriptors->appManager->getAppPath('openregister') . $relativePath);
		if (is_file($path) === false) {
			$output->warning($this->label . ' register descriptor not found: ' . $path);
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false) {
			$output->warning($this->label . ' register descriptor is not valid JSON: ' . $path);
			return null;
		}

		return $data;
	}//end read()

	/**
	 * Import the decoded descriptor for the openregister app.
	 *
	 * The importer takes the DECODED descriptor; force: false keeps the
	 * import idempotent and leaves administrator edits in place.
	 *
	 * @param array  $data    The decoded descriptor.
	 * @param string $version The descriptor version for the importer's version_compare gate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function import(array $data, string $version): void {
		$this->descriptors->configurationService->importFromApp(
			appId: 'openregister',
			data: $data,
			version: $version,
			force: false
		);
	}//end import()
}//end class
