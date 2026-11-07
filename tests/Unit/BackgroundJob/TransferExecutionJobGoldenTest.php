<?php

/**
 * Golden test for TransferExecutionJob::run().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Pins every call run() makes, whatever shape the job's constructor takes:
 * the job is built by reflection from a map of the collaborators below.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\TransferExecutionJob
 */
class TransferExecutionJobGoldenTest extends TestCase {

	/**
	 * The collaborators, keyed by the type the job asks for.
	 *
	 * @var array<string, object>
	 */
	private array $deps = [];

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The app config values the job reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The list loadTransferList() returns.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $list = null;

	/**
	 * The status executeAttempt() returns, or null to throw.
	 *
	 * @var string|null
	 */
	private ?string $outcome = TransferListService::STATUS_COMPLETED;

	protected function setUp(): void {
		parent::setUp();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => ($this->config[$key] ?? $default)
		);

		$records = $this->createMock(TransferRecordService::class);
		$records->method('loadTransferList')->willReturnCallback(
			function (string $id): ?array {
				$this->calls[] = ['load', $id];
				return $this->list;
			}
		);
		$records->method('saveTransferList')->willReturnCallback(
			function (array $list): array {
				$this->calls[] = ['save', $list];
				return $list;
			}
		);

		$transfer = $this->createMock(EdepotTransferService::class);
		$transfer->method('executeAttempt')->willReturnCallback(
			function (array $list, TransportInterface $transport, int $attempt): array {
				$this->calls[] = ['execute', $list['uuid'], $this->transportName(transport: $transport), $attempt];
				if ($this->outcome === null) {
					throw new \RuntimeException('transport down');
				}

				return array_merge($list, ['status' => $this->outcome]);
			}
		);

		$lists = $this->createMock(TransferListService::class);
		$lists->method('notifyArchivists')->willReturnCallback(
			function (array $list): void {
				$this->calls[] = ['notify', $list];
			}
		);

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $class, int $runAfter, mixed $argument): void {
				$this->calls[] = ['schedule', $class, $runAfter, $argument];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$this->deps = [
			ITimeFactory::class           => $time,
			EdepotTransferService::class  => $transfer,
			TransferRecordService::class  => $records,
			TransferListService::class    => $lists,
			SftpTransport::class          => $this->createMock(SftpTransport::class),
			RestApiTransport::class       => $this->createMock(RestApiTransport::class),
			OpenConnectorTransport::class => $this->createMock(OpenConnectorTransport::class),
			IJobList::class               => $jobList,
			IAppConfig::class             => $config,
			LoggerInterface::class        => $logger,
		];

		$this->list = ['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED];
	}//end setUp()

	/**
	 * Name the transport mock that was handed over.
	 *
	 * @param TransportInterface $transport The transport.
	 *
	 * @return string
	 */
	private function transportName(TransportInterface $transport): string {
		foreach ([SftpTransport::class => 'sftp', RestApiTransport::class => 'rest', OpenConnectorTransport::class => 'oc'] as $class => $name) {
			if ($this->deps[$class] === $transport) {
				return $name;
			}
		}

		return 'unknown';
	}//end transportName()

	/**
	 * Build a class from the collaborator map, constructing any type the map
	 * lacks from its own constructor.
	 *
	 * @param string $class The class to build.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		if (isset($this->deps[$class]) === true) {
			return $this->deps[$class];
		}

		$reflection = new ReflectionClass($class);
		$args       = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, $class.'::'.$parameter->getName());
			$args[] = $this->build($type->getName());
		}

		return $reflection->newInstanceArgs($args);
	}//end build()

	/**
	 * Run the job once.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function runJob(mixed $argument): void {
		$job = $this->build(TransferExecutionJob::class);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	public function testInvalidArgumentIsLoggedAndDropped(): void {
		$this->runJob(['attempt' => 2]);
		$this->runJob('nope');

		$message = '[TransferExecutionJob] Invalid job argument: missing transferListId';
		$this->assertSame([['error', $message, []], ['error', $message, []]], $this->calls);
	}//end testInvalidArgumentIsLoggedAndDropped()

	public function testMissingListIsLoggedAndDropped(): void {
		$this->list = null;
		$this->runJob(['transferListId' => 'gone']);

		$this->assertSame(
			[['load', 'gone'], ['error', '[TransferExecutionJob] Transfer list not found: gone', []]],
			$this->calls
		);
	}//end testMissingListIsLoggedAndDropped()

	public function testCompletedTransferIsSavedOnTheDefaultTransport(): void {
		$this->runJob(['transferListId' => 't1']);

		$this->assertSame(
			[
				['load', 't1'],
				['execute', 't1', 'rest', 1],
				['save', ['uuid' => 't1', 'status' => TransferListService::STATUS_COMPLETED]],
				['info', '[TransferExecutionJob] Transfer completed', ['transferListId' => 't1', 'attempt' => 1]],
			],
			$this->calls
		);
	}//end testCompletedTransferIsSavedOnTheDefaultTransport()

	public function testConfiguredTransportIsUsed(): void {
		foreach (['sftp' => 'sftp', 'openconnector' => 'oc', 'rest_api' => 'rest', 'other' => 'rest'] as $setting => $name) {
			$this->calls                      = [];
			$this->config['edepot_transport'] = $setting;
			$this->runJob(['transferListId' => 't1', 'attempt' => 3]);
			$this->assertSame(['execute', 't1', $name, 3], $this->calls[1], $setting);
		}
	}//end testConfiguredTransportIsUsed()

	public function testFailedAttemptIsRescheduledWithBackoff(): void {
		$this->outcome = TransferListService::STATUS_PARTIALLY_FAILED;
		$this->runJob(['transferListId' => 't1', 'attempt' => 2]);

		$this->assertCount(5, $this->calls);
		$this->assertSame(['load', 't1'], $this->calls[0]);
		$this->assertSame(['execute', 't1', 'rest', 2], $this->calls[1]);
		$this->assertSame(['save', ['uuid' => 't1', 'status' => TransferListService::STATUS_IN_PROGRESS]], $this->calls[2]);

		// Attempt 2 backs off 120 s with up to 10 % jitter either way.
		[$verb, $class, $runAfter, $argument] = $this->calls[3];
		$this->assertSame(['schedule', TransferExecutionJob::class, ['transferListId' => 't1', 'attempt' => 3]], [$verb, $class, $argument]);
		$this->assertGreaterThanOrEqual(1000108, $runAfter);
		$this->assertLessThanOrEqual(1000132, $runAfter);

		$context = ['transferListId' => 't1', 'attempt' => 2, 'nextAttempt' => 3, 'delaySeconds' => ($runAfter - 1000000)];
		$this->assertSame(['info', '[TransferExecutionJob] Attempt failed — rescheduled', $context], $this->calls[4]);
	}//end testFailedAttemptIsRescheduledWithBackoff()

	public function testThrownAttemptCountsAsAFailure(): void {
		$this->outcome = null;
		$this->config['edepot_transfer_max_attempts'] = '1';
		$this->runJob(['transferListId' => 't1']);

		$failed = ['uuid' => 't1', 'status' => TransferListService::STATUS_FAILED];
		$this->assertSame(
			[
				['load', 't1'],
				['execute', 't1', 'rest', 1],
				['error', '[TransferExecutionJob] Attempt threw: transport down', ['transferListId' => 't1', 'attempt' => 1]],
				['save', $failed],
				['notify', $failed],
				[
					'warning',
					'[TransferExecutionJob] Attempt cap reached — transfer failed, archivists notified',
					['transferListId' => 't1', 'attempt' => 1, 'maxAttempts' => 1],
				],
			],
			$this->calls
		);
	}//end testThrownAttemptCountsAsAFailure()

	public function testAttemptCapFallsBackToTenWhenConfiguredBelowOne(): void {
		$this->outcome = TransferListService::STATUS_PARTIALLY_FAILED;
		$this->config['edepot_transfer_max_attempts'] = '0';
		$this->runJob(['transferListId' => 't1', 'attempt' => 10]);

		$this->assertSame('notify', $this->calls[3][0]);
		$this->assertSame(['transferListId' => 't1', 'attempt' => 10, 'maxAttempts' => 10], $this->calls[4][2]);

		$this->calls = [];
		$this->runJob(['transferListId' => 't1', 'attempt' => 9]);
		$this->assertSame('schedule', $this->calls[3][0]);
	}//end testAttemptCapFallsBackToTenWhenConfiguredBelowOne()
}//end class
