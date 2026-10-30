<?php

/**
 * Deferred listener chunks fit Nextcloud's job argument limit
 *
 * Drives AnnotationNotificationListener, the caller whose update entries
 * carry the object's previous data, into the real ListenerDeferralService,
 * with a job list that refuses an argument over the limit the way
 * Nextcloud's does: 4,000 characters on Nextcloud 32 and 33, 32,000 from 34.
 * Before this change one cron run lost eleven chunks of 25 update
 * notifications that way (zuiddrecht live test, 14 October 2026).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deferral
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deferral;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Support\ShutdownFunctionSpy;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../Support/ShutdownFunctionSpy.php';

/**
 * Update notifications of large objects reach the job list in chunks it takes.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
 */
class DeferredChunksFitTheJobLimitTest extends TestCase {

	/**
	 * The job argument limit of Nextcloud 34 and later.
	 */
	private const NC34_LIMIT = 32000;

	/**
	 * The arguments the job list took, per job.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $enqueued = [];

	/**
	 * The job class of each job the list took.
	 *
	 * @var array<int, string>
	 */
	private array $jobClasses = [];

	/**
	 * What was logged at error level.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * What was logged at warning level.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Reset the shutdown spy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ShutdownFunctionSpy::reset();
	}//end setUp()

	/**
	 * The real deferral service on a job list that enforces the limit.
	 *
	 * @param int $limit The job argument limit of the simulated Nextcloud.
	 *
	 * @return ListenerDeferralService
	 */
	private function deferral(int $limit = self::NC34_LIMIT): ListenerDeferralService {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument) use ($limit): void {
				// The check Nextcloud's JobList::add() makes.
				if (strlen((string)json_encode($argument)) > $limit) {
					throw new InvalidArgumentException("Background job arguments can't exceed " . $limit . ' characters (json encoded)');
				}

				$this->enqueued[]   = $argument;
				$this->jobClasses[] = $job;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new ListenerDeferralService(
			userSession: $this->createMock(IUserSession::class),
			organisation: $this->createMock(OrganisationService::class),
			jobList: $jobList,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
			argumentLimit: $limit,
		);
	}//end deferral()

	/**
	 * The notification listener on a schema that declares notifications.
	 *
	 * @param ListenerDeferralService $deferral The deferral service.
	 *
	 * @return AnnotationNotificationListener
	 */
	private function listener(ListenerDeferralService $deferral): AnnotationNotificationListener {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('case');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'onUpdate' => [
						'trigger' => ['type' => 'updated'],
						'channels' => ['nc-notification'],
						'subject' => 'Gewijzigd',
						'recipients' => [['kind' => 'users', 'users' => ['admin']]],
					],
				],
			]
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new AnnotationNotificationListener(
			dispatcher: $this->createMock(AnnotationNotificationDispatcher::class),
			schemaMapper: $schemaMapper,
			deferral: $deferral,
		);
	}//end listener()

	/**
	 * A case with this much data in it.
	 *
	 * @param string $uuid   The uuid.
	 * @param int    $length The length of its description.
	 *
	 * @return ObjectEntity
	 */
	private function caseOf(string $uuid, int $length): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('23');
		$object->setSchema('172');
		$object->setObject(['title' => 'Zaak ' . $uuid, 'description' => str_repeat('x', $length)]);
		return $object;
	}//end caseOf()

	/**
	 * Every uuid the job list took, in order.
	 *
	 * @return array<int, string>
	 */
	private function enqueuedUuids(): array {
		$uuids = [];
		foreach ($this->enqueued as $argument) {
			foreach ($argument['entries'] as $entry) {
				$uuids[] = $entry['uuid'];
			}
		}

		return $uuids;
	}//end enqueuedUuids()

	/**
	 * The limits of the Nextcloud versions this app supports, with an entry size that needs spreading at each.
	 *
	 * @return array<string, array{0: int, 1: int}> Limit and description length.
	 */
	public static function limits(): array {
		return [
			'Nextcloud 34 and later: 32,000' => [32000, 2000],
			'Nextcloud 32 and 33: 4,000' => [4000, 500],
		];
	}//end limits()

	/**
	 * Twenty-five updates of large cases, one listener chunk, all reach the
	 * job list, whichever limit the running Nextcloud has.
	 *
	 * @param int $limit  The job argument limit.
	 * @param int $length The length of each case's description.
	 *
	 * @return void
	 *
	 * @dataProvider limits
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes(int $limit, int $length): void {
		$deferral = $this->deferral(limit: $limit);
		$listener = $this->listener(deferral: $deferral);
		$expected = [];
		for ($i = 1; $i <= 25; $i++) {
			$uuid = 'case-' . $i;
			$expected[] = $uuid;
			$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: $uuid, length: $length), $this->caseOf(uuid: $uuid, length: $length)));
		}

		$deferral->flushAll();

		self::assertSame([], $this->errors, 'nothing was refused');
		self::assertSame([], $this->warnings, 'nothing had to be reduced');
		self::assertSame($expected, $this->enqueuedUuids(), 'every update, once, in order');
		self::assertGreaterThan(1, count($this->enqueued), 'more than one job');
		foreach ($this->enqueued as $argument) {
			self::assertLessThanOrEqual($limit, strlen((string)json_encode($argument)));
		}

		self::assertSame([AnnotationNotificationDispatchJob::class], array_values(array_unique($this->jobClasses)));
	}//end testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes()

	/**
	 * An update whose previous data alone passes the limit is enqueued without
	 * it: the notification is not lost, and the log names the reduction.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testAnOversizedUpdateIsKeptWithoutItsOldData(): void {
		$deferral = $this->deferral(limit: ListenerDeferralService::ARGUMENT_LENGTH_NC32);
		$listener = $this->listener(deferral: $deferral);
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'small-1', length: 100), $this->caseOf(uuid: 'small-1', length: 100)));
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'big', length: 5000), $this->caseOf(uuid: 'big', length: 5000)));
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'small-2', length: 100), $this->caseOf(uuid: 'small-2', length: 100)));

		$deferral->flushAll();

		self::assertSame([], $this->errors, 'nothing was dropped');
		self::assertSame(['small-1', 'big', 'small-2'], $this->enqueuedUuids());
		self::assertCount(1, $this->warnings);
		self::assertSame('big', $this->warnings[0][1]['uuid']);
		self::assertStringContainsString('calculatedChange rules are skipped', $this->warnings[0][0]);
		foreach ($this->enqueued as $argument) {
			foreach ($argument['entries'] as $entry) {
				self::assertSame($entry['uuid'] !== 'big', array_key_exists('oldData', $entry) && $entry['oldData'] !== null);
			}
		}
	}//end testAnOversizedUpdateIsKeptWithoutItsOldData()

	/**
	 * One entry too large for any job, whatever it carries, is named in the
	 * log; the others in its chunk still go.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo(): void {
		$deferral = $this->deferral();
		$deferral->defer(jobClass: AnnotationNotificationDispatchJob::class, entry: ['uuid' => 'small-1', 'trigger' => 'updated']);
		$deferral->defer(jobClass: AnnotationNotificationDispatchJob::class, entry: ['uuid' => 'huge', 'trigger' => 'updated', 'payload' => str_repeat('x', 40000)]);
		$deferral->defer(jobClass: AnnotationNotificationDispatchJob::class, entry: ['uuid' => 'small-2', 'trigger' => 'updated']);

		$deferral->flushAll();

		self::assertSame(['small-1', 'small-2'], $this->enqueuedUuids());
		self::assertCount(1, $this->errors);
		self::assertSame('huge', $this->errors[0][1]['uuid']);
	}//end testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo()

	/**
	 * Without an injected limit the service reads the running server's, and falls back to 4,000.
	 *
	 * @return void
	 */
	public function testTheLimitComesFromTheServerWhenItNamesOne(): void {
		$service = new ListenerDeferralService(
			userSession: $this->createMock(IUserSession::class),
			organisation: $this->createMock(OrganisationService::class),
			jobList: $this->createMock(IJobList::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$serverConstant = 'OC\\BackgroundJob\\JobList::MAX_ARGUMENT_JSON_LENGTH';
		$expected = ListenerDeferralService::ARGUMENT_LENGTH_NC32;
		if (defined($serverConstant) === true) {
			$expected = constant($serverConstant);
		}

		self::assertSame($expected, $service->getArgumentLimit());
	}//end testTheLimitComesFromTheServerWhenItNamesOne()

	/**
	 * Small entries still go as one job per chunk.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testSmallEntriesStayInOneJob(): void {
		$deferral = $this->deferral();
		$listener = $this->listener(deferral: $deferral);
		for ($i = 1; $i <= 10; $i++) {
			$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'c-' . $i, length: 50), $this->caseOf(uuid: 'c-' . $i, length: 50)));
		}

		$deferral->flushAll();

		self::assertCount(1, $this->enqueued);
		self::assertCount(10, $this->enqueued[0]['entries']);
	}//end testSmallEntriesStayInOneJob()
}//end class
