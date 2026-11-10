<?php

/**
 * ConfigurationCheckJobGoldenTest.
 *
 * Pins the complete log transcript (level, message, file context), the service
 * calls and the job interval of ConfigurationCheckJob for every branch, so a
 * restructuring of the job cannot change what it does or reports. Written
 * against the code before its refactor. Line numbers in the log context are
 * deliberately not pinned: they move with any edit.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use Exception;
use OCA\OpenRegister\BackgroundJob\ConfigurationCheckJob;
use OCA\OpenRegister\BackgroundJob\Support\ConfigurationCheckServices;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * Golden transcript of the configuration check job.
 */
class ConfigurationCheckJobGoldenTest extends TestCase {

	/**
	 * Every log call as [level, message, file].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string|null}>
	 */
	private array $log = [];

	/**
	 * Every service call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The mapper double.
	 *
	 * @var ConfigurationMapper&MockObject
	 */
	private ConfigurationMapper&MockObject $mapper;

	/**
	 * The configuration service double.
	 *
	 * @var ConfigurationServiceInterface&MockObject
	 */
	private ConfigurationServiceInterface&MockObject $service;

	/**
	 * The notification service double.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService&MockObject $notifications;

	/**
	 * Build the job with recording doubles.
	 *
	 * @param string $interval The configured interval.
	 *
	 * @return ConfigurationCheckJob
	 */
	private function job(string $interval): ConfigurationCheckJob {
		$this->mapper        = $this->createMock(ConfigurationMapper::class);
		$this->service       = $this->createMock(ConfigurationServiceInterface::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$appConfig           = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($interval): string {
				$this->calls[] = "appConfig:$app:$key:$default";
				return $interval;
			}
		);

		$log    = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param array<int, mixed> $log The transcript.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|Stringable    $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->log[] = [(string)$level, (string)$message, isset($context['file']) ? basename($context['file']) : null, isset($context['line'])];
			}
		};

		return new ConfigurationCheckJob(
			new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $appConfig, $logger),
			new ConfigurationCheckServices($this->mapper, $this->service, $this->notifications)
		);
	}//end job()

	/**
	 * Run the job's protected run().
	 *
	 * @param ConfigurationCheckJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(ConfigurationCheckJob $job): void {
		$method = (new ReflectionClass($job))->getMethod('run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The job's interval.
	 *
	 * @param ConfigurationCheckJob $job The job.
	 *
	 * @return int
	 */
	private static function interval(ConfigurationCheckJob $job): int {
		return (int)(new ReflectionClass($job))->getProperty('interval')->getValue($job);
	}//end interval()

	/**
	 * A configuration entity.
	 *
	 * @param int         $id         The id.
	 * @param string      $sourceType The source type.
	 * @param string|null $local      The local version.
	 * @param bool        $auto       Whether auto-update is on.
	 *
	 * @return Configuration
	 */
	private static function config(int $id, string $sourceType, ?string $local, bool $auto): Configuration {
		$config = new Configuration();
		$config->setId($id);
		$config->setTitle('Config ' . $id);
		$config->setSourceType($sourceType);
		if ($local !== null) {
			$config->setLocalVersion($local);
			$config->setRemoteVersion('2.0.0');
		}

		$config->setAutoUpdate($auto);
		return $config;
	}//end config()

	/**
	 * Interval and constructor log, enabled.
	 *
	 * @return void
	 */
	public function testConstructorEnabled(): void {
		$job = $this->job('1800');

		$this->assertSame(1800, self::interval($job));
		$this->assertSame(
			[['debug', '[ConfigurationCheckJob] Configuration check job interval set to 1800 seconds', 'ConfigurationCheckJob.php', true]],
			$this->log
		);
		$this->assertSame(['appConfig:openregister:configuration_check_interval:3600'], $this->calls);
	}//end testConstructorEnabled()

	/**
	 * Interval, constructor log and run, disabled.
	 *
	 * @return void
	 */
	public function testDisabled(): void {
		$job = $this->job('0');
		$this->mapper->expects($this->never())->method('findAll');
		self::runJob($job);

		$this->assertSame(86400 * 365, self::interval($job));
		$this->assertSame(
			[
				['debug', '[ConfigurationCheckJob] Configuration check job is disabled (interval set to 0)', 'ConfigurationCheckJob.php', true],
				['debug', '[ConfigurationCheckJob] Starting configuration check job', 'ConfigurationCheckJob.php', true],
				['debug', '[ConfigurationCheckJob] Configuration check job is disabled, skipping', 'ConfigurationCheckJob.php', true],
			],
			$this->log
		);
		$this->assertSame(
			['appConfig:openregister:configuration_check_interval:3600', 'appConfig:openregister:configuration_check_interval:3600'],
			$this->calls
		);
	}//end testDisabled()

	/**
	 * Every per-configuration branch in one run.
	 *
	 * @return void
	 */
	public function testEveryBranchTranscript(): void {
		$job     = $this->job('3600');
		$configs = [
			self::config(1, 'local', '1.0.0', true),
			self::config(2, 'github', null, false),
			self::config(3, 'github', null, false),
			self::config(4, 'url', '1.0.0', true),
			self::config(5, 'gitlab', '1.0.0', true),
			self::config(6, 'github', '1.0.0', false),
			self::config(7, 'github', '1.0.0', false),
			self::config(8, 'github', '1.0.0', false),
		];
		$this->mapper->method('findAll')->willReturn($configs);
		$this->service->method('checkRemoteVersion')->willReturnCallback(
			function (Configuration $config): ?string {
				$this->calls[] = 'check:' . $config->getId();
				return match ($config->getId()) {
					2 => null,
					8 => throw new Exception('remote down'),
					default => '2.0.0',
				};
			}
		);
		$this->service->method('importConfigurationWithSelection')->willReturnCallback(
			function (Configuration $config, array $selection): array {
				$this->calls[] = 'import:' . $config->getId() . ':' . json_encode($selection);
				if ($config->getId() === 5) {
					throw new Exception('import broke');
				}

				return [];
			}
		);
		$this->notifications->method('notifyConfigurationUpdate')->willReturnCallback(
			function (Configuration $config): int {
				$this->calls[] = 'notify:' . $config->getId();
				if ($config->getId() === 7) {
					throw new Exception('mail down');
				}

				return 4;
			}
		);
		$this->log   = [];
		$this->calls = [];
		self::runJob($job);

		$f = 'ConfigurationCheckJob.php';
		$this->assertSame(
			[
				['debug', '[ConfigurationCheckJob] Starting configuration check job', $f, true],
				['debug', '[ConfigurationCheckJob] Found 8 configurations to check', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 2 (ID: 2)', $f, true],
				['warning', '[ConfigurationCheckJob] No remote version for config 2', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 3 (ID: 3)', $f, true],
				['debug', '[ConfigurationCheckJob] Configuration Config 3 is up to date', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 4 (ID: 4)', $f, true],
				['debug', '[ConfigurationCheckJob] Update available for Config 4: 1.0.0 → 2.0.0', $f, true],
				['debug', '[ConfigurationCheckJob] Auto-update enabled, importing updates for Config 4', $f, true],
				['debug', '[ConfigurationCheckJob] Successfully auto-updated configuration Config 4', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 5 (ID: 5)', $f, true],
				['debug', '[ConfigurationCheckJob] Update available for Config 5: 1.0.0 → 2.0.0', $f, true],
				['debug', '[ConfigurationCheckJob] Auto-update enabled, importing updates for Config 5', $f, true],
				['error', '[ConfigurationCheckJob] Auto-update failed: import broke', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 6 (ID: 6)', $f, true],
				['debug', '[ConfigurationCheckJob] Update available for Config 6: 1.0.0 → 2.0.0', $f, true],
				['debug', '[ConfigurationCheckJob] Auto-update disabled for Config 6, sending notification', $f, true],
				['info', '[ConfigurationCheckJob] Sent 4 notifications', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 7 (ID: 7)', $f, true],
				['debug', '[ConfigurationCheckJob] Update available for Config 7: 1.0.0 → 2.0.0', $f, true],
				['debug', '[ConfigurationCheckJob] Auto-update disabled for Config 7, sending notification', $f, true],
				['error', '[ConfigurationCheckJob] Failed to send notifications for Config 7: mail down', $f, true],
				['debug', '[ConfigurationCheckJob] Checking Config 8 (ID: 8)', $f, true],
				['error', '[ConfigurationCheckJob] Error checking configuration 8: remote down', $f, true],
				['debug', '[ConfigurationCheckJob] Completed: 6 checked, 1 updated, 2 failed', $f, true],
			],
			$this->log
		);
		$this->assertSame(
			[
				'appConfig:openregister:configuration_check_interval:3600',
				'check:2',
				'check:3',
				'check:4',
				'import:4:[]',
				'check:5',
				'import:5:[]',
				'check:6',
				'notify:6',
				'check:7',
				'notify:7',
				'check:8',
			],
			$this->calls
		);
	}//end testEveryBranchTranscript()

	/**
	 * A failing findAll is logged and swallowed.
	 *
	 * @return void
	 */
	public function testFindAllFailureTranscript(): void {
		$job = $this->job('3600');
		$this->mapper->method('findAll')->willThrowException(new Exception('db gone'));
		$this->log = [];
		self::runJob($job);

		$this->assertSame(
			[
				['debug', '[ConfigurationCheckJob] Starting configuration check job', 'ConfigurationCheckJob.php', true],
				['error', '[ConfigurationCheckJob] Configuration check job failed: db gone', 'ConfigurationCheckJob.php', true],
			],
			$this->log
		);
	}//end testFindAllFailureTranscript()
}//end class
