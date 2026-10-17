<?php

/**
 * Deferred listener chunks fit Nextcloud's job argument limit
 *
 * Drives AnnotationNotificationListener, the caller whose update entries
 * carry the object's previous data, into the real ListenerDeferralService,
 * with a job list that refuses an argument over 32,000 characters the way
 * Nextcloud's does. Before this change one cron run lost eleven chunks of 25
 * update notifications that way (zuiddrecht live test, 14 October 2026).
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
 * @spec openspec/changes/deferred-listener-batches-stay-under-the-job-limit/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
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
 * @spec openspec/changes/deferred-listener-batches-stay-under-the-job-limit/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
 */
class DeferredChunksFitTheJobLimitTest extends TestCase {

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
	 * @return ListenerDeferralService
	 */
	private function deferral(): ListenerDeferralService {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, mixed $argument): void {
				// The check Nextcloud's JobList::add() makes.
				if (strlen((string)json_encode($argument)) > 32000) {
					throw new InvalidArgumentException("Background job arguments can't exceed 32000 characters (json encoded)");
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

		return new ListenerDeferralService(
			userSession: $this->createMock(IUserSession::class),
			organisation: $this->createMock(OrganisationService::class),
			jobList: $jobList,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $logger,
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
	 * Twenty-five updates of cases of about 2,000 characters each, one
	 * listener chunk, all reach the job list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deferred-listener-batches-stay-under-the-job-limit/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes(): void {
		$deferral = $this->deferral();
		$listener = $this->listener(deferral: $deferral);
		$expected = [];
		for ($i = 1; $i <= 25; $i++) {
			$uuid = 'case-' . $i;
			$expected[] = $uuid;
			$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: $uuid, length: 2000), $this->caseOf(uuid: $uuid, length: 2000)));
		}

		$deferral->flushAll();

		self::assertSame([], $this->errors, 'nothing was refused');
		self::assertSame($expected, $this->enqueuedUuids(), 'every update, once, in order');
		self::assertGreaterThan(1, count($this->enqueued), 'more than one job');
		foreach ($this->enqueued as $argument) {
			self::assertLessThanOrEqual(ListenerDeferralService::MAX_ARGUMENT_LENGTH, strlen((string)json_encode($argument)));
		}

		self::assertSame([AnnotationNotificationDispatchJob::class], array_values(array_unique($this->jobClasses)));
	}//end testAChunkOfLargeUpdatesIsSpreadOverJobsTheListTakes()

	/**
	 * One update too large for any job is named in the log; the others in
	 * its chunk still go.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deferred-listener-batches-stay-under-the-job-limit/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo(): void {
		$deferral = $this->deferral();
		$listener = $this->listener(deferral: $deferral);
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'small-1', length: 100), $this->caseOf(uuid: 'small-1', length: 100)));
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'huge', length: 40000), $this->caseOf(uuid: 'huge', length: 40000)));
		$listener->handle(new ObjectUpdatedEvent($this->caseOf(uuid: 'small-2', length: 100), $this->caseOf(uuid: 'small-2', length: 100)));

		$deferral->flushAll();

		self::assertSame(['small-1', 'small-2'], $this->enqueuedUuids());
		self::assertCount(1, $this->errors);
		self::assertSame('huge', $this->errors[0][1]['uuid']);
	}//end testAnEntryTooLargeForAnyJobIsNamedAndTheRestGo()

	/**
	 * Small entries still go as one job per chunk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/deferred-listener-batches-stay-under-the-job-limit/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
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
