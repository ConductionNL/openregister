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

use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoaderInterface;
use OCA\OpenRegister\Kernel\Contracts\AppContainerFinderInterface;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Runs one app's forced register import, through the app where it can.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
class AppRegisterImportRunner implements AppRegisterImportRunnerInterface {
	/**
	 * The import ran through the app's own SettingsService.
	 *
	 * @var string
	 */
	public const PATH_APP = 'app';

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
	 * @param AppContainerFinderInterface $containers         Reaches the app's own DI container.
	 * @param AppConfigurationImporterInterface $configurationService Imports on the fallback path.
	 * @param RegisterDocumentLoaderInterface $documents Reads the app's register document and fragments.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly AppContainerFinderInterface $containers,
		private readonly AppConfigurationImporterInterface $configurationService,
		private readonly RegisterDocumentLoaderInterface $documents,
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
		[$data, $version] = $this->documents->load(appId: $appId);

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
		$class = $this->settingsServiceClass(appId: $appId);
		if ($class === null) {
			return null;
		}

		$container = $this->containers->find(appId: $appId);
		if ($container === null) {
			return null;
		}

		$service = $this->serviceFrom(container: $container, class: $class);
		if ($service === null) {
			return null;
		}

		return $this->forcedEntry(service: $service, class: $class);
	}//end appEntryPoint()

	/**
	 * The app's SettingsService class name, or null when the app declares no namespace.
	 *
	 * @param string $appId The app.
	 *
	 * @return string|null
	 */
	private function settingsServiceClass(string $appId): ?string {
		$namespace = (string)($this->appManager->getAppInfo($appId)['namespace'] ?? '');
		if ($namespace === '') {
			return null;
		}

		return 'OCA\\' . $namespace . '\\Service\\SettingsService';
	}//end settingsServiceClass()

	/**
	 * The service from the app's container, or null when it is absent, unresolvable or not an object.
	 *
	 * @param ContainerInterface $container The app's container.
	 * @param string $class The SettingsService class name.
	 *
	 * @return object|null
	 */
	private function serviceFrom(ContainerInterface $container, string $class): ?object {
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

		return $service;
	}//end serviceFrom()

	/**
	 * The first forced entry point the service has.
	 *
	 * @param object $service The app's SettingsService.
	 * @param string $class The SettingsService class name.
	 *
	 * @return array{service: object, method: string, force: string|null, class: string}|null
	 */
	private function forcedEntry(object $service, string $class): ?array {
		foreach (self::FORCED_ENTRY_POINTS as $entryPoint) {
			if ($this->accepts(service: $service, entryPoint: $entryPoint) === true) {
				return ['service' => $service, 'method' => $entryPoint[0], 'force' => $entryPoint[1], 'class' => $class];
			}
		}

		return null;
	}//end forcedEntry()

	/**
	 * Whether a service has a method of the given forced shape.
	 *
	 * @param object $service The app's SettingsServiceInterface.
	 * @param array{0: string, 1: string|null} $entryPoint [method name, force parameter's name or null for a method taking none].
	 *
	 * @return bool True when the method exists, is public and has that shape.
	 */
	private function accepts(object $service, array $entryPoint): bool {
		$reflection = $this->publicMethod(service: $service, method: $entryPoint[0]);
		if ($reflection === null) {
			return false;
		}

		if ($entryPoint[1] === null) {
			return ($reflection->getNumberOfRequiredParameters() === 0);
		}

		$params = $reflection->getParameters();
		return (isset($params[0]) === true && $params[0]->getName() === $entryPoint[1]);
	}//end accepts()

	/**
	 * Reflection of a public method of the service, or null when it has no such public method.
	 *
	 * @param object $service The app's SettingsService.
	 * @param string $method The method name.
	 *
	 * @return ReflectionMethod|null
	 */
	private function publicMethod(object $service, string $method): ?ReflectionMethod {
		if (method_exists($service, $method) === false) {
			return null;
		}

		$reflection = new ReflectionMethod($service, $method);
		if ($reflection->isPublic() === false) {
			return null;
		}

		return $reflection;
	}//end publicMethod()

	/**
	 * Run the import through the app's own SettingsService.
	 *
	 * @param array{service: object, method: string, force: string|null, class: string} $entry The entry point.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function runThroughApp(array $entry): array {
		$route = new RegisterImportRoute(path: self::PATH_APP, via: $this->viaOf(entry: $entry));

		// Named, so the value can only land on the parameter that was checked
		// to be called `force`. ONE call: the import is not idempotent in cost.
		try {
			$result = $entry['service']->{$entry['method']}(...$this->forcedArgs(entry: $entry));
		} catch (Throwable $e) {
			return $route->failed(message: $e->getMessage());
		}

		return $this->appOutcome(route: $route, result: $result);
	}//end runThroughApp()

	/**
	 * How the entry point is called, for the operator.
	 *
	 * @param array{service: object, method: string, force: string|null, class: string} $entry The entry point.
	 *
	 * @return string
	 */
	private function viaOf(array $entry): string {
		if ($entry['force'] === null) {
			return sprintf('%s::%s()', $entry['class'], $entry['method']);
		}

		return sprintf('%s::%s(%s: true)', $entry['class'], $entry['method'], $entry['force']);
	}//end viaOf()

	/**
	 * The named arguments of the forced call: `[force => true]`, or none.
	 *
	 * @param array{service: object, method: string, force: string|null, class: string} $entry The entry point.
	 *
	 * @return array<string, bool>
	 */
	private function forcedArgs(array $entry): array {
		if ($entry['force'] === null) {
			return [];
		}

		return [$entry['force'] => true];
	}//end forcedArgs()

	/**
	 * The result of the app's reply: a non-array, `success: false` or a non-empty `error` is a failure.
	 *
	 * @param RegisterImportRoute $route The app path.
	 * @param mixed $result The app's reply.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function appOutcome(RegisterImportRoute $route, mixed $result): array {
		if (is_array($result) === false) {
			return $route->failed(message: 'The app returned no result.');
		}

		$message = (string)($result['message'] ?? ($result['error'] ?? ''));
		if (($result['success'] ?? true) === false || empty($result['error']) === false) {
			return $route->failed(message: $message);
		}

		return $route->succeeded(message: $message);
	}//end appOutcome()

	/**
	 * Run the import through OpenRegister's reader of the app's files.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function runThroughOpenRegister(string $appId): array {
		$route = new RegisterImportRoute(
			path: self::PATH_OPENREGISTER,
			via: sprintf('OpenRegister (lib/Settings/%s_register.json + register.d)', $appId)
		);
		$document = $this->document(appId: $appId);
		if ($document['data'] === null) {
			return $route->failed(message: sprintf('%s ships no lib/Settings/%s_register.json.', $appId, $appId));
		}

		$request = ['appId' => $appId, 'data' => $document['data'], 'version' => $document['version']];
		return $this->importDocument(route: $route, request: $request);
	}//end runThroughOpenRegister()

	/**
	 * Hand the app's document to OpenRegister's importer, forced.
	 *
	 * @param RegisterImportRoute $route The OpenRegister path.
	 * @param array{appId: string, data: array<string, mixed>, version: string} $request The import request.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	private function importDocument(RegisterImportRoute $route, array $request): array {
		try {
			$result = $this->forcedImport(request: $request);
		} catch (Throwable $e) {
			return $route->failed(message: $e->getMessage());
		}

		if ($result === []) {
			return $route->failed(message: 'The import returned an empty result.');
		}

		return $route->succeeded(message: '');
	}//end importDocument()

	/**
	 * OpenRegister's forced import of the app's document.
	 *
	 * @param array{appId: string, data: array<string, mixed>, version: string} $request The import request.
	 *
	 * @return array<string, mixed> The importer's result.
	 */
	private function forcedImport(array $request): array {
		return $this->configurationService->importFromApp(
			appId: $request['appId'],
			data: $request['data'],
			version: $request['version'],
			force: true
		);
	}//end forcedImport()
}//end class
