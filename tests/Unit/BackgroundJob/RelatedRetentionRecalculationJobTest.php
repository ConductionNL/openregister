<?php

/**
 * A client's end date moves, and its contact persons' destruction dates follow.
 *
 * Runs the real index, calculator and retention service over mocked mappers,
 * so the assertion is on the dates the job stores, not on which collaborator
 * it called.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob
 * @covers \OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener
 * @covers \OCA\OpenRegister\Service\Archival\DependantRetentionIndex
 */
class RelatedRetentionRecalculationJobTest extends TestCase {

	private const CLIENT = 'c1c1c1c1-0000-0000-0000-000000000001';

	private MagicMapper&MockObject $objectMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private RegisterMapper&MockObject $registerMapper;
	private DependantRetentionIndex $index;
	private RelatedRetentionRecalculationJob $job;
	private Schema $clientSchema;
	private Schema $contactSchema;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->clientSchema = new Schema();
		$this->clientSchema->setId(7);
		$this->clientSchema->setSlug('client');
		$this->clientSchema->setArchive([
			'enabled' => true,
			'afleidingswijze' => 'ander_datumkenmerk',
			'sourceDateProperty' => 'relationshipEndedAt',
			'defaultBewaartermijn' => 'P2Y',
		]);

		$this->contactSchema = new Schema();
		$this->contactSchema->setId(8);
		$this->contactSchema->setSlug('contact');
		$this->contactSchema->setProperties(['client' => ['type' => 'string', '$ref' => '#/components/schemas/client']]);
		$this->contactSchema->setArchive([
			'enabled' => true,
			'afleidingswijze' => 'gerelateerde_zaak',
			'sourceRelation' => 'client',
			'sourceRelationProperty' => 'relationshipEndedAt',
			'defaultBewaartermijn' => 'P2Y',
		]);

		$this->schemaMapper->method('findAll')->willReturn([$this->clientSchema, $this->contactSchema]);
		$this->schemaMapper->method('find')->willReturn($this->clientSchema);

		$calculator = new ArchiveActionDateCalculator($this->objectMapper, $logger);
		$this->index = new DependantRetentionIndex($this->schemaMapper, $calculator);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$retention = new RetentionService(
			$this->objectMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(AuditTrailMapper::class),
			$settings,
			$this->createMock(IAppConfig::class),
			$this->createMock(IUserSession::class),
			$logger,
			$this->createMock(RetentionRowScanner::class),
			$calculator,
			new SelectielijstResolver($this->objectMapper, $this->schemaMapper, $this->registerMapper, $settings, $logger),
		);

		$this->job = new RelatedRetentionRecalculationJob(
			$this->createMock(ITimeFactory::class),
			$this->objectMapper,
			$this->schemaMapper,
			$this->registerMapper,
			$this->index,
			$calculator,
			$retention,
			$logger,
		);
	}//end setUp()

	/**
	 * A contact person of the client, created before the client had an end date.
	 *
	 * @param string $uuid   The contact's uuid.
	 * @param string $client The client it points at.
	 *
	 * @return ObjectEntity The contact.
	 */
	private function contact(string $uuid, string $client): ObjectEntity {
		$contact = new ObjectEntity();
		$contact->setUuid($uuid);
		$contact->setSchema('8');
		$contact->setObject(['name' => $uuid, 'client' => $client]);
		$contact->setRetention(['archiefnominatie' => 'vernietigen', 'archiefstatus' => 'active', 'bewaartermijn' => 'P2Y']);

		return $contact;
	}//end contact()

	/**
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function testAClientsEndDateRecalculatesItsTwoContactPersons(): void {
		$client = new ObjectEntity();
		$client->setUuid(self::CLIENT);
		$client->setSchema('7');
		$client->setObject(['name' => 'Bakkerij Jansen', 'relationshipEndedAt' => '2026-10-01']);
		$this->objectMapper->method('find')->willReturn($client);

		$register = new Register();
		$register->setId(3);
		$this->registerMapper->method('getAllRegisterIdsWithSchema')->with(8)->willReturn([3]);
		$this->registerMapper->method('find')->willReturn($register);

		$first = $this->contact(uuid: 'k1', client: self::CLIENT);
		$second = $this->contact(uuid: 'k2', client: self::CLIENT);
		// Found through the relation index, but its derivation reference is
		// another client: it must be left alone.
		$other = $this->contact(uuid: 'k3', client: 'another-client');
		$this->objectMapper->method('findByRelationBatchInSchema')
			->with([self::CLIENT], 8, 3, 'client', ['client'])
			->willReturn([$first, $second, $other]);

		$stored = [];
		$this->objectMapper->expects($this->exactly(2))
			->method('update')
			->willReturnCallback(
				static function (ObjectEntity $entity) use (&$stored): ObjectEntity {
					$stored[$entity->getUuid()] = $entity->getRetention()['archiefactiedatum'];
					return $entity;
				}
			);

		$changed = $this->job->recalculateDependantsOf(uuid: self::CLIENT);

		$this->assertSame(2, $changed);
		$this->assertSame(['k1' => '2028-10-01', 'k2' => '2028-10-01'], $stored);
		$this->assertArrayNotHasKey('archiefactiedatum', $other->getRetention());
	}//end testAClientsEndDateRecalculatesItsTwoContactPersons()

	/**
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function testTheListenerQueuesOnceWhenTheWatchedDateChanges(): void {
		$jobList = $this->createMock(IJobList::class);
		$listener = new RelatedRetentionRecalculationListener(
			$this->schemaMapper,
			$this->index,
			$jobList,
			$this->createMock(LoggerInterface::class)
		);

		$old = new ObjectEntity();
		$old->setUuid(self::CLIENT);
		$old->setSchema('7');
		$old->setObject(['name' => 'Bakkerij Jansen']);
		$new = clone $old;
		$new->setObject(['name' => 'Bakkerij Jansen', 'relationshipEndedAt' => '2026-10-01']);

		$jobList->method('has')->willReturnOnConsecutiveCalls(false, true);
		$jobList->expects($this->once())
			->method('add')
			->with(RelatedRetentionRecalculationJob::class, ['uuid' => self::CLIENT]);

		$listener->handle(new ObjectUpdatedEvent($new, $old));
		$listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testTheListenerQueuesOnceWhenTheWatchedDateChanges()

	/**
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function testTheListenerIgnoresAnEditThatLeavesTheDateAlone(): void {
		$jobList = $this->createMock(IJobList::class);
		$listener = new RelatedRetentionRecalculationListener(
			$this->schemaMapper,
			$this->index,
			$jobList,
			$this->createMock(LoggerInterface::class)
		);

		$old = new ObjectEntity();
		$old->setUuid(self::CLIENT);
		$old->setSchema('7');
		$old->setObject(['name' => 'Bakkerij Jansen', 'relationshipEndedAt' => '2026-10-01']);
		$new = clone $old;
		$new->setObject(['name' => 'Bakker Jansen', 'relationshipEndedAt' => '2026-10-01']);

		$jobList->expects($this->never())->method('add');

		$listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testTheListenerIgnoresAnEditThatLeavesTheDateAlone()
}//end class
