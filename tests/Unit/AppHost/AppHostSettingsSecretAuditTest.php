<?php

/**
 * A settings key the register configuration declares secret is masked on the audit trail.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\AppHost
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/settings-change-audit/specs/apphost-settings-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the real AppHostSettingsService and SettingsChangeAuditor over a register JSON on disk.
 */
class AppHostSettingsSecretAuditTest extends TestCase {

	/** @var array<int, \OCA\OpenRegister\Db\AuditTrail> The rows the auditor wrote. */
	private array $written = [];

	private string $appPath = '';

	private bool $resolverFails = false;

	protected function tearDown(): void {
		if ($this->appPath !== '') {
			array_map('unlink', glob($this->appPath . '/lib/Settings/register.d/*.json'));
			if (is_dir($this->appPath . '/lib/Settings/register.d') === true) {
				rmdir($this->appPath . '/lib/Settings/register.d');
			}

			array_map('unlink', glob($this->appPath . '/lib/Settings/*.json'));
			rmdir($this->appPath . '/lib/Settings');
			rmdir($this->appPath . '/lib');
			rmdir($this->appPath);
		}

		parent::tearDown();
	}

	/**
	 * Save `register` from one value to another under the given register JSON.
	 *
	 * @param array<string, mixed> $register The app's register JSON.
	 *
	 * @return array<string, mixed> The `changed` block of the one row written.
	 */
	private function saveRegisterSetting(array $register, array $hookSecrets = []): array {
		return $this->saveUnderDocument(json_encode($register), $hookSecrets);
	}

	/**
	 * Save `register` from one value to another under the given register document.
	 *
	 * @param string|null        $document    The raw register JSON, null for no file at all.
	 * @param array<int, string> $hookSecrets What the secretConfigKeys() hook answers.
	 * @param string|null        $fragment    A raw `register.d` fragment.
	 *
	 * @return array<string, mixed> The `changed` block of each row, by key.
	 */
	private function saveUnderDocument(?string $document, array $hookSecrets = [], ?string $fragment = null): array {
		$this->appPath = sys_get_temp_dir() . '/apphost-secret-audit-' . uniqid();
		mkdir($this->appPath . '/lib/Settings', 0777, true);
		if ($document !== null) {
			file_put_contents($this->appPath . '/lib/Settings/myapp_register.json', $document);
		}

		if ($fragment !== null) {
			mkdir($this->appPath . '/lib/Settings/register.d');
			file_put_contents($this->appPath . '/lib/Settings/register.d/secrets.json', $fragment);
		}

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willReturn($this->appPath);

		$stored = ['register' => 'old-token', 'apiToken' => 'old-key'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$written = &$this->written;
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries, int $chunkSize = 100) use (&$written): array {
				$written = array_merge($written, $entries);
				return $entries;
			}
		);
		$auditor = new SettingsChangeAuditor(
			mapper: $mapper,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($auditor);

		$service = new class(
			'myapp',
			$appConfig,
			$appManager,
			$container,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$hookSecrets,
			$this->resolverFails
		) extends AppHostSettingsService {
			/**
			 * @param array<int, string> $hookSecrets What the secretConfigKeys() hook answers.
			 */
			public function __construct(
				string $appId,
				IAppConfig $appConfig,
				IAppManager $appManager,
				ContainerInterface $container,
				IGroupManager $groupManager,
				IUserSession $userSession,
				LoggerInterface $logger,
				private readonly array $hookSecrets,
				private readonly bool $resolverFails,
			) {
				parent::__construct($appId, $appConfig, $appManager, $container, $groupManager, $userSession, $logger);
			}

			protected function configKeys(): array {
				return ['register', 'apiToken'];
			}

			protected function secretConfigKeys(): array {
				return $this->hookSecrets;
			}

			protected function resolveRegisterConfiguration(): array {
				if ($this->resolverFails === true) {
					throw new \RuntimeException('unreadable');
				}

				return parent::resolveRegisterConfiguration();
			}
		};

		$service->updateSettings(['register' => 'new-token', 'apiToken' => 'new-key']);

		$this->assertCount(2, $this->written, 'two changed keys, two rows');
		$changed = [];
		foreach ($this->written as $row) {
			$changed[$row->getChanged()['key']] = $row->getChanged();
		}

		return $changed;
	}

	public function testAKeyDeclaredSecretIsMaskedOnTheTrail(): void {
		$changed = $this->saveRegisterSetting(
			[
				'info' => ['version' => '1.0.0'],
				'register' => [SettingsChangeAuditor::SECRET_KEY => true],
			]
		)['register'];

		$this->assertTrue($changed['secret']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['old']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['new']);
		$this->assertStringNotContainsString('token', (string)json_encode($changed), 'no value reaches the row');
	}

	public function testAnUndeclaredKeyKeepsItsValues(): void {
		$changed = $this->saveRegisterSetting(['info' => ['version' => '1.0.0']])['register'];

		$this->assertFalse($changed['secret']);
		$this->assertSame('old-token', $changed['old']);
		$this->assertSame('new-token', $changed['new']);
	}

	public function testTheHookKeysAndTheDeclaredKeysAreBothMasked(): void {
		$changed = $this->saveRegisterSetting(
			['register' => [SettingsChangeAuditor::SECRET_KEY => true]],
			['apiToken']
		);

		$this->assertSame(SettingsChangeAuditor::MASK, $changed['register']['new'], 'declared in the register document');
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['apiToken']['new'], 'named by the hook');
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['apiToken']['old']);
	}

	public function testWithoutARegisterDocumentTheHookKeysStillApply(): void {
		$changed = $this->saveRegisterSetting([], ['apiToken']);

		$this->assertSame(SettingsChangeAuditor::MASK, $changed['apiToken']['new']);
		$this->assertSame('new-token', $changed['register']['new']);
	}

	/**
	 * @return array<string, array{0: string|null, 1: string|null}> The register document and fragment.
	 */
	public static function unreadableDocuments(): array {
		return [
			'no document' => [null, null],
			'malformed document' => ['{"register": {', null],
			'malformed fragment' => ['{"info": {"version": "1.0.0"}}', '{"register": {'],
		];
	}

	/**
	 * @dataProvider unreadableDocuments
	 */
	public function testAnUnreadableConfigurationRecordsTheChangeWithoutTheValue(?string $document, ?string $fragment): void {
		$changed = $this->saveUnderDocument($document, [], $fragment);

		foreach (['register', 'apiToken'] as $key) {
			$this->assertSame($key, $changed[$key]['key'], 'the change itself stays on the trail');
			$this->assertTrue($changed[$key]['secret']);
			$this->assertSame(SettingsChangeAuditor::MASK, $changed[$key]['old']);
			$this->assertSame(SettingsChangeAuditor::MASK, $changed[$key]['new']);
		}

		$this->assertStringNotContainsString('token', (string)json_encode($changed['register']));
		$this->assertStringNotContainsString('-key', (string)json_encode($changed['apiToken']));
	}

	public function testAResolverThatThrowsRecordsTheChangeWithoutTheValue(): void {
		$this->resolverFails = true;

		$changed = $this->saveRegisterSetting(['info' => ['version' => '1.0.0']]);

		$this->assertSame(SettingsChangeAuditor::MASK, $changed['register']['new']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['apiToken']['old']);
	}

	public function testAWellFormedFragmentKeepsTheValuesOfUndeclaredKeys(): void {
		$changed = $this->saveUnderDocument(
			'{"info": {"version": "1.0.0"}}',
			[],
			json_encode(['apiToken' => [SettingsChangeAuditor::SECRET_KEY => true]])
		);

		$this->assertSame('new-token', $changed['register']['new']);
		$this->assertSame(SettingsChangeAuditor::MASK, $changed['apiToken']['new']);
	}
}
