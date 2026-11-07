<?php

/**
 * Golden outcomes of the settings change recorder's audit and secret handling.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Audit\SecuritySettingRegistry;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what the recorder writes to the audit trail, which keys it marks
 * secret (masked) and which it leaves readable, that it announces, and that a
 * failing audit write never fails the save nor puts a secret in the log.
 */
class OwnSettingsChangeRecorderAccessGoldenTest extends TestCase {

	/**
	 * The stored app config values, key to string.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The recordUpdate() calls, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * The logged error messages.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Build the recorder under test.
	 *
	 * @param SettingsChangeAuditor         $auditor   The auditor.
	 * @param SecuritySettingAnnouncer|null $announcer The announcer, if any.
	 *
	 * @return OwnSettingsChangeRecorder The recorder.
	 */
	private function recorder(SettingsChangeAuditor $auditor, ?SecuritySettingAnnouncer $announcer = null): OwnSettingsChangeRecorder {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
		);
		$appConfig->method('hasKey')->willReturnCallback(
			fn (string $app, string $key) => array_key_exists($key, $this->store)
		);
		$appConfig->method('getValueType')->willReturn(IAppConfig::VALUE_STRING);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		return new OwnSettingsChangeRecorder(
			$appConfig,
			$auditor,
			new SecuritySettingRegistry($appConfig),
			$logger,
			$announcer
		);
	}//end recorder()

	/**
	 * An auditor that captures every call.
	 *
	 * @return SettingsChangeAuditor The auditor.
	 */
	private function capturingAuditor(): SettingsChangeAuditor {
		$auditor = $this->createMock(SettingsChangeAuditor::class);
		$auditor->method('recordUpdate')->willReturnCallback(
			function (string $app, array $before, array $after, array $secretKeys = []): int {
				$this->recorded[] = [
					'app' => $app,
					'before' => $before,
					'after' => $after,
					'secretKeys' => $secretKeys,
				];
				return 3;
			}
		);
		return $auditor;
	}//end capturingAuditor()

	/**
	 * A change is written with its before and after; a credential is marked secret, a plain field is not.
	 *
	 * @return void
	 */
	public function testAChangeIsRecordedAndOnlyCredentialsAreMarkedSecret(): void {
		$this->store['llm'] = (string) json_encode(['openaiConfig' => ['apiKey' => 'old-key', 'model' => 'small']]);
		$recorder = $this->recorder($this->capturingAuditor());

		$before = $recorder->snapshot(keys: ['llm']);
		$this->store['llm'] = (string) json_encode(['openaiConfig' => ['apiKey' => 'new-key', 'model' => 'large']]);

		$this->assertSame(3, $recorder->record(before: $before, keys: ['llm']));
		$this->assertCount(1, $this->recorded);
		$call = $this->recorded[0];
		$this->assertSame('openregister', $call['app']);
		$this->assertSame(['llm.openaiConfig.apiKey' => 'old-key', 'llm.openaiConfig.model' => 'small'], $call['before']);
		$this->assertSame(['llm.openaiConfig.apiKey' => 'new-key', 'llm.openaiConfig.model' => 'large'], $call['after']);
		$this->assertSame(['llm.openaiConfig.apiKey'], $call['secretKeys']);
	}//end testAChangeIsRecordedAndOnlyCredentialsAreMarkedSecret()

	/**
	 * A plain, non-secret change is recorded with no secret keys.
	 *
	 * @return void
	 */
	public function testAPlainChangeHasNoSecretKeys(): void {
		$this->store['rbac'] = (string) json_encode(['enabled' => true]);
		$recorder = $this->recorder($this->capturingAuditor());

		$before = $recorder->snapshot(keys: ['rbac']);
		$this->store['rbac'] = (string) json_encode(['enabled' => false]);
		$recorder->record(before: $before, keys: ['rbac']);

		$this->assertSame(['rbac.enabled' => true], $this->recorded[0]['before']);
		$this->assertSame(['rbac.enabled' => false], $this->recorded[0]['after']);
		$this->assertSame([], $this->recorded[0]['secretKeys']);
	}//end testAPlainChangeHasNoSecretKeys()

	/**
	 * The announcer is asked for its snapshot and told what moved.
	 *
	 * @return void
	 */
	public function testTheAnnouncerIsTold(): void {
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturnOnConsecutiveCalls(['rbac.enabled' => true], ['rbac.enabled' => false]);
		$announcer->expects($this->once())->method('announce')->with(['rbac.enabled' => true], ['rbac.enabled' => false]);
		$recorder = $this->recorder($this->capturingAuditor(), $announcer);

		$before = $recorder->snapshot(keys: ['rbac']);
		$this->assertSame(['rbac.enabled' => true], $before['security']);
		$recorder->record(before: $before, keys: ['rbac']);
	}//end testTheAnnouncerIsTold()

	/**
	 * Without an announcer the snapshot carries no security state.
	 *
	 * @return void
	 */
	public function testWithoutAnAnnouncerTheSecuritySnapshotIsEmpty(): void {
		$this->store['rbac'] = (string) json_encode(['enabled' => true]);
		$recorder = $this->recorder($this->capturingAuditor());

		$this->assertSame(['settings' => ['rbac.enabled' => true], 'security' => []], $recorder->snapshot(keys: ['rbac']));
	}//end testWithoutAnAnnouncerTheSecuritySnapshotIsEmpty()

	/**
	 * A refused audit write returns zero, never throws, and never logs the secret value.
	 *
	 * @return void
	 */
	public function testARefusedAuditWriteReturnsZeroAndKeepsTheSecretOutOfTheLog(): void {
		$this->store['llm'] = (string) json_encode(['openaiConfig' => ['apiKey' => 'hidden-value']]);
		$auditor = $this->createMock(SettingsChangeAuditor::class);
		$auditor->method('recordUpdate')->willThrowException(new RuntimeException('audit store refused'));
		$recorder = $this->recorder($auditor);

		$this->assertSame(0, $recorder->record(before: ['settings' => [], 'security' => []], keys: ['llm']));
		$this->assertCount(1, $this->errors);
		$this->assertStringContainsString('audit store refused', $this->errors[0]);
		$this->assertStringNotContainsString('hidden-value', $this->errors[0]);
	}//end testARefusedAuditWriteReturnsZeroAndKeepsTheSecretOutOfTheLog()
}//end class
