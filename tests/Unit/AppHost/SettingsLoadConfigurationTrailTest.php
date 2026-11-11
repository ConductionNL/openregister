<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Exception\ConfigurationMissingException;
use OCA\OpenRegister\AppHost\Exception\FoundationUnavailableException;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsServices;
use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Characterisation test of loadConfiguration() on both settings services:
 * the result or exception of every branch, the log trail and the
 * config_version stamp.
 */
class SettingsLoadConfigurationTrailTest extends TestCase {

	/** @var array<int, string> */
	private array $events = [];

	private AbstractLogger $logger;

	protected function setUp(): void {
		parent::setUp();
		$events = &$this->events;
		$this->logger = new class ($events) extends AbstractLogger {
			/** @var array<int, string> */
			private array $events;

			public function __construct(array &$events) {
				$this->events = &$events;
			}

			public function log($level, $message, array $context = []): void {
				$exception = $context['exception'] ?? null;
				if ($exception instanceof \Throwable) {
					$context['exception'] = get_class($exception) . ':' . $exception->getMessage();
				}
				$this->events[] = $level . ' ' . $message . ' ' . json_encode($context);
			}
		};
	}

	/**
	 * The services both classes are built on.
	 *
	 * @param bool                 $installed Whether OpenRegister is installed.
	 * @param ContainerInterface   $container The container.
	 *
	 * @return AppHostSettingsServices
	 */
	private function services(bool $installed, ContainerInterface $container): AppHostSettingsServices {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);
		$appManager->method('getAppVersion')->willReturn('9.9.9');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->events[] = 'stamp ' . $app . ' ' . $key . ' ' . $value;
				return true;
			}
		);
		return new AppHostSettingsServices($appConfig, $appManager, $container, $this->logger);
	}

	/**
	 * A container whose import service returns $result or throws it.
	 *
	 * @param string                     $id     The service id the class asks for.
	 * @param array|\Throwable           $result What importFromApp gives.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $id, array|\Throwable $result): ContainerInterface {
		$importer = new class ($result) {
			public function __construct(private array|\Throwable $result) {
			}

			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}
				return $this->result;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $requested) use ($id, $importer) {
				if ($requested !== $id) {
					throw new \RuntimeException('unknown service ' . $requested);
				}
				return $importer;
			}
		);
		return $container;
	}

	private function appHost(bool $installed, ContainerInterface $container, ?array $data): AppHostSettingsService {
		return new class ('myapp', $this->services($installed, $container), $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $data) extends AppHostSettingsService {
			public function __construct(string $appId, AppHostSettingsServices $services, IGroupManager $groups, IUserSession $session, private ?array $data) {
				parent::__construct($appId, $services, $groups, $session);
			}

			protected function resolveRegisterConfiguration(): array {
				return [$this->data, '1.0.0'];
			}
		};
	}

	private function generic(bool $installed, ContainerInterface $container, ?array $data): GenericSettingsService {
		return new class ('myapp', $this->services($installed, $container), $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $data) extends GenericSettingsService {
			public function __construct(string $appId, AppHostSettingsServices $services, IGroupManager $groups, IUserSession $session, private ?array $data) {
				parent::__construct($appId, $services, $groups, $session);
			}

			protected function resolveRegisterConfiguration(): array {
				return [$this->data, '1.0.0'];
			}
		};
	}

	private const APPHOST_ID = 'OCA\OpenRegister\Service\ConfigurationServiceInterface';
	private const GENERIC_ID = 'OCA\OpenRegister\Service\ConfigurationService';

	public function testAppHostBranches(): void {
		$cases = [
			'unavailable' => [$this->appHost(false, $this->container(self::APPHOST_ID, ['version' => '2']), ['a' => 1]), ['success' => false, 'message' => 'OpenRegister is not installed or enabled.']],
			'missing' => [$this->appHost(true, $this->container(self::APPHOST_ID, ['version' => '2']), null), ['success' => false, 'message' => 'Configuration file myapp_register.json not found under the myapp app path.']],
			'empty' => [$this->appHost(true, $this->container(self::APPHOST_ID, []), ['a' => 1]), ['success' => false, 'message' => 'Import returned an empty result.']],
			'imported' => [$this->appHost(true, $this->container(self::APPHOST_ID, ['version' => '2']), ['a' => 1]), ['success' => true, 'message' => 'Configuration imported successfully.', 'version' => '2']],
			'unversioned' => [$this->appHost(true, $this->container(self::APPHOST_ID, ['x' => 1]), ['a' => 1]), ['success' => true, 'message' => 'Configuration imported successfully.', 'version' => 'unknown']],
			'throws' => [$this->appHost(true, $this->container(self::APPHOST_ID, new \RuntimeException('boom')), ['a' => 1]), ['success' => false, 'message' => 'boom']],
			'unresolvable' => [$this->appHost(true, $this->container('other', []), ['a' => 1]), ['success' => false, 'message' => 'unknown service OCA\OpenRegister\Service\ConfigurationServiceInterface']],
		];
		$results = [];
		foreach ($cases as $name => [$service, $expected]) {
			$this->events[] = '-- ' . $name;
			$results[$name] = $service->loadConfiguration(force: true);
			$this->assertSame($expected, $results[$name], $name);
		}

		$this->assertSame(
			[
				'-- unavailable',
				'warning [AppHost:myapp] OpenRegister not available, skipping register initialization []',
				'-- missing',
				'warning [AppHost:myapp] Configuration file myapp_register.json not found under the myapp app path. []',
				'-- empty',
				'-- imported',
				'info [AppHost:myapp] register configuration imported successfully []',
				'stamp myapp config_version 9.9.9',
				'-- unversioned',
				'info [AppHost:myapp] register configuration imported successfully []',
				'stamp myapp config_version 9.9.9',
				'-- throws',
				'error [AppHost:myapp] configuration import failed {"exception":"boom"}',
				'-- unresolvable',
				'error [AppHost:myapp] configuration import failed {"exception":"unknown service OCA\\\\OpenRegister\\\\Service\\\\ConfigurationServiceInterface"}',
			],
			$this->events
		);
	}

	public function testGenericBranches(): void {
		$cases = [
			'unavailable' => $this->generic(false, $this->container(self::GENERIC_ID, ['version' => '2']), ['a' => 1]),
			'unresolvable' => $this->generic(true, $this->container('other', []), ['a' => 1]),
			'missing' => $this->generic(true, $this->container(self::GENERIC_ID, ['version' => '2']), null),
			'empty' => $this->generic(true, $this->container(self::GENERIC_ID, []), ['a' => 1]),
			'imported' => $this->generic(true, $this->container(self::GENERIC_ID, ['version' => '2']), ['a' => 1]),
			'throws' => $this->generic(true, $this->container(self::GENERIC_ID, new \RuntimeException('boom')), ['a' => 1]),
		];
		$outcomes = [];
		foreach ($cases as $name => $service) {
			$this->events[] = '-- ' . $name;
			try {
				$outcomes[$name] = $service->loadConfiguration(force: false);
			} catch (FoundationUnavailableException | ConfigurationMissingException $e) {
				$outcomes[$name] = get_class($e) . ':' . $e->getMessage() . ':' . get_class((object)($e->getPrevious() ?? new \stdClass()));
			} catch (\RuntimeException $e) {
				$outcomes[$name] = 'raw:' . $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'unavailable' => FoundationUnavailableException::class . ':' . (new FoundationUnavailableException(appId: 'myapp'))->getMessage() . ':stdClass',
				'unresolvable' => FoundationUnavailableException::class . ':' . (new FoundationUnavailableException(appId: 'myapp', detail: 'ConfigurationService could not be resolved from the container.'))->getMessage() . ':RuntimeException',
				'missing' => ConfigurationMissingException::class . ':' . (new ConfigurationMissingException(appId: 'myapp', configKey: 'lib/Settings/myapp_register.json'))->getMessage() . ':stdClass',
				'empty' => ['success' => false, 'message' => 'Import returned an empty result.'],
				'imported' => ['success' => true, 'message' => 'Configuration imported successfully.', 'version' => '2'],
				'throws' => 'raw:boom',
			],
			$outcomes
		);
		$this->assertSame(
			[
				'-- unavailable',
				'error [AppHost:myapp] OpenRegister not available — register import refused (fail-closed) []',
				'-- unresolvable',
				'error [AppHost:myapp] ConfigurationService unresolvable — register import refused (fail-closed) {"exception":"RuntimeException:unknown service OCA\\\\OpenRegister\\\\Service\\\\ConfigurationService"}',
				'-- missing',
				'error [AppHost:myapp] register JSON lib/Settings/myapp_register.json missing or invalid []',
				'-- empty',
				'-- imported',
				'info [AppHost:myapp] register configuration imported successfully []',
				'stamp myapp config_version 9.9.9',
				'-- throws',
			],
			$this->events
		);
	}
}
