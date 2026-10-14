<?php

/**
 * AppRegisterImportRunner — import an app's REAL register configuration, forced.
 *
 * An app's register configuration reaches an instance through the app's own
 * `InitializeSettings` repair step, which calls the app's SettingsService,
 * which reads `lib/Settings/<app>_register.json`, merges its
 * `lib/Settings/register.d/*.json` fragments (each app in its own way) and
 * hands the result to `ConfigurationService::importFromApp()`. Several apps
 * then re-apply instance configuration on top: pipelinq re-grants its CRM
 * groups, portaliq re-applies its page-editor groups.
 *
 * Repair steps run on install and upgrade only, so a register change shipped
 * without an app-version bump never reached an existing instance, and the one
 * on-demand command imported the app's MOCK descriptor instead.
 *
 * 🔴 THE APP'S OWN PATH FIRST. Calling the app's SettingsService is the only
 * way to get the app's fragment merge and its post-import steps exactly as an
 * upgrade runs them. The fleet has no common interface for it, so the forced
 * entry points that exist are named below, one per shape, and the first one
 * the app's SettingsService has is used. An app with none falls back to
 * OpenRegister's own reader of the same files, and the result says so,
 * because that fallback does not run the app's post-import steps.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader;
use OCP\App\IAppManager;
use ReflectionMethod;
use Throwable;

/**
 * Runs one app's forced register import, through the app where it can.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
class AppRegisterImportRunner {
	/**
	 * The import ran through the app's own SettingsService.
	 *
	 * @var string
	 */
	public const PATH_APP = 'app';

	/**
	 * The import ran through OpenRegister's reader of the app's files.
	 *
	 * @var string
	 */
	public const PATH_OPENREGISTER = 'openregister';

	/**
	 * The forced entry points the fleet's SettingsServices have, in order.
	 *
	 * Each is `[method, name of the force parameter or null]`. A method with a
	 * force parameter is used only when that parameter really is named `force`:
	 * shillinq's `loadConfiguration()` takes no argument and is NOT forced, so
	 * matching the name alone would run an import the version gate skips.
	 *
	 * Measured on the fleet's development branches, 14 Oct 2026:
	 * shillinq and humaniq `loadConfigurationForced()`; decidiq
	 * `reloadConfiguration()`; portaliq, dossiq, hermiq, stackiq and every
	 * AppHost app `loadConfiguration(force)`; pipelinq `loadSettings(force)`.
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	private const FORCED_ENTRY_POINTS = [
		['loadConfigurationForced', null],
		['reloadConfiguration', null],
		['loadConfiguration', 'force'],
		['loadSettings', 'force'],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager          $appManager           Resolves the app's namespace and path.
	 * @param AppContainerLocator  $containers           Reaches the app's own DI container.
	 * @param ConfigurationService $configurationService Imports on the fallback path.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly AppContainerLocator $containers,
		private readonly ConfigurationService $configurationService,
	) {
	}//end __construct()

	/**
	 * The app's real register document: base file plus register.d fragments.
	 *
	 * Read through the same loader AppHost apps import with. Used to know which
	 * registers the configuration declares, and as the fallback import.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{data: array<string, mixed>|null, version: string, fragments: int}
	 *         `data` is null when the app ships no `<app>_register.json`.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function document(string $appId): array {
		[$data, $version] = (new RegisterDocumentLoader(appManager: $this->appManager))->load(appId: $appId);

		$fragments = 0;
		try {
			$found = glob($this->appManager->getAppPath($appId) . '/lib/Settings/register.d/*.json');
			if ($found !== false) {
				$fragments = count($found);
			}
		} catch (Throwable $e) {
			$fragments = 0;
		}

		return ['data' => $data, 'version' => $version, 'fragments' => $fragments];
	}//end document()

	/**
	 * Run the app's forced import.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 *         `via` names the method that ran, so the operator can tell which path it was.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function run(string $appId): array {
		$entry = $this->appEntryPoint(appId: $appId);
		if ($entry !== null) {
			return $this->runThroughApp(entry: $entry);
		}

		return $this->runThroughOpenRegister(appId: $appId);
	}//end run()

	/**
	 * The app's SettingsService and the forced method it has, or null.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{service: object, method: string, force: string|null, class: string}|null
	 */
	private function appEntryPoint(string $appId): ?array {
		$namespace = (string)($this->appManager->getAppInfo($appId)['namespace'] ?? '');
		if ($namespace === '') {
			return null;
		}

		$container = $this->containers->find(appId: $appId);
		if ($container === null) {
			return null;
		}

		$class = 'OCA\\' . $namespace . '\\Service\\SettingsService';
		try {
			if ($container->has($class) === false) {
				return null;
			}

			$service = $container->get($class);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		foreach (self::FORCED_ENTRY_POINTS as [$method, $force]) {
			if ($this->accepts(service: $service, method: $method, force: $force) === true) {
				return ['service' => $service, 'method' => $method, 'force' => $force, 'class' => $class];
			}
		}

		return null;
	}//end appEntryPoint()

	/**
	 * Whether a service has a method of the given forced shape.
	 *
	 * @param object      $service The app's SettingsService.
	 * @param string      $method  The method name.
	 * @param string|null $force   The force parameter's name, or null for a method taking none.
	 *
	 * @return bool True when the method exists, is public and has that shape.
	 */
	private function accepts(object $service, string $method, ?string $force): bool {
		if (method_exists($service, $method) === false) {
			return false;
		}

		$reflection = new ReflectionMethod($service, $method);
		if ($reflection->isPublic() === false) {
			return false;
		}

		$params = $reflection->getParameters();
		if ($force === null) {
			return ($reflection->getNumberOfRequiredParameters() === 0);
		}

		return (isset($params[0]) === true && $params[0]->getName() === $force);
	}//end accepts()

	/**
	 * Run the import through the app's own SettingsService.
	 *
	 * @param array{service: object, method: string, force: string|null, class: string} $entry The entry point.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function runThroughApp(array $entry): array {
		$method = $entry['method'];
		$via    = sprintf('%s::%s()', $entry['class'], $method);
		if ($entry['force'] !== null) {
			$via = sprintf('%s::%s(%s: true)', $entry['class'], $method, $entry['force']);
		}

		// Named, so the value can only land on the parameter that was checked
		// to be called `force`. ONE call: the import is not idempotent in cost.
		$args = [];
		if ($entry['force'] !== null) {
			$args = [$entry['force'] => true];
		}

		try {
			$result = $entry['service']->{$method}(...$args);
		} catch (Throwable $e) {
			return ['success' => false, 'path' => self::PATH_APP, 'via' => $via, 'message' => $e->getMessage()];
		}

		if (is_array($result) === false) {
			return ['success' => false, 'path' => self::PATH_APP, 'via' => $via, 'message' => 'The app returned no result.'];
		}

		$failed = (($result['success'] ?? true) === false || empty($result['error']) === false);
		$message = (string)($result['message'] ?? ($result['error'] ?? ''));

		return ['success' => ($failed === false), 'path' => self::PATH_APP, 'via' => $via, 'message' => $message];
	}//end runThroughApp()

	/**
	 * Run the import through OpenRegister's reader of the app's files.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function runThroughOpenRegister(string $appId): array {
		$via      = sprintf('OpenRegister (lib/Settings/%s_register.json + register.d)', $appId);
		$document = $this->document(appId: $appId);
		if ($document['data'] === null) {
			return [
				'success' => false,
				'path'    => self::PATH_OPENREGISTER,
				'via'     => $via,
				'message' => sprintf('%s ships no lib/Settings/%s_register.json.', $appId, $appId),
			];
		}

		try {
			$result = $this->configurationService->importFromApp(
				appId: $appId,
				data: $document['data'],
				version: $document['version'],
				force: true
			);
		} catch (Throwable $e) {
			return ['success' => false, 'path' => self::PATH_OPENREGISTER, 'via' => $via, 'message' => $e->getMessage()];
		}

		if ($result === []) {
			return ['success' => false, 'path' => self::PATH_OPENREGISTER, 'via' => $via, 'message' => 'The import returned an empty result.'];
		}

		return ['success' => true, 'path' => self::PATH_OPENREGISTER, 'via' => $via, 'message' => ''];
	}//end runThroughOpenRegister()
}//end class
