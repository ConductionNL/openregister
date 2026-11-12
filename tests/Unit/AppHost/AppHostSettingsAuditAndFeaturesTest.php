<?php

declare(strict_types=1);

/**
 * Characterisation of AppHostSettingsService's settings-change audit and its
 * feature declarations: what is handed to the auditor, when nothing is, what
 * is logged, and which toggles are declared.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\AppHost\Service\AppHostSettingsServices;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Pins the audit hand-off and the feature declarations of the settings service.
 */
class AppHostSettingsAuditAndFeaturesTest extends TestCase {

	private const AUDITOR = 'OCA\\OpenRegister\\Service\\Rbac\\SettingsChangeAuditor';

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	public function testAChangeIsHandedToTheAuditorWithTheChangedKeysAsSecretsWhenConfigIsUnreadable(): void {
		$auditor = new class($this->trail) {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function recordUpdate(string $app, array $before, array $after, array $secrets): void {
				$this->trail[] = 'record ' . $app . ' ' . json_encode($before) . ' ' . json_encode($after) . ' ' . json_encode($secrets);
			}

			public function secretKeysIn(array $data): array {
				return ['token'];
			}
		};

		$this->invoke(service: $this->service(auditor: $auditor, data: new RuntimeException('no config')), method: 'auditSettingsChange', args: [['a' => 1], ['a' => 2, 'b' => 3]]);

		$this->assertSame(
			[
				'resolve',
				'warning [AppHost:myapp] Register configuration unreadable: no config',
				'record myapp {"a":1} {"a":2,"b":3} ["a","b"]',
			],
			$this->trail
		);
	}//end testAChangeIsHandedToTheAuditorWithTheChangedKeysAsSecretsWhenConfigIsUnreadable()

	public function testTheAuditorsSecretKeysAreUsedWhenConfigIsReadable(): void {
		$auditor = new class($this->trail) {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function recordUpdate(string $app, array $before, array $after, array $secrets): void {
				$this->trail[] = 'record ' . json_encode($secrets);
			}

			public function secretKeysIn(array $data): array {
				return ['token'];
			}
		};

		$this->invoke(service: $this->service(auditor: $auditor, data: ['x' => 1]), method: 'auditSettingsChange', args: [['a' => 1], ['a' => 2]]);

		$this->assertSame(['resolve', 'record ["token"]'], $this->trail);
	}//end testTheAuditorsSecretKeysAreUsedWhenConfigIsReadable()

	public function testAnAuditorWithoutRecordUpdateIsSkipped(): void {
		$this->invoke(service: $this->service(auditor: new \stdClass(), data: []), method: 'auditSettingsChange', args: [[], ['a' => 1]]);

		$this->assertSame([], $this->trail);
	}//end testAnAuditorWithoutRecordUpdateIsSkipped()

	public function testAnUnresolvableAuditorIsAWarning(): void {
		$this->invoke(service: $this->service(auditor: null, data: []), method: 'auditSettingsChange', args: [[], ['a' => 1]]);

		$this->assertSame(['warning [AppHost:myapp] Settings change was stored but not audited: no auditor'], $this->trail);
	}//end testAnUnresolvableAuditorIsAWarning()

	public function testDeclaredFeaturesAreReadOnceAndListed(): void {
		$service = $this->service(auditor: null, data: ['features' => ['x' => ['key' => 'a'], 'y' => ['key' => 'b']]]);

		$first  = $this->invoke(service: $service, method: 'featureDeclarations', args: []);
		$second = $this->invoke(service: $service, method: 'featureDeclarations', args: []);

		$this->assertSame([['key' => 'a'], ['key' => 'b']], $first);
		$this->assertSame($first, $second);
		$this->assertSame(['resolve'], $this->trail);
	}//end testDeclaredFeaturesAreReadOnceAndListed()

	public function testAMissingOrMalformedFeaturesBlockDeclaresNothing(): void {
		$this->assertSame([], $this->invoke(service: $this->service(auditor: null, data: ['other' => 1]), method: 'featureDeclarations', args: []));
		$this->assertSame([], $this->invoke(service: $this->service(auditor: null, data: ['features' => 'all']), method: 'featureDeclarations', args: []));
	}//end testAMissingOrMalformedFeaturesBlockDeclaresNothing()

	public function testUnreadableDeclarationsAreAWarningAndDeclareNothing(): void {
		$declared = $this->invoke(service: $this->service(auditor: null, data: new RuntimeException('broken')), method: 'featureDeclarations', args: []);

		$this->assertSame([], $declared);
		$this->assertSame(['resolve', 'warning [AppHost:myapp] feature declarations unreadable, no toggles offered: broken'], $this->trail);
	}//end testUnreadableDeclarationsAreAWarningAndDeclareNothing()

	/**
	 * Call a private or protected method of the service.
	 *
	 * @param AppHostSettingsService $service The service.
	 * @param string                 $method  The method.
	 * @param array                  $args    Its arguments.
	 *
	 * @return mixed
	 */
	private function invoke(AppHostSettingsService $service, string $method, array $args): mixed {
		$reflection = new \ReflectionMethod(AppHostSettingsService::class, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($service, $args);
	}//end invoke()

	/**
	 * A settings service whose register configuration is $data (or throws it).
	 *
	 * @param object|null           $auditor The auditor the container gives; null throws.
	 * @param array|RuntimeException $data    The register configuration.
	 *
	 * @return AppHostSettingsService
	 */
	private function service(?object $auditor, array|RuntimeException $data): AppHostSettingsService {
		$this->trail = [];
		$container   = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($auditor): object {
				if ($id === self::AUDITOR && $auditor !== null) {
					return $auditor;
				}

				throw new RuntimeException('no auditor');
			}
		);

		$trail  = &$this->trail;
		$logger = new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trail[] = $level . ' ' . $message;
			}
		};

		$services = new AppHostSettingsServices($this->createMock(IAppConfig::class), $this->createMock(IAppManager::class), $container, $logger);
		return new class('myapp', $services, $this->createMock(IGroupManager::class), $this->createMock(IUserSession::class), $data, $this->trail) extends AppHostSettingsService {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(string $appId, AppHostSettingsServices $services, IGroupManager $groups, IUserSession $session, private array|RuntimeException $data, private array &$trail) {
				parent::__construct($appId, $services, $groups, $session);
			}

			protected function resolveRegisterConfiguration(): array {
				if ($this->data instanceof RuntimeException) {
					$this->trail[] = 'resolve';
					throw $this->data;
				}

				$this->trail[] = 'resolve';
				return [$this->data, '1.0.0'];
			}
		};
	}//end service()
}//end class
