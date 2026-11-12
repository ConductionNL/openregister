<?php

/**
 * OwnSettingsChangeRecorder: how settings are read into the flat before/after maps
 * the auditor diffs — plain typed keys, JSON blobs flattened to dotted paths,
 * lists kept whole, raw strings kept as-is, and unreadable keys skipped.
 *
 * OwnSettingsChangeRecorderTest covers the settings doors end to end; this file
 * pins the read and flatten rules through snapshot() and record().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Audit\SecuritySettingRegistry;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCA\OpenRegister\Service\Settings\SettingsAuditTrail;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder
 */
class OwnSettingsChangeRecorderReadTest extends TestCase {

	private IAppConfig&MockObject $config;
	private SettingsChangeAuditor&MockObject $auditor;
	private SecuritySettingRegistry&MockObject $registry;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
		$this->auditor = $this->createMock(SettingsChangeAuditor::class);
		$this->registry = $this->createMock(SecuritySettingRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function recorder(?SecuritySettingAnnouncer $announcer = null): OwnSettingsChangeRecorder {
		return new OwnSettingsChangeRecorder(
			$this->config,
			new SettingsAuditTrail($this->auditor, $this->registry, $announcer),
			$this->logger
		);
	}

	public function testPlainKeysAreReadByTheirStoredType(): void {
		$this->config->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => $key !== 'absent');
		$types = ['flag' => IAppConfig::VALUE_BOOL, 'days' => IAppConfig::VALUE_INT, 'name' => IAppConfig::VALUE_STRING];
		$this->config->method('getValueType')->willReturnCallback(static fn (string $app, string $key): int => $types[$key]);
		$this->config->method('getValueBool')->with('openregister', 'flag')->willReturn(true);
		$this->config->method('getValueInt')->with('openregister', 'days')->willReturn(30);
		$this->config->method('getValueString')->with('openregister', 'name', '')->willReturn('Acme');

		$snapshot = $this->recorder()->snapshot(['@flag', '@days', '@name', '@absent']);

		$this->assertSame(['settings' => ['flag' => true, 'days' => 30, 'name' => 'Acme'], 'security' => []], $snapshot);
	}

	public function testJsonSettingsAreFlattenedToDottedPathsWithListsKeptWhole(): void {
		$this->config->method('getValueString')->willReturnMap([
			['openregister', 'rbac', '', json_encode(['enabled' => true, 'groups' => ['admin', 'staff'], 'deep' => ['a' => ['b' => 1]], 'empty' => []])],
			['openregister', 'tags', '', json_encode(['x', 'y'])],
			['openregister', 'none', '', json_encode([])],
			['openregister', 'raw', '', 'not json'],
			['openregister', 'unset', '', ''],
		]);

		$settings = $this->recorder()->snapshot(['rbac', 'tags', 'none', 'raw', 'unset'])['settings'];

		$this->assertSame(
			[
				'rbac.enabled' => true,
				'rbac.groups' => ['admin', 'staff'],
				'rbac.deep.a.b' => 1,
				'rbac.empty' => [],
				'tags' => ['x', 'y'],
				'none' => [],
				'raw' => 'not json',
			],
			$settings
		);
	}

	public function testAnUnreadableKeyIsLoggedAndSkipped(): void {
		$this->config->method('getValueString')->willReturnCallback(static function (string $app, string $key): string {
			if ($key === 'broken') {
				throw new RuntimeException('type mismatch');
			}

			return 'ok';
		});
		$this->logger->expects($this->once())->method('warning')
			->with('[OwnSettingsChangeRecorder] Could not read setting broken: type mismatch');

		$this->assertSame(['good' => 'ok'], $this->recorder()->snapshot(['broken', 'good'])['settings']);
	}

	public function testSnapshotIncludesTheSecuritySnapshotWhenAnAnnouncerIsWired(): void {
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturn(['rbac.enabled' => true]);

		$this->assertSame(['rbac.enabled' => true], $this->recorder($announcer)->snapshot([])['security']);
	}

	public function testRecordDiffsBeforeAgainstTheFreshReadAndMarksSecretsFromBothSides(): void {
		$this->config->method('getValueString')->willReturn(json_encode(['apiKey' => 'new', 'model' => 'm']));
		$this->registry->method('isSecret')->willReturnCallback(static fn (string $path): bool => str_ends_with($path, 'Key') || $path === 'llm.oldToken');
		$this->auditor->expects($this->once())->method('recordUpdate')
			->with('openregister', ['llm.oldToken' => 't', 'llm.model' => 'old'], ['llm.apiKey' => 'new', 'llm.model' => 'm'], ['llm.oldToken', 'llm.apiKey'])
			->willReturn(2);

		$written = $this->recorder()->record(['settings' => ['llm.oldToken' => 't', 'llm.model' => 'old'], 'security' => []], ['llm']);

		$this->assertSame(2, $written);
	}

	public function testRecordAnnouncesSecurityChangesAgainstAFreshSnapshot(): void {
		$this->config->method('getValueString')->willReturn('');
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturn(['rbac.enabled' => false]);
		$announcer->expects($this->once())->method('announce')->with(['rbac.enabled' => true], ['rbac.enabled' => false]);
		$this->auditor->method('recordUpdate')->willReturn(0);

		$this->assertSame(0, $this->recorder($announcer)->record(['settings' => [], 'security' => ['rbac.enabled' => true]], ['rbac']));
	}

	public function testAFailingAnnouncementIsLoggedButKeepsTheWrittenCount(): void {
		$this->config->method('getValueString')->willReturn('');
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('announce')->willThrowException(new RuntimeException('activity down'));
		$this->auditor->method('recordUpdate')->willReturn(3);
		$this->logger->expects($this->once())->method('error')
			->with('[OwnSettingsChangeRecorder] A settings change was stored but not recorded: activity down', ['app' => 'openregister']);

		$this->assertSame(3, $this->recorder($announcer)->record(['settings' => [], 'security' => []], ['rbac']));
	}
}
