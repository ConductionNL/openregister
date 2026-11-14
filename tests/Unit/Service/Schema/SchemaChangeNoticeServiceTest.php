<?php

/**
 * SchemaChangeNoticeService: the reach on the gate and the notice after an acknowledged breaking change.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
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

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\SchemaChangeNoticeJob;
use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaFollowerMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ApiCaller\ApiCallRecorder;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Schema\SchemaChangeNoticeService;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Schema\SchemaChangeNoticeService
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\SchemaChangelog
 */
final class SchemaChangeNoticeServiceTest extends TestCase {

	private ApiCallRecordMapperInterface&MockObject $callers;

	private ApiCallRecorder&MockObject $recorder;

	private SchemaFollowerMapper&MockObject $followers;

	private SchemaChangelogMapperInterface&MockObject $changelog;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private PermissionCheckInterface&MockObject $permissions;

	private IUserManager&MockObject $users;

	private IManager&MockObject $notifications;

	private IJobList&MockObject $jobs;

	/** @var list<array{uid: string, subject: string, parameters: array}> */
	private array $sent = [];

	protected function setUp(): void {
		$this->callers = $this->createMock(ApiCallRecordMapperInterface::class);
		$this->recorder = $this->createMock(ApiCallRecorder::class);
		$this->recorder->method('isEnabled')->willReturn(true);
		$this->followers = $this->createMock(SchemaFollowerMapper::class);
		$this->changelog = $this->createMock(SchemaChangelogMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->registers->method('getAllRegisterIdsWithSchema')->with(12)->willReturn([3]);
		$register = new Register();
		$register->setSlug('zaken');
		$this->registers->method('find')->with(3, false, false)->willReturn($register);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid !== 'ghost');
		$this->notifications = $this->createMock(IManager::class);
		$this->notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$this->notifications->method('notify')->willReturnCallback(
			function (INotification $sent): void {
				$this->sent[] = $sent;
			}
		);
		$this->jobs = $this->createMock(IJobList::class);
	}//end setUp()

	/**
	 * A notification that records what it is set to when sent.
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$state = new \ArrayObject();
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				static function (...$args) use ($notification, $state, $setter): INotification {
					$state[$setter] = $args;
					return $notification;
				}
			);
		}

		$notification->method('getUser')->willReturnCallback(static fn (): string => $state['setUser'][0]);
		$notification->method('getSubjectParameters')->willReturnCallback(static fn (): array => $state['setSubject'][1]);
		$notification->method('getSubject')->willReturnCallback(static fn (): string => $state['setSubject'][0]);

		return $notification;
	}//end notification()

	private function service(): SchemaChangeNoticeService {
		return new SchemaChangeNoticeService(
			$this->callers,
			$this->recorder,
			$this->followers,
			$this->changelog,
			$this->registers,
			$this->schemas,
			$this->permissions,
			$this->users,
			$this->notifications,
			$this->jobs,
			new NullLogger()
		);
	}//end service()

	private static function melding(): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('melding');
		$schema->setTitle('Melding');
		return $schema;
	}//end melding()

	public function testTheGateShowsTwoSuppliersAndTheFollowers(): void {
		$this->followers->method('countFollowers')->with(12)->willReturn(1);
		$prefixes = ['/apps/openregister/api/objects/{id}/melding', '/apps/openregister/api/objects/zaken/melding'];
		$this->callers->expects($this->once())->method('countCallersOfRoutes')
			->with($prefixes, $this->callback(static fn (DateTime $from): bool => abs($from->getTimestamp() - strtotime('-90 days')) < 60))
			->willReturn(2);
		$top = [['principal' => 'leverancier-a', 'calls' => 40, 'lastSeen' => '2026-10-01T08:00:00+00:00'], ['principal' => 'leverancier-b', 'calls' => 3, 'lastSeen' => null]];
		$this->callers->expects($this->once())->method('findCallersOfRoutes')->with($prefixes, $this->anything(), 10)->willReturn($top);

		$reach = $this->service()->reach(self::melding());

		$this->assertSame(['affectedCallers' => ['recorded' => true, 'count' => 2, 'top' => $top], 'followers' => 1], $reach);
	}//end testTheGateShowsTwoSuppliersAndTheFollowers()

	public function testWithTheCallerRecordOffTheGateSaysSoInsteadOfZero(): void {
		$recorder = $this->createMock(ApiCallRecorder::class);
		$recorder->method('isEnabled')->willReturn(false);
		$this->recorder = $recorder;
		$this->followers->method('countFollowers')->willReturn(3);
		$this->callers->expects($this->never())->method('countCallersOfRoutes');

		$reach = $this->service()->reach(self::melding());

		$this->assertFalse($reach['affectedCallers']['recorded']);
		$this->assertArrayNotHasKey('count', $reach['affectedCallers']);
		$this->assertStringContainsString('switched off', $reach['affectedCallers']['message']);
		$this->assertSame(3, $reach['followers']);
	}//end testWithTheCallerRecordOffTheGateSaysSoInsteadOfZero()

	public function testASchemaWithoutASlugHasNoAttributableRoutes(): void {
		$schema = self::melding();
		$schema->setSlug('');

		$this->assertSame([], $this->service()->routePrefixes($schema));
	}//end testASchemaWithoutASlugHasNoAttributableRoutes()

	public function testRecipientsAreToldOnceAndOnlyWhenTheyExistAndMayRead(): void {
		$this->callers->method('findCallersOfRoutes')->willReturn(
			[['principal' => 'jan', 'calls' => 9, 'lastSeen' => null], ['principal' => '', 'calls' => 4, 'lastSeen' => null], ['principal' => 'ghost', 'calls' => 2, 'lastSeen' => null], ['principal' => 'kees', 'calls' => 1, 'lastSeen' => null]]
		);
		$this->followers->method('findUids')->with(12)->willReturn(['piet', 'jan']);
		$this->permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId): bool => $action === 'read' && $userId !== 'kees'
		);

		$this->assertSame(['jan', 'piet'], $this->service()->recipients(self::melding()));
	}//end testRecipientsAreToldOnceAndOnlyWhenTheyExistAndMayRead()

	public function testAFailingReadCheckRefusesTheRecipient(): void {
		$this->callers->method('findCallersOfRoutes')->willReturn([]);
		$this->followers->method('findUids')->willReturn(['jan']);
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('no session'));

		$this->assertSame([], $this->service()->recipients(self::melding()));
	}//end testAFailingReadCheckRefusesTheRecipient()

	public function testRecipientsAreCappedAtFiveHundred(): void {
		$many = array_map(static fn (int $i): array => ['principal' => 'u' . $i, 'calls' => 1, 'lastSeen' => null], range(1, 500));
		$this->callers->method('findCallersOfRoutes')->with($this->anything(), $this->anything(), 500)->willReturn($many);
		$this->followers->method('findUids')->willReturn(['late-follower']);
		$this->permissions->method('hasPermission')->willReturn(true);

		$recipients = $this->service()->recipients(self::melding());

		$this->assertCount(500, $recipients);
		$this->assertNotContains('late-follower', $recipients);
	}//end testRecipientsAreCappedAtFiveHundred()

	public function testASupplierIsToldAndTheCountIsRecorded(): void {
		$entry = new SchemaChangelog();
		$entry->setId(77);
		$entry->setSchemaId(12);
		$entry->setVersion('2.0.0');
		$this->changelog->method('find')->with(77)->willReturn($entry);
		$this->schemas->method('find')->with(12, [], false, false)->willReturn(self::melding());
		$this->callers->method('findCallersOfRoutes')->willReturn([['principal' => 'leverancier-a', 'calls' => 40, 'lastSeen' => null], ['principal' => 'leverancier-b', 'calls' => 3, 'lastSeen' => null]]);
		$this->followers->method('findUids')->willReturn([]);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->changelog->expects($this->once())->method('recordNotice')->with($entry, 2)->willReturn($entry);

		$told = $this->service()->send(77, 'Field toelichting is removed, use omschrijving');

		$this->assertSame(2, $told);
		$this->assertSame(['leverancier-a', 'leverancier-b'], array_map(static fn (INotification $n): string => $n->getUser(), $this->sent));
		$this->assertSame('schema_breaking_change', $this->sent[0]->getSubject());
		$this->assertSame(
			['schemaId' => 12, 'schema' => 'Melding', 'version' => '2.0.0', 'note' => 'Field toelichting is removed, use omschrijving'],
			$this->sent[0]->getSubjectParameters()
		);
	}//end testASupplierIsToldAndTheCountIsRecorded()

	public function testANoticeThatFailsToSendIsNotCounted(): void {
		$entry = new SchemaChangelog();
		$entry->setId(77);
		$entry->setSchemaId(12);
		$this->changelog->method('find')->willReturn($entry);
		$this->schemas->method('find')->willReturn(self::melding());
		$this->callers->method('findCallersOfRoutes')->willReturn([]);
		$this->followers->method('findUids')->willReturn(['jan']);
		$this->permissions->method('hasPermission')->willReturn(true);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willThrowException(new RuntimeException('down'));
		$this->notifications = $notifications;
		$this->changelog->expects($this->once())->method('recordNotice')->with($entry, 0)->willReturn($entry);

		$this->assertSame(0, $this->service()->send(77, null));
	}//end testANoticeThatFailsToSendIsNotCounted()

	public function testQueueingAddsOneJobWithTheEntryAndNote(): void {
		$entry = new SchemaChangelog();
		$entry->setId(77);
		$this->jobs->expects($this->once())->method('add')->with(SchemaChangeNoticeJob::class, ['changelogId' => 77, 'note' => 'n']);

		$this->service()->queue($entry, 'n');
	}//end testQueueingAddsOneJobWithTheEntryAndNote()

	public function testTheNoteIsPlainTrimmedAndBounded(): void {
		$this->assertNull(SchemaChangeNoticeService::note(null));
		$this->assertNull(SchemaChangeNoticeService::note('  '));
		$this->assertSame('Use omschrijving', SchemaChangeNoticeService::note(' <b>Use</b> omschrijving '));
		$this->assertSame(500, mb_strlen((string)SchemaChangeNoticeService::note(str_repeat('é', 500))));

		try {
			SchemaChangeNoticeService::note(str_repeat('x', 501));
			$this->fail('A note over 500 characters must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('changeNotice may be at most 500 characters.', $e->getMessage());
		}

		$this->expectExceptionMessage('changeNotice must be a text.');
		SchemaChangeNoticeService::note(['x']);
	}//end testTheNoteIsPlainTrimmedAndBounded()
}//end class
