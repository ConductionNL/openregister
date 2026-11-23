<?php

/**
 * RegisterBundles: the register API's bundle import, export and scope check, answered by the configuration helpers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\Core\BundleImport;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterBundlesInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Answers the register API's bundle questions with the configuration service.
 *
 * Every call is one call to the configuration service or the register/bundle
 * binding check with the same arguments the register API passed before, so
 * import, export and the scope check behave as they did.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class RegisterBundles implements RegisterBundlesInterface {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationServiceInterface  $configurations The configuration import and export.
	 * @param RegisterBundleBindingInterface $binding        Keeps a manager's bundle inside its register.
	 */
	public function __construct(
		private readonly ConfigurationServiceInterface $configurations,
		private readonly RegisterBundleBindingInterface $binding,
	) {
	}//end __construct()

	/**
	 * The bundle a request uploaded, or the error response to return.
	 *
	 * @param array<string, mixed>   $params The request parameters.
	 * @param array<int, mixed>|null $files  The uploaded files.
	 *
	 * @return array<string, mixed>|JSONResponse The decoded bundle, or the error response.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function uploadedBundle(array $params, ?array $files): array|JSONResponse {
		return $this->configurations->getUploadedJson(data: $params, uploadedFiles: $files);
	}//end uploadedBundle()

	/**
	 * Why this bundle may not be imported into this register by a manager.
	 *
	 * @param Register             $register The register the bundle was uploaded to.
	 * @param array<string, mixed> $bundle   The bundle.
	 *
	 * @return string|null The reason, or null when the bundle stays inside the register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function refusalFor(Register $register, array $bundle): ?string {
		return $this->binding->refusalFor(register: $register, bundle: $bundle);
	}//end refusalFor()

	/**
	 * Import a bundle that belongs to no stored configuration.
	 *
	 * @param array<string, mixed> $bundle The decoded bundle.
	 * @param BundleImport         $import Who imports it, for which app and version, and whether to force.
	 *
	 * @return array<string, mixed> What the import created or updated, by kind.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importBundle(array $bundle, BundleImport $import): array {
		return $this->configurations->importFromJson(
			data: $bundle,
			configuration: null,
			owner: $import->owner,
			appId: $import->appId,
			version: $import->version,
			force: $import->force
		);
	}//end importBundle()

	/**
	 * A register as a configuration bundle.
	 *
	 * @param Register $register       The register to export.
	 * @param bool     $includeObjects Whether the bundle carries the register's objects.
	 *
	 * @return array<string, mixed> The bundle.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function exportRegister(Register $register, bool $includeObjects): array {
		return $this->configurations->exportConfig(input: $register, includeObjects: $includeObjects);
	}//end exportRegister()
}//end class
