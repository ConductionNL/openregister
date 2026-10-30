<?php

/**
 * One object's history is readable by whoever may read that object.
 *
 * Driven through the REAL AuditTrailController, the REAL
 * ObjectHistoryForReaders and the REAL HistoryEntryRedactor, with the REAL PropertyRbacHandler deciding which
 * properties are withheld. Only the object read itself (ObjectReadAccess), the
 * mappers and the doelbinding guard are mocked, so the read decision is a
 * fixture and everything this change adds runs as it does in production.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-an-objects-history-withholds-from-its-reader-what-the-object-withholds
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailPageQuery;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Service\Object\ObjectReadAccess;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class AuditTrailObjectHistoryForReadersTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private const OBJECT_UUID = '0b6f6b8e-1d1a-4c55-9a43-6a0d2f1f7c01';

	private IRequest&MockObject $request;
	private LogService&MockObject $logService;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private ObjectReadAccess&MockObject $readAccess;
	private RegisterMapper&MockObject $registerMapper;
	private PurposeGuard&MockObject $purposeGuard;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn([]);
		$this->logService = $this->createMock(LogService::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->readAccess = $this->createMock(ObjectReadAccess::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->purposeGuard = $this->createMock(PurposeGuard::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$register = new Register();
		$register->setId(5);
		$this->registerMapper->method('find')->willReturn($register);

		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setProperties([
			'titel' => ['type' => 'string'],
			'interneAantekening' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'redactie']]],
			],
			'apiToken' => ['type' => 'string', 'writeOnly' => true],
			'configuratie' => ['type' => 'object'],
		]);
		$this->schema->setConfiguration([
			'objectSummaryField' => 'interneAantekening',
			Schema::WRITEONLY_PATHS_ANNOTATION => ['configuratie.geheim'],
		]);
	}

	/**
	 * The groups `audit.readEventsVisibleTo` names, as JSON.
	 */
	private string $readEventGroups = '[]';

	private function controllerFor(string $uid, bool $isAdmin, array $groups = []): AuditTrailController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($isAdmin);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
		$this->groupManager->method('isInGroup')->willReturnCallback(static fn (string $user, string $group): bool => in_array($group, $groups, true));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === ReadEventVisibility::CONFIG_KEY ? $this->readEventGroups : $default
		);

		$properties = new PropertyRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($this->userSession, $this->groupManager)
		);

		return new AuditTrailController(
			'openregister',
			$this->request,
			$this->logService,
			$this->auditTrailMapper,
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			new AuditTrailPageService(
				$this->createMock(AuditTrailPageQuery::class),
				$this->logService,
				$this->createMock(IJobList::class)
			),
			new ObjectHistoryForReaders(
				$this->readAccess,
				$this->auditTrailMapper,
				new HistoryEntryRedactor($properties),
				$this->registerMapper,
				$this->purposeGuard,
				new ReadEventVisibility($appConfig, $this->groupManager, $this->userSession)
			)
		);
	}

	private function readableObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT_UUID);
		$object->setRegister('5');
		$object->setSchema('7');
		$object->setObject(['titel' => 'Nieuw', 'interneAantekening' => 'geheim-nu']);

		$this->readAccess->method('readableOrAmbiguous')->willReturn($object);
		$this->readAccess->method('schemaOf')->willReturn($this->schema);

		return $object;
	}

	private function updateEntry(): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid('e1');
		$entry->setObjectUuid(self::OBJECT_UUID);
		$entry->setAction('update');
		$entry->setUser('alice');
		$entry->setSession('sess-1');
		$entry->setRequest('req-1');
		$entry->setIpAddress('10.0.0.7');
		$entry->setChanged([
			'titel' => ['old' => 'Oud', 'new' => 'Nieuw'],
			'interneAantekening' => ['old' => 'klokkenluider-bsn', 'new' => 'geheim-nu'],
			'apiToken' => ['old' => null, 'new' => 'tok-123'],
			'configuratie' => [
				'old' => ['geheim' => 'pw-oud', 'kleur' => 'rood'],
				'new' => ['geheim' => 'pw-nieuw', 'kleur' => 'blauw'],
			],
			'@self' => [
				'old' => ['summary' => 'klokkenluider-bsn', 'relations' => ['interneAantekening' => 'x', 'titel' => 'y']],
				'new' => ['summary' => 'geheim-nu', 'relations' => ['interneAantekening.0' => 'x', 'titel' => 'y']],
			],
		]);

		return $entry;
	}

	public function testAReaderOfTheObjectGetsItsHistory(): void {
		$this->readableObject();
		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturnCallback(function (...$args) {
				$filters = ($args[2] ?? []);
				$this->assertSame(self::OBJECT_UUID, $filters['object_uuid'], 'entries are read by the uuid the read check resolved');
				return [$this->updateEntry()];
			});
		$this->logService->expects($this->never())->method('getLogs');

		$result = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(200, $result->getStatus());
		$data = $result->getData();
		$this->assertSame(1, $data['total']);
		$this->assertCount(1, $data['results']);
		$this->assertSame('update', $data['results'][0]['action']);
		$this->assertSame(['old' => 'Oud', 'new' => 'Nieuw'], $data['results'][0]['changed']['titel']);
		foreach (['session', 'request', 'ipAddress'] as $field) {
			$this->assertArrayNotHasKey($field, $data['results'][0]);
		}
	}

	public function testPropertiesHiddenFromTheReaderAreFilteredFromEveryEntry(): void {
		$this->readableObject();
		$this->auditTrailMapper->method('findAll')->willReturn([$this->updateEntry()]);

		$result = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(200, $result->getStatus());
		$changed = $result->getData()['results'][0]['changed'];
		$this->assertArrayNotHasKey('interneAantekening', $changed, 'a property behind a read rule leaks through the history');
		$this->assertArrayNotHasKey('apiToken', $changed, 'a writeOnly value leaks through the history');
		$this->assertSame(['kleur' => 'rood'], $changed['configuratie']['old'], 'a nested write-only path leaks through the history');
		$this->assertSame(['kleur' => 'blauw'], $changed['configuratie']['new']);
		$this->assertSame(['titel' => 'y'], $changed['@self']['old']['relations']);
		$this->assertSame(['titel' => 'y'], $changed['@self']['new']['relations']);
		$this->assertNull($changed['@self']['old']['summary'], 'the @self copy of a withheld property leaks');
		$this->assertNull($changed['@self']['new']['summary']);
		$this->assertStringNotContainsString('klokkenluider-bsn', (string)json_encode($result->getData()));
	}

	public function testACascadeEntryDoesNotNameTheObjectWhoseDeleteCausedIt(): void {
		$this->readableObject();
		$entry = new AuditTrail();
		$entry->setUuid('e2');
		$entry->setObjectUuid(self::OBJECT_UUID);
		$entry->setAction('delete');
		$entry->setChanged([
			'triggeredBy' => 'referential_integrity',
			'cascadeContext' => [
				'triggerObject' => 'f3c2b1a0-0000-4000-8000-00000000beef',
				'triggerSchema' => 'verborgen-schema',
				'action_type' => 'referential_integrity.cascade_delete',
				'property' => 'interneAantekening',
			],
		]);
		$this->auditTrailMapper->method('findAll')->willReturn([$entry]);

		$result = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$cascade = $result->getData()['results'][0]['changed']['cascadeContext'];
		$this->assertNull($cascade['triggerObject']);
		$this->assertNull($cascade['triggerSchema']);
		$this->assertNull($cascade['property'], 'a property the reader may not see is not named either');
		$this->assertSame('referential_integrity.cascade_delete', $cascade['action_type']);
		$this->assertStringNotContainsString('beef', (string)json_encode($result->getData()));
	}

	public function testAReaderAllowedThePropertySeesItInTheHistory(): void {
		$this->readableObject();
		$this->auditTrailMapper->method('findAll')->willReturn([$this->updateEntry()]);

		$result = $this->controllerFor('redacteur', false, ['redactie'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$changed = $result->getData()['results'][0]['changed'];
		$this->assertSame(['old' => 'klokkenluider-bsn', 'new' => 'geheim-nu'], $changed['interneAantekening']);
		$this->assertArrayNotHasKey('apiToken', $changed, 'writeOnly is withheld from every reader');
	}

	public function testASearchCannotFindAWithheldValue(): void {
		$this->readableObject();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['_search' => 'klokkenluider', 'changed' => '%x%', '_sort' => ['ip_address' => 'ASC']]);
		$this->auditTrailMapper->expects($this->once())
			->method('findAll')
			->willReturnCallback(function (...$args) {
				$this->assertArrayNotHasKey('changed', $args[2], 'a reader may not filter on the raw payload');
				$this->assertSame(['created' => 'DESC'], $args[3], 'a reader may not sort on a withheld column');
				$this->assertNull($args[4] ?? null, 'the search must not run against the raw payload in SQL');
				return [$this->updateEntry()];
			});

		$result = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(0, $result->getData()['total']);
		$this->assertSame([], $result->getData()['results']);
	}

	public function testAUserWhoMayNotReadTheObjectGets404AndNoEntryIsRead(): void {
		$this->readAccess->method('readableOrAmbiguous')->willReturn(null);
		$this->auditTrailMapper->expects($this->never())->method('findAll');
		$this->logService->expects($this->never())->method('getLogs');

		$result = $this->controllerFor('buitenstaander', false, ['users'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(404, $result->getStatus());
	}

	public function testAReaderGetsTheSame409ForAnAmbiguousSlug(): void {
		// #4037: a slug two objects of the register and schema hold is ambiguous for a
		// reader too, not "not found", and neither object's history is read.
		$this->readAccess->method('readableOrAmbiguous')->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$response = $this->controllerFor('alice', false, ['users'])->objects('dossiq', 'zaak', 'dubbel');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('ambiguous-identifier', $response->getData()['error']);
	}

	public function testTheDoelbindingRefusalOfTheObjectGetAppliesToItsHistory(): void {
		$this->readableObject();
		$this->purposeGuard->method('enforce')
			->willThrowException(new PurposeRefusedException(rule: 'purpose-required', reason: 'Name a purpose.'));
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$result = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(403, $result->getStatus());
		$this->assertSame('PURPOSE_REFUSED', $result->getData()['error']);
	}

	/**
	 * A read entry and a change on the object, newest first.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function readAndUpdate(): array {
		$read = new AuditTrail();
		$read->setUuid('r1');
		$read->setObjectUuid(self::OBJECT_UUID);
		$read->setAction('read');
		$read->setUser('fatma');
		$read->setChanged([]);

		return [$read, $this->updateEntry()];
	}

	public function testAReaderOutsideTheNamedGroupsDoesNotSeeWhoReadTheObject(): void {
		$this->readableObject();
		$this->readEventGroups = '["dossiq-sensitive"]';
		$this->auditTrailMapper->method('findAll')->willReturn($this->readAndUpdate());

		$data = $this->controllerFor('behandelaar', false, ['behandelaars'])->objects('dossiq', 'zaak', self::OBJECT_UUID)->getData();

		$this->assertSame(['update'], array_column($data['results'], 'action'), 'a read entry reaches a reader outside the named groups');
		$this->assertSame(1, $data['total'], 'the total counts a read entry the reader is not given');
		$this->assertFalse($data['readEventsVisible']);
	}

	public function testNoGroupNamedMeansNoNonAdminSeesReads(): void {
		$this->readableObject();
		$this->auditTrailMapper->method('findAll')->willReturn($this->readAndUpdate());

		$data = $this->controllerFor('behandelaar', false, ['behandelaars', 'dossiq-sensitive'])->objects('dossiq', 'zaak', self::OBJECT_UUID)->getData();

		$this->assertSame(['update'], array_column($data['results'], 'action'));
		$this->assertFalse($data['readEventsVisible']);
	}

	public function testAMemberOfANamedGroupSeesWhoReadTheObject(): void {
		$this->readableObject();
		$this->readEventGroups = '["dossiq-sensitive"]';
		$this->auditTrailMapper->method('findAll')->willReturn($this->readAndUpdate());

		$data = $this->controllerFor('pieter', false, ['dossiq-sensitive'])->objects('dossiq', 'zaak', self::OBJECT_UUID)->getData();

		$this->assertSame(['read', 'update'], array_column($data['results'], 'action'));
		$this->assertTrue($data['readEventsVisible']);
	}

	public function testAnAdministratorReadsTheHistoryAsBefore(): void {
		$entry = $this->updateEntry();
		$this->logService->expects($this->once())->method('getLogs')->willReturn([$entry]);
		$this->logService->method('count')->willReturn(1);
		$this->readAccess->expects($this->never())->method('readableOrAmbiguous');

		$result = $this->controllerFor('admin', true, ['admin'])->objects('dossiq', 'zaak', self::OBJECT_UUID);

		$this->assertSame(200, $result->getStatus());
		$this->assertSame([$entry], $result->getData()['results'], 'the admin path returns the entries unfiltered');
		$this->assertTrue($result->getData()['readEventsVisible'], 'an administrator always sees read entries');
	}

	public function testAnAnonymousCallerGets401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->readAccess->expects($this->never())->method('readableOrAmbiguous');

		$controller = new AuditTrailController(
			'openregister',
			$this->request,
			$this->logService,
			$this->auditTrailMapper,
			$this->createMock(AuditHashService::class),
			$this->userSession,
			$this->groupManager,
			$this->createMock(ReadableAuditTrailLister::class),
			$this->createMock(AuditTrailPageService::class),
			$this->createMock(ObjectHistoryForReaders::class)
		);

		$this->assertSame(401, $controller->objects('dossiq', 'zaak', self::OBJECT_UUID)->getStatus());
	}

	public function testOnlyThePerObjectHistoryIsOpenToANonAdminAtTheFrameworkLevel(): void {
		// Nextcloud's middleware blocks a non-admin before the body runs unless
		// the method carries @NoAdminRequired. A unit call to objects() would
		// pass without it, so the annotation is asserted directly.
		$reflection = new ReflectionClass(AuditTrailController::class);
		$openToNonAdmins = static function (string $method) use ($reflection): bool {
			$target = $reflection->getMethod($method);
			$annotated = preg_match('/^\s*\*\s*@NoAdminRequired\b/m', (string)$target->getDocComment()) === 1;
			return $annotated === true || $target->getAttributes(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class) !== [];
		};

		$this->assertTrue($openToNonAdmins('objects'), 'objects() is blocked for non-admins by the middleware');
		foreach (['index', 'show', 'statistics', 'export'] as $adminOnly) {
			$this->assertFalse($openToNonAdmins($adminOnly), $adminOnly.'() is an instance-wide listing and must stay admin-only');
		}
	}
}
