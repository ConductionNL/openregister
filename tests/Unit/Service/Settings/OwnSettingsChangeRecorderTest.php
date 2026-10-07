<?php

declare(strict_types=1);

/**
 * Open Register's own settings saves write an audit row (#4060).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Audit\SecuritySettingRegistry;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every door that saves Open Register's own settings hands a before and after
 * to the SettingsChangeAuditor.
 *
 * The app config is a real in-memory store, not a stub that answers the same
 * value twice: the diff can only show a change when the write actually moved
 * the stored value, which is what the audit row claims.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The doors under test need their own collaborators.
 */
class OwnSettingsChangeRecorderTest extends TestCase {

	/**
	 * The stored app config values, key to string.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The app config backed by $store.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Captures every recordUpdate() call.
	 *
	 * @var SettingsChangeAuditor&MockObject
	 */
	private SettingsChangeAuditor $auditor;

	/**
	 * The recordUpdate() calls, in order.
	 *
	 * @var array<int, array{app: string, before: array<string, mixed>, after: array<string, mixed>, secretKeys: array<int, string>}>
	 */
	private array $recorded = [];

	/**
	 * Set up the in-memory store and the capturing auditor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = [];
		$this->recorded = [];

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('hasKey')->willReturnCallback(
			fn (string $app, string $key) => array_key_exists($key, $this->store)
		);
		$this->appConfig->method('getValueType')->willReturn(IAppConfig::VALUE_STRING);

		$this->auditor = $this->createMock(SettingsChangeAuditor::class);
		$this->auditor->method('recordUpdate')->willReturnCallback(
			function (string $app, array $before, array $after, array $secretKeys = []): int {
				$this->recorded[] = [
					'app' => $app,
					'before' => $before,
					'after' => $after,
					'secretKeys' => $secretKeys,
				];
				return 1;
			}
		);
	}//end setUp()

	/**
	 * The recorder under test, wired to the real registry.
	 *
	 * @param SecuritySettingAnnouncer|null $announcer The announcer, if any.
	 *
	 * @return OwnSettingsChangeRecorder The recorder.
	 */
	private function recorder(?SecuritySettingAnnouncer $announcer = null): OwnSettingsChangeRecorder {
		return new OwnSettingsChangeRecorder(
			$this->appConfig,
			$this->auditor,
			new SecuritySettingRegistry($this->appConfig),
			$this->createMock(LoggerInterface::class),
			$announcer
		);
	}//end recorder()

	/**
	 * A configuration handler with the recorder wired in.
	 *
	 * @return ConfigurationSettingsHandler The handler.
	 */
	private function configurationHandler(): ConfigurationSettingsHandler {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('search')->willReturn([]);
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([]);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findAllWithUserCount')->willReturn([]);

		return new ConfigurationSettingsHandler(
			$this->appConfig,
			$groups,
			$users,
			$organisations,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppManager::class),
			'openregister',
			null,
			$this->recorder()
		);
	}//end configurationHandler()

	/**
	 * The change named by one key in the last recorded call.
	 *
	 * @param string $key The flattened key.
	 *
	 * @return array{0: mixed, 1: mixed} The old and new value.
	 */
	private function lastChange(string $key): array {
		$this->assertNotEmpty($this->recorded, 'No audit row was handed to the SettingsChangeAuditor.');
		$call = $this->recorded[array_key_last($this->recorded)];
		$this->assertSame('openregister', $call['app']);

		return [($call['before'][$key] ?? null), ($call['after'][$key] ?? null)];
	}//end lastChange()

	/**
	 * Switching RBAC off through PUT /api/settings/rbac is recorded.
	 *
	 * @return void
	 */
	public function testRbacDoorRecordsTheChangedKey(): void {
		$this->store['rbac'] = json_encode(['enabled' => true, 'adminOverride' => true]);

		$this->configurationHandler()->updateRbacSettingsOnly(['enabled' => false, 'adminOverride' => true]);

		$this->assertSame([true, false], $this->lastChange('rbac.enabled'));
		$this->assertSame([true, true], $this->lastChange('rbac.adminOverride'));
	}//end testRbacDoorRecordsTheChangedKey()

	/**
	 * Switching multitenancy off through PUT /api/settings/multitenancy is recorded.
	 *
	 * @return void
	 */
	public function testMultitenancyDoorRecordsTheChangedKey(): void {
		$this->store['multitenancy'] = json_encode(['enabled' => true]);

		$this->configurationHandler()->updateMultitenancySettingsOnly(['enabled' => false]);

		$this->assertSame([true, false], $this->lastChange('multitenancy.enabled'));
	}//end testMultitenancyDoorRecordsTheChangedKey()

	/**
	 * The organisation door is recorded.
	 *
	 * @return void
	 */
	public function testOrganisationDoorRecordsTheChangedKey(): void {
		$this->configurationHandler()->updateOrganisationSettingsOnly(['default_organisation' => 'org-2']);

		$this->assertSame([null, 'org-2'], $this->lastChange('organisation.default_organisation'));
	}//end testOrganisationDoorRecordsTheChangedKey()

	/**
	 * The full save (PUT /api/settings) is recorded, and a secret is marked.
	 *
	 * @return void
	 */
	public function testFullSaveRecordsAndMarksSecrets(): void {
		$this->store['solr'] = json_encode(['password' => 'old-secret', 'host' => 'solr']);

		$this->configurationHandler()->updateSettings(['solr' => ['password' => 'new-secret', 'host' => 'solr']]);

		$this->assertSame(['old-secret', 'new-secret'], $this->lastChange('solr.password'));
		$call = $this->recorded[array_key_last($this->recorded)];
		$this->assertContains('solr.password', $call['secretKeys']);
		$this->assertContains('solr.zookeeperPassword', $call['secretKeys']);
		$this->assertNotContains('solr.host', $call['secretKeys']);
	}//end testFullSaveRecordsAndMarksSecrets()

	/**
	 * The retention door on ObjectRetentionHandler is recorded.
	 *
	 * @return void
	 */
	public function testRetentionDoorRecordsTheChangedKey(): void {
		$this->store['retention'] = json_encode(['auditTrailsEnabled' => true]);

		$handler = new ObjectRetentionHandler($this->appConfig, 'openregister', $this->recorder());
		$handler->updateRetentionSettingsOnly(['auditTrailsEnabled' => false]);

		$this->assertSame([true, false], $this->lastChange('retention.auditTrailsEnabled'));
	}//end testRetentionDoorRecordsTheChangedKey()

	/**
	 * The object and archival doors are recorded.
	 *
	 * @return void
	 */
	public function testObjectAndArchivalDoorsRecord(): void {
		$handler = new ObjectRetentionHandler($this->appConfig, 'openregister', $this->recorder());

		$handler->updateObjectSettingsOnly(['batchSize' => 50]);
		$this->assertSame([null, 50], $this->lastChange('objectManagement.batchSize'));

		$handler->updateArchivalSettingsOnly([]);
		$call = $this->recorded[array_key_last($this->recorded)];
		$this->assertNotSame([], array_filter(array_keys($call['after']), fn (string $key) => str_starts_with($key, 'archival.')));
	}//end testObjectAndArchivalDoorsRecord()

	/**
	 * A per-section save also announces the security-marked settings.
	 *
	 * @return void
	 */
	public function testPerSectionSaveAnnounces(): void {
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturnOnConsecutiveCalls(['rbac.enabled' => true], ['rbac.enabled' => false]);
		$announcer->expects($this->once())
			->method('announce')
			->with(['rbac.enabled' => true], ['rbac.enabled' => false]);

		$recorder = $this->recorder(announcer: $announcer);
		$before = $recorder->snapshot(keys: ['rbac']);
		$this->store['rbac'] = json_encode(['enabled' => false]);
		$recorder->record(before: $before, keys: ['rbac']);
	}//end testPerSectionSaveAnnounces()

	/**
	 * Nested blobs flatten so a nested credential is its own, masked, key.
	 *
	 * @return void
	 */
	public function testNestedCredentialIsItsOwnSecretKey(): void {
		$recorder = $this->recorder();
		$before = $recorder->snapshot(keys: ['llm']);
		$this->store['llm'] = json_encode(['openaiConfig' => ['apiKey' => 'sk-1', 'model' => 'x'], 'enabledFileTypes' => ['txt']]);
		$recorder->record(before: $before, keys: ['llm']);

		$call = $this->recorded[array_key_last($this->recorded)];
		$this->assertSame('sk-1', $call['after']['llm.openaiConfig.apiKey']);
		$this->assertContains('llm.openaiConfig.apiKey', $call['secretKeys']);
		$this->assertNotContains('llm.openaiConfig.model', $call['secretKeys']);
	}//end testNestedCredentialIsItsOwnSecretKey()

	/**
	 * A failing auditor never fails the save.
	 *
	 * @return void
	 */
	public function testAFailingAuditorNeverThrows(): void {
		$auditor = $this->createMock(SettingsChangeAuditor::class);
		$auditor->method('recordUpdate')->willThrowException(new \RuntimeException('database gone'));
		$recorder = new OwnSettingsChangeRecorder(
			$this->appConfig,
			$auditor,
			new SecuritySettingRegistry($this->appConfig),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(0, $recorder->record(before: ['settings' => [], 'security' => []], keys: ['rbac']));
	}//end testAFailingAuditorNeverThrows()
	/**
	 * Changing the LLM model through the LLM settings door is recorded, and the key stays secret (openregister#4100).
	 *
	 * @return void
	 */
	public function testLlmDoorRecordsTheChangedKeyAndMarksTheApiKeySecret(): void {
		$this->store['llm'] = json_encode(['enabled' => true, 'openaiConfig' => ['apiKey' => 'sk-old', 'model' => 'small']]);

		$handler = new LlmSettingsHandler($this->appConfig, 'openregister', $this->recorder());
		$handler->updateLLMSettingsOnly(['openaiConfig' => ['apiKey' => 'sk-new', 'model' => 'large']]);

		$this->assertSame(['small', 'large'], $this->lastChange('llm.openaiConfig.model'));
		$call = $this->recorded[array_key_last($this->recorded)];
		$this->assertContains('llm.openaiConfig.apiKey', $call['secretKeys']);
	}//end testLlmDoorRecordsTheChangedKeyAndMarksTheApiKeySecret()

	/**
	 * The file settings door is recorded (openregister#4100).
	 *
	 * @return void
	 */
	public function testFileDoorRecordsTheChangedKey(): void {
		$this->store['fileManagement'] = json_encode(['maxFileSize' => 100]);

		$handler = new FileSettingsHandler($this->appConfig, 'openregister', $this->recorder());
		$handler->updateFileSettingsOnly(['maxFileSize' => 200]);

		$this->assertSame([100, 200], $this->lastChange('fileManagement.maxFileSize'));
	}//end testFileDoorRecordsTheChangedKey()

	/**
	 * The search backend door is recorded (openregister#4100).
	 *
	 * @return void
	 */
	public function testSearchBackendDoorRecords(): void {
		$this->store['search_backend'] = json_encode(['active' => 'solr']);

		$handler = new SearchBackendHandler($this->appConfig, $this->createMock(LoggerInterface::class), 'openregister', $this->recorder());
		$handler->updateSearchBackendConfig('database');

		$this->assertSame(['solr', 'database'], $this->lastChange('search_backend.active'));
	}//end testSearchBackendDoorRecords()
}//end class
