<?php

/**
 * DeclaredProviderLoader — discovers app-declared credential providers.
 *
 * Walks the installed (enabled) apps and reads `lib/Settings/credential-providers.json`
 * from each app's OWN directory, read-only. Every entry is validated by
 * {@see DeclarationValidator}; a file that cannot be read, is not provider JSON, or
 * holds any entry that fails validation contributes NO admissible entry, so a broken
 * declaration denies rather than half-admits. OpenRegister's own file is the reviewed
 * catalogue and is never read as a declaration. Results are cached for the request.
 *
 * Nothing here admits a provider: it only reports what each app declared and whether
 * the declaration is well-formed. {@see DeclaredProviderResolver} decides admission.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-declarations-are-discovered-from-shipped-app-config-only
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only discovery of the provider declarations installed apps ship.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-declarations-are-discovered-from-shipped-app-config-only
 */
class DeclaredProviderLoader {

	/**
	 * App-relative path of a declaration file.
	 *
	 * @var string
	 */
	private const DECLARATION_PATH = '/lib/Settings/credential-providers.json';

	/**
	 * The app whose file at that path is the reviewed catalogue, not a declaration.
	 *
	 * @var string
	 */
	private const CATALOGUE_APP = 'openregister';

	/**
	 * Discovered declarations keyed by identifier, or null before the first walk.
	 *
	 * @var array<string, array{app: string, entry: array<string, mixed>, valid: bool, reason: string, lane: string}>|null
	 */
	private ?array $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager The installed apps and their paths.
	 * @param ProviderCatalogue    $catalogue  The reviewed catalogue an `extends` names.
	 * @param DeclarationValidator $validator  Validates each declared entry.
	 * @param LoggerInterface      $logger     Logs secret-free discovery warnings.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ProviderCatalogue $catalogue,
		private readonly DeclarationValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every discovered declaration, valid or not, keyed by identifier.
	 *
	 * @return array<string, array{app: string, entry: array<string, mixed>, valid: bool, reason: string, lane: string}>
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-declarations-are-discovered-from-shipped-app-config-only
	 */
	public function all(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}

		$this->cache = [];
		foreach ($this->installedAppIds() as $appId) {
			if ($appId === self::CATALOGUE_APP) {
				continue;
			}

			foreach ($this->loadApp(appId: $appId) as $identifier => $declaration) {
				$this->cache[$identifier] = $declaration;
			}
		}

		return $this->cache;
	}//end all()

	/**
	 * One discovered declaration, or null when no enabled app declares the identifier.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return array{app: string, entry: array<string, mixed>, valid: bool, reason: string, lane: string}|null
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-declared-providers-fail-closed-on-disable-uninstall-and-revocation
	 */
	public function get(string $identifier): ?array {
		return ($this->all()[$identifier] ?? null);
	}//end get()

	/**
	 * The declarations of one app; every entry is invalid when any one of them is.
	 *
	 * @param string $appId The app.
	 *
	 * @return array<string, array{app: string, entry: array<string, mixed>, valid: bool, reason: string, lane: string}>
	 */
	private function loadApp(string $appId): array {
		$providers = $this->readProviders(appId: $appId);
		if ($providers === null) {
			return [];
		}

		$declarations = [];
		$fileError = '';
		foreach ($providers as $key => $entry) {
			$outcome = $this->validator->validate(
				appId: $appId,
				key: (string)$key,
				entry: $entry,
				catalogue: $this->catalogue->all()
			);
			if ($outcome['valid'] === false && $fileError === '') {
				$fileError = 'entry "' . (string)$key . '": ' . $outcome['reason'];
			}

			if (is_array($entry) === true && str_starts_with((string)$key, $appId . ':') === true) {
				$declarations[(string)$key] = ['app' => $appId, 'entry' => $entry] + $outcome;
			}
		}

		if ($fileError === '') {
			return $declarations;
		}

		$this->logger->warning('[DeclaredProviderLoader] declaration of app "' . $appId . '" admits nothing: ' . $fileError);
		foreach (array_keys($declarations) as $identifier) {
			$declarations[$identifier]['valid'] = false;
			$declarations[$identifier]['lane'] = '';
			if ($declarations[$identifier]['reason'] === '') {
				$declarations[$identifier]['reason'] = 'another entry of the same file is invalid';
			}
		}

		return $declarations;
	}//end loadApp()

	/**
	 * The `providers` map an app declares, or null when it declares nothing readable.
	 *
	 * @param string $appId The app.
	 *
	 * @return array<mixed>|null The map.
	 */
	private function readProviders(string $appId): ?array {
		try {
			$path = $this->appManager->getAppPath($appId) . self::DECLARATION_PATH;
		} catch (Throwable $e) {
			return null;
		}

		if (is_file($path) === false) {
			return null;
		}

		$raw = false;
		if (is_readable($path) === true) {
			$raw = file_get_contents($path);
		}

		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false || is_array(($decoded['providers'] ?? null)) === false) {
			$this->logger->warning('[DeclaredProviderLoader] declaration of app "' . $appId . '" is not readable provider JSON');
			return null;
		}

		return $decoded['providers'];
	}//end readProviders()

	/**
	 * The installed app ids, or none when the app manager cannot list them.
	 *
	 * @return array<int, string> The app ids.
	 */
	private function installedAppIds(): array {
		try {
			return array_values(array_map('strval', $this->appManager->getInstalledApps()));
		} catch (Throwable $e) {
			$this->logger->warning('[DeclaredProviderLoader] installed apps could not be listed');
			return [];
		}
	}//end installedAppIds()
}//end class
