<?php

/**
 * Golden test: the access flags a form submit hands to every read and write, and what it refuses.
 *
 * Pins, for each way into the submit path, the RBAC, multitenancy, owner and
 * user arguments of every save, delete and find, and the refusals that stop a
 * write before it is made. A refactor of FormSubmitService must leave every
 * expectation here untouched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormFileStorage;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\Form\FormSubmitDestinations;
use OCA\OpenRegister\Service\Form\FormSubmitKeeping;
use OCA\OpenRegister\Service\Form\FormSubmitPlatform;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitService;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryLockingProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * The submit path's allowed and refused outcomes, with the flags of every call.
 */
class FormSubmitServiceAccessGoldenTest extends TestCase {

	/**
	 * Every call the submit made on the object store, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every register and schema lookup, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lookups = [];

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * A save to refuse, by schema slug.
	 *
	 * @var array<string, Throwable>
	 */
	private array $refuse = [];

	private FormSubmitService $service;

	private IUser $user;

	protected function setUp(): void {
		DraftStatusPolicy::resetPromotions();
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true): Register {
				$this->lookups[] = ['register', (string)$id, $_rbac, $_multitenancy];
				if ($id === 'nowhere') {
					throw new RuntimeException('no register');
				}

				$register = new Register();
				$register->setId(1);
				$register->setSlug((string)$id);

				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				$this->lookups[] = ['schema', (string)$id, $_rbac, $_multitenancy];
				$schema = new Schema();
				$schema->setId(crc32((string)$id));
				$schema->setSlug((string)$id);
				$schema->setProperties(['title' => ['type' => 'string']]);

				return $schema;
			}
		);
		$validator = $this->createMock(ValidateObjectInterface::class);
		$validator->method('validateObject')->willReturn(new ValidationResult(null));

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('saveObject')->willReturnCallback($this->save(...));
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true, bool $_audit = true): ?ObjectEntity {
				$this->calls[] = ['find', (string)$id, $_rbac, $_multitenancy, $_audit];

				return ($this->stored[(string)$id] ?? null);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, ?IUser $currentUser = null, bool $permanent = false): bool {
				$this->calls[] = ['delete', $uuid, $_rbac, $_multitenancy, $permanent];
				unset($this->stored[$uuid]);

				return true;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_760_000_000);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-12T09:15:00+02:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$temp = $this->createMock(ITempManager::class);
		$storage = new FormFileStorage(appData: new MemoryAppData(), time: $time);

		$this->service = new FormSubmitService(
			destinations: new FormSubmitDestinations(registers: $registers, schemas: $schemas, validator: $validator, objects: $objects),
			keeping: new FormSubmitKeeping(
				findings: new FormPayloadFindings(),
				keys: new FormIdempotencyStore(storage: $storage, locks: new MemoryLockingProvider(), l10n: $l10n),
				uploads: new FormUploadStore(storage: $storage, temp: $temp, rules: new FilePropertyRules(), l10n: $l10n)
			),
			platform: new FormSubmitPlatform(
				l10n: $l10n,
				logger: $this->createMock(LoggerInterface::class),
				events: $this->createMock(IEventDispatcher::class),
				time: $time
			)
		);
	}//end setUp()

	protected function tearDown(): void {
		DraftStatusPolicy::resetPromotions();
	}//end tearDown()

	/**
	 * The fake save: records the access arguments, applies the real status policy, stores the object.
	 */
	private function save(
		array|ObjectEntity $object,
		ObjectSaveOptions $options = new ObjectSaveOptions(),
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ObjectEntity {
		$schema = $options->target->schema;
		$uuid = $options->uuid;
		$currentUser = $options->actor->currentUser;
		$_unowned = $options->actor->unowned;
		$_owner = $options->actor->owner;
		$data = (array)$object;
		$status = ($data['@self']['status'] ?? null);
		$this->calls[] = ['save', $schema->getSlug(), $uuid, $_rbac, $_multitenancy, $currentUser?->getUID(), $_unowned, $_owner, $status];
		if (isset($this->refuse[$schema->getSlug()]) === true) {
			throw $this->refuse[$schema->getSlug()];
		}

		$stored = ($this->stored[(string)$uuid] ?? null);
		if ($stored !== null && $status !== null) {
			$stored->setId(1);
			(new DraftStatusPolicy())->resolveStatus(entity: $stored, selfData: ['status' => $status]);
		}

		unset($data['@self']);
		$entity = new ObjectEntity();
		$entity->setUuid($uuid ?? ('uuid-' . $schema->getSlug() . '-' . count($this->calls)));
		$entity->setObject($data);
		$entity->setStatus($status);
		$entity->setOwner($stored?->getOwner() ?? $_owner ?? $currentUser?->getUID());
		$entity->setCreated(new DateTime('2026-10-10T14:03:11+02:00'));
		$this->stored[$entity->getUuid()] = $entity;

		return $entity;
	}//end save()

	/**
	 * A stored draft owned by the given owner value.
	 */
	private function storeDraft(string $uuid, string $owner): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['title' => 'kept']);
		$entity->setStatus(ObjectEntity::STATUS_DRAFT);
		$entity->setOwner($owner);
		$this->stored[$uuid] = $entity;
	}//end storeDraft()

	/**
	 * The calls of one kind.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsOf(string $kind): array {
		return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $kind));
	}//end callsOf()

	/**
	 * The status of the refusal a call throws, or null when it does not throw one.
	 */
	private function refusalStatus(callable $call): ?int {
		try {
			$call();
		} catch (FormSubmitRefusedException $exception) {
			return $exception->getStatus();
		}

		return null;
	}//end refusalStatus()

	public function testAnAnonymousSubmitSavesUnderRbacUnowned(): void {
		$this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: ['title' => 'a'], submitter: new FormSubmitter()));

		$this->assertSame([['save', 'case', null, true, true, null, true, null, null]], $this->callsOf('save'));
		$this->assertSame([['find', 'uuid-case-1', false, false, false]], $this->callsOf('find'));
		$this->assertEqualsCanonicalizing([['register', 'reg', false, false], ['schema', 'case', true, false]], $this->lookups);
	}//end testAnAnonymousSubmitSavesUnderRbacUnowned()

	public function testASignedInSubmitSavesUnderRbacAsTheUser(): void {
		$this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: ['title' => 'a'], submitter: new FormSubmitter(subject: $this->user)));

		$this->assertSame([['save', 'case', null, true, true, 'alice', false, null, null]], $this->callsOf('save'));
	}//end testASignedInSubmitSavesUnderRbacAsTheUser()

	public function testAUsersNewDraftSavesUnderRbac(): void {
		$this->service->saveDraft(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: $this->user)));

		$this->assertSame([['save', 'case', null, true, true, 'alice', false, null, 'draft']], $this->callsOf('save'));
	}//end testAUsersNewDraftSavesUnderRbac()

	public function testAScopedNewDraftSavesWithoutRbacStampingTheOwner(): void {
		$this->service->saveDraft(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: null, owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'))));

		$this->assertSame([['save', 'case', null, false, true, null, false, 'portal:bsn-1', 'draft']], $this->callsOf('save'));
	}//end testAScopedNewDraftSavesWithoutRbacStampingTheOwner()

	public function testAScopedDraftUpdateIsCheckedThenSavedWithoutRbac(): void {
		$this->storeDraft(uuid: 'd-1', owner: 'portal:bsn-1');
		$this->service->saveDraft(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: ['title' => 'b'], submitter: new FormSubmitter(subject: null, draftId: 'd-1', owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'))));

		$this->assertSame(['find', 'd-1', false, false, false], $this->calls[0]);
		$this->assertSame([['save', 'case', 'd-1', false, true, null, false, null, 'draft']], $this->callsOf('save'));
	}//end testAScopedDraftUpdateIsCheckedThenSavedWithoutRbac()

	public function testAScopedPromotionSavesWithoutRbacAsActive(): void {
		$this->storeDraft(uuid: 'd-1', owner: 'portal:bsn-1');
		$this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(draftId: 'd-1', owner: DraftOwner::portalSubject(subjectRef: 'bsn-1'))));

		$this->assertSame(['find', 'd-1', false, false, false], $this->calls[0]);
		$this->assertSame([['save', 'case', 'd-1', false, true, null, false, null, 'active']], $this->callsOf('save'));
	}//end testAScopedPromotionSavesWithoutRbacAsActive()

	public function testAUsersPromotionSavesUnderRbacAsTheUser(): void {
		$this->storeDraft(uuid: 'd-1', owner: 'alice');
		$this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: $this->user, draftId: 'd-1')));

		$this->assertSame([['save', 'case', 'd-1', true, true, 'alice', false, null, 'active']], $this->callsOf('save'));
	}//end testAUsersPromotionSavesUnderRbacAsTheUser()

	public function testAnAnonymousDraftIsRefusedBeforeAnyWrite(): void {
		$status = $this->refusalStatus(fn () => $this->service->saveDraft(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: null))));

		$this->assertSame(401, $status);
		$this->assertSame([], $this->calls);
	}//end testAnAnonymousDraftIsRefusedBeforeAnyWrite()

	public function testSomebodyElsesDraftIsRefusedBeforeAnyWrite(): void {
		$this->storeDraft(uuid: 'd-1', owner: 'portal:bsn-2');
		$owner = DraftOwner::portalSubject(subjectRef: 'bsn-1');

		$this->assertSame(404, $this->refusalStatus(fn () => $this->service->saveDraft(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: null, draftId: 'd-1', owner: $owner)))));
		$this->assertSame(404, $this->refusalStatus(fn () => $this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(draftId: 'd-1', owner: $owner)))));
		$this->assertSame(404, $this->refusalStatus(fn () => $this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter(subject: $this->user, draftId: 'd-1')))));
		$this->assertSame(404, $this->refusalStatus(fn () => $this->service->readDraft(destination: ['register' => 'reg', 'schema' => 'case'], submitter: new FormSubmitter(subject: $this->user, draftId: 'd-1'))));
		$this->assertFalse($this->service->isOwnerOfDraft(destination: ['register' => 'reg', 'schema' => 'case'], submitter: new FormSubmitter(subject: null, draftId: 'd-1', owner: $owner)));
		$this->assertTrue($this->service->isOwnerOfDraft(destination: ['register' => 'reg', 'schema' => 'case'], submitter: new FormSubmitter(subject: null, draftId: 'd-1', owner: DraftOwner::portalSubject(subjectRef: 'bsn-2'))));
		$this->assertSame([], $this->callsOf('save'));
	}//end testSomebodyElsesDraftIsRefusedBeforeAnyWrite()

	public function testARefusedPermissionIs403AndCompensatesWithoutRbacPermanently(): void {
		$this->refuse['contact'] = new NotAuthorizedException(message: 'no');
		$status = $this->refusalStatus(
			fn () => $this->service->submit(submission: new FormSubmission(writes: [['register' => 'reg', 'schema' => 'case', 'as' => 'one'], ['register' => 'reg', 'schema' => 'contact', 'as' => 'two']], payload: ['title' => 'a'], submitter: new FormSubmitter()))
		);

		$this->assertSame(403, $status);
		$this->assertSame([['delete', 'uuid-case-1', false, false, true]], $this->callsOf('delete'));
		$this->assertSame([], $this->callsOf('find'));
	}//end testARefusedPermissionIs403AndCompensatesWithoutRbacPermanently()

	public function testAnUnknownDestinationIsRefusedBeforeAnyWrite(): void {
		$this->assertSame(404, $this->refusalStatus(fn () => $this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'nowhere', 'schema' => 'case'], ['mapping' => null])], payload: [], submitter: new FormSubmitter()))));
		$this->assertSame([], $this->calls);
		$this->assertNull($this->service->findDraft(destination: ['register' => 'nowhere', 'schema' => 'case'], submitter: new FormSubmitter(subject: $this->user)));
		$this->assertNull($this->service->findDraft(destination: ['register' => 'reg', 'schema' => 'case'], submitter: new FormSubmitter(subject: null)));
	}//end testAnUnknownDestinationIsRefusedBeforeAnyWrite()

	public function testAPayloadNeverNamesTheObjectItWrites(): void {
		$this->service->submit(submission: new FormSubmission(writes: [array_merge(['register' => 'reg', 'schema' => 'case'], ['mapping' => ['fixed' => ['id' => 'victim']]])], payload: ['id' => 'victim', 'uuid' => 'victim'], submitter: new FormSubmitter()));

		$this->assertNull($this->callsOf('save')[0][2]);
		$this->assertArrayNotHasKey('victim', $this->stored);
	}//end testAPayloadNeverNamesTheObjectItWrites()
}//end class
