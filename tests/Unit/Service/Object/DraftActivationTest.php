<?php

/**
 * A draft's owner makes it definitive: fully valid, same id, one promotion, one event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\Object\DraftActivation;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUser;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Decision 216. The save in this test is judged by the REAL DraftStatusPolicy,
 * so a draft only leaves draft here when the activation asked for the one
 * promotion the save path allows. The validation is a real opis run against
 * the schema's own properties and `required` list.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftActivation
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Service\Object\DraftFinder
 * @uses \OCA\OpenRegister\Service\Form\FormPayloadFindings
 * @uses \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses \OCA\OpenRegister\Exception\DraftActivationRefusedException
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Exception\DraftAlreadyLeftException
 * @uses \OCA\OpenRegister\Event\ObjectActivatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftActivationTest extends TestCase {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * What each saveObject call received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * What each find call received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * Events dispatched.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * A throwable the next save raises instead of saving.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $refuseSave = null;

	/**
	 * Run inside the next save before the status policy judges it, as a parallel request would.
	 *
	 * @var callable|null
	 */
	private $beforeGate = null;

	/**
	 * Whether the save skips the status policy, as one that never reached it would.
	 *
	 * @var bool
	 */
	private bool $saveSkipsGate = false;

	private DraftActivation $activation;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		DraftStatusPolicy::resetPromotions();

		$this->register = new Register();
		$this->register->setId(10);
		$this->register->setSlug('service');

		// The shape of a real destination: required text, a date-time, an
		// enum, a property with a default and one a listener fills.
		$this->schema = new Schema();
		$this->schema->setId(1);
		$this->schema->setSlug('ticket');
		$this->schema->setRequired(['subject', 'channel', 'priority', 'number']);
		$this->schema->setProperties(
			[
				'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
				'channel' => ['type' => 'string', 'enum' => ['phone', 'email', 'counter']],
				'contactedAt' => ['type' => 'string', 'format' => 'date-time'],
				'priority' => ['type' => 'string', 'default' => 'normal'],
				'number' => ['type' => 'string', 'x-openregister' => ['serverSet' => true]],
			]
		);
		$this->schema->setHardValidation(false);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				$register = null,
				$schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'rbac' => $_rbac, 'register' => $register, 'schema' => $schema];
				if ($id === 'gone') {
					throw new RuntimeException('not found');
				}

				return ($this->stored[(string)$id] ?? null);
			}
		);
		$objects->method('saveObject')->willReturnCallback($this->realGateSave(...));

		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturnCallback($this->opisValidate(...));

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-12T16:00:00+02:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->activation = new DraftActivation(
			objects: $objects,
			validator: $validator,
			findings: new FormPayloadFindings(),
			events: $events,
			time: $time,
			l10n: $l10n
		);
	}//end setUp()

	protected function tearDown(): void {
		DraftStatusPolicy::resetPromotions();
	}//end tearDown()

	/**
	 * A real opis validation against the schema's own properties, honouring the excusals.
	 *
	 * @param array<string, mixed>   $object      The object.
	 * @param Schema|int|string|null $schema      The schema.
	 * @param object                 $schemaObject Unused.
	 * @param int                    $_depth      Unused.
	 * @param array<string, string>  $notSupplied Excused properties.
	 */
	private function opisValidate(array $object, Schema|int|string|null $schema = null, object $schemaObject = new \stdClass(), int $_depth = 0, array $notSupplied = []): ValidationResult {
		$this->assertInstanceOf(Schema::class, $schema);
		$properties = [];
		foreach ($schema->getProperties() as $name => $property) {
			$properties[$name] = array_diff_key($property, ['x-openregister' => true, 'default' => true]);
		}

		$definition = [
			'type' => 'object',
			'required' => array_values(array_diff($schema->getRequired(), array_keys($notSupplied))),
			'properties' => $properties,
		];
		$validator = new Validator();
		$validator->setMaxErrors(10);

		return $validator->validate(json_decode((string)json_encode((object)$object)), json_decode((string)json_encode($definition)));
	}//end opisValidate()

	/**
	 * The save, with the status decided by the real DraftStatusPolicy as SaveObject does.
	 *
	 * @param array<string, mixed>|ObjectEntity $object The object.
	 */
	private function realGateSave(
		array|ObjectEntity $object,
		?array $extend = [],
		$register = null,
		$schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
	): ObjectEntity {
		$this->saves[] = ['object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'user' => $currentUser, 'register' => $register, 'schema' => $schema];
		if ($this->refuseSave !== null) {
			throw $this->refuseSave;
		}

		$data = (array)$object;
		$self = ($data['@self'] ?? []);
		unset($data['@self']);

		$entity = $this->stored[(string)$uuid];
		if ($this->beforeGate !== null) {
			$hook = $this->beforeGate;
			$this->beforeGate = null;
			$hook();
		}

		// The real gate: throws unless exactly this object was allowed one promotion.
		if ($this->saveSkipsGate === false) {
			$entity->setStatus((new DraftStatusPolicy())->resolveStatus(entity: $entity, selfData: $self));
		}

		$entity->setObject($data + ['number' => 'T-2026-0001']);

		return $entity;
	}//end realGateSave()

	/**
	 * Store an object.
	 *
	 * @param array<string, mixed> $data The object's data.
	 */
	private function store(string $uuid, string $owner, ?string $status, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setId(count($this->stored) + 1);
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setStatus($status);
		$entity->setObject($data);
		$this->stored[$uuid] = $entity;

		return $entity;
	}//end store()

	/**
	 * A Nextcloud user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Run an activation and return its refusal.
	 *
	 * @param array<string, mixed> $changes Last values.
	 */
	private function refusalOf(string $draftId, DraftOwner $owner, array $changes = []): DraftActivationRefusedException {
		try {
			$this->activation->activate(register: $this->register, schema: $this->schema, draftId: $draftId, owner: $owner, changes: $changes);
		} catch (DraftActivationRefusedException $refused) {
			return $refused;
		}

		$this->fail('The activation was not refused.');
	}//end refusalOf()

	/**
	 * Spec scenario: the owner makes a complete draft definitive.
	 */
	public function testTheOwnerMakesACompleteDraftDefinitive(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['id' => 'd1', 'subject' => 'Bin not collected', 'channel' => 'phone']);
		$agent = $this->user(uid: 'agent');

		$saved = $this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::ofUser(user: $agent));

		// The same object, now active: the real policy let the status move.
		$this->assertSame('d1', $saved->getUuid());
		$this->assertSame('active', $saved->getStatus());
		$this->assertFalse($saved->isDraft());

		// One save, of the stored data, under the same id, asking for `active`.
		$this->assertCount(1, $this->saves);
		$this->assertSame('d1', $this->saves[0]['uuid']);
		$this->assertSame(['subject' => 'Bin not collected', 'channel' => 'phone', '@self' => ['status' => 'active']], $this->saves[0]['object']);
		$this->assertSame($this->register, $this->saves[0]['register']);
		$this->assertSame($this->schema, $this->saves[0]['schema']);

		// RBAC stays on for a user, and the save runs as that user.
		$this->assertTrue($this->saves[0]['rbac']);
		$this->assertTrue($this->saves[0]['multitenancy']);
		$this->assertSame($agent, $this->saves[0]['user']);

		// Leaving draft is the object's creation (decision 227): the created event once, then the receipt with its moment.
		$this->assertCount(2, $this->dispatched);
		$this->assertSame(\OCA\OpenRegister\Event\ObjectCreatedEvent::class, get_class($this->dispatched[0]));
		$this->assertSame($saved, $this->dispatched[0]->getObject());
		$this->assertInstanceOf(ObjectActivatedEvent::class, $this->dispatched[1]);
		$this->assertSame($saved, $this->dispatched[1]->getObject());
		$this->assertSame('2026-10-12T16:00:00+02:00', $this->dispatched[1]->getReceivedAt()->format(DATE_ATOM));
	}//end testTheOwnerMakesACompleteDraftDefinitive()

	/**
	 * A signed-in user is their own asserted owner; a controller hands the user in and never an owner.
	 */
	public function testActivatingAsAUserAssertsThatUser(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->store(uuid: 'd2', owner: 'agent', status: 'draft', data: ['subject' => 'B', 'channel' => 'phone']);

		$saved = $this->activation->activateAsUser(register: $this->register, schema: $this->schema, draftId: 'd1', user: $this->user(uid: 'agent'), changes: ['channel' => 'email']);
		$this->assertSame('active', $saved->getStatus());
		$this->assertSame('email', $this->saves[0]['object']['channel']);

		// Control: another user is refused through the same door.
		try {
			$this->activation->activateAsUser(register: $this->register, schema: $this->schema, draftId: 'd2', user: $this->user(uid: 'colleague'));
			$this->fail('A colleague made somebody else\'s draft definitive.');
		} catch (DraftActivationRefusedException $refused) {
			$this->assertSame(403, $refused->getStatus());
		}
	}//end testActivatingAsAUserAssertsThatUser()

	/**
	 * The allowance is used once: after the activation nothing else may leave draft on it.
	 */
	public function testTheAllowanceDoesNotOutliveTheActivation(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$other = $this->store(uuid: 'd2', owner: 'agent', status: 'draft', data: ['subject' => 'B', 'channel' => 'phone']);

		$this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		// Control: a plain save that asks for `active` is still refused, for another draft and for any later write.
		$this->expectException(ValidationException::class);
		(new DraftStatusPolicy())->resolveStatus(entity: $other, selfData: ['status' => 'active']);
	}//end testTheAllowanceDoesNotOutliveTheActivation()

	/**
	 * A refused save leaves no allowance behind either.
	 */
	public function testARefusedSaveLeavesNoAllowanceBehind(): void {
		$draft = $this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->refuseSave = new NotAuthorizedException(message: 'No update right.');

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		// The owner who may not write the schema is refused with 403: RBAC decided, not this class.
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame('not-the-owner', $refused->getReason());
		$this->assertSame([], $this->dispatched);
		$this->assertSame('draft', $draft->getStatus());

		$this->expectException(ValidationException::class);
		(new DraftStatusPolicy())->resolveStatus(entity: $draft, selfData: ['status' => 'active']);
	}//end testARefusedSaveLeavesNoAllowanceBehind()

	/**
	 * Spec scenario: an incomplete draft is refused with one finding per property, and stays a draft.
	 */
	public function testAnIncompleteDraftIsRefusedPerField(): void {
		$draft = $this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['contactedAt' => '2026-10-12T15:00:00+02:00']);

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('invalid', $refused->getReason());
		$byProperty = [];
		foreach ($refused->getFindings() as $finding) {
			$byProperty[$finding['property']] = $finding['code'];
		}

		// Both required properties the draft misses, each as its own finding.
		$this->assertSame('required', $byProperty['subject'] ?? null);
		$this->assertSame('required', $byProperty['channel'] ?? null);
		// A property with a default and one a listener fills are not the owner's to supply.
		$this->assertArrayNotHasKey('priority', $byProperty);
		$this->assertArrayNotHasKey('number', $byProperty);

		// Nothing was saved, nothing announced, and the draft is still a draft.
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->dispatched);
		$this->assertSame('draft', $draft->getStatus());
	}//end testAnIncompleteDraftIsRefusedPerField()

	/**
	 * A stored draft holds null for every property nobody filled in: a table has a column for each.
	 * Found live (12 October): the answer named `channel` with code `type` and the sentence
	 * "The data (null) must match the type: string", where the caller needs `required`.
	 */
	public function testAStoredNullIsAPropertyNobodyFilledIn(): void {
		$this->store(
			uuid: 'd1',
			owner: 'agent',
			status: 'draft',
			data: ['subject' => null, 'channel' => null, 'priority' => null, 'number' => null, 'contactedAt' => '2026-10-12T15:00:00+02:00']
		);

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$byProperty = [];
		foreach ($refused->getFindings() as $finding) {
			$byProperty[$finding['property']] = $finding['code'];
		}

		$this->assertSame(['channel' => 'required', 'subject' => 'required'], $this->sorted(map: $byProperty));

		// Control: with the two values sent, the same stored nulls stand in nobody's way.
		$saved = $this->activation->activate(
			register: $this->register,
			schema: $this->schema,
			draftId: 'd1',
			owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')),
			changes: ['subject' => 'Bin not collected', 'channel' => 'phone']
		);
		$this->assertSame('active', $saved->getStatus());
		$this->assertArrayNotHasKey('priority', $this->saves[0]['object']);
	}//end testAStoredNullIsAPropertyNobodyFilledIn()

	/**
	 * A map sorted by key, so an assertion reads the same whatever order the validator found things in.
	 * @param array<string, string> $map The map.
	 * @return array<string, string> The sorted map.
	 */
	private function sorted(array $map): array {
		ksort($map);

		return $map;
	}//end sorted()

	/**
	 * The last changes are laid over the stored draft before it is judged; `@self` and `id` in them are ignored.
	 */
	public function testLastChangesCompleteTheDraft(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'Old subject']);

		$saved = $this->activation->activate(
			register: $this->register,
			schema: $this->schema,
			draftId: 'd1',
			owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')),
			changes: ['subject' => 'New subject', 'channel' => 'email', '@self' => ['owner' => 'somebody-else', 'status' => 'draft'], 'id' => 'another-id']
		);

		$this->assertSame('active', $saved->getStatus());
		$this->assertSame(['subject' => 'New subject', 'channel' => 'email', '@self' => ['status' => 'active']], $this->saves[0]['object']);
		$this->assertSame('d1', $this->saves[0]['uuid']);
	}//end testLastChangesCompleteTheDraft()

	/**
	 * A draft is never type-invalid, but a format can go stale: a wrong format is refused by name.
	 */
	public function testAWrongFormatIsRefusedByName(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone', 'contactedAt' => 'yesterday']);

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('contactedAt', $refused->getFindings()[0]['property']);
		$this->assertSame('format', $refused->getFindings()[0]['code']);
		$this->assertSame([], $this->saves);
	}//end testAWrongFormatIsRefusedByName()

	/**
	 * A value outside the enum in the last changes is refused, naming the property.
	 */
	public function testAnInvalidLastChangeIsRefused(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')), changes: ['channel' => 'pigeon']);

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('channel', $refused->getFindings()[0]['property']);
		$this->assertSame('enum', $refused->getFindings()[0]['code']);
		$this->assertSame([], $this->saves);
	}//end testAnInvalidLastChangeIsRefused()

	/**
	 * Spec scenario: somebody else, an administrator included, is refused and the draft stays as it was.
	 */
	public function testSomebodyElseIsRefused(): void {
		$draft = $this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'admin')));

		$this->assertSame(403, $refused->getStatus());
		$this->assertSame('not-the-owner', $refused->getReason());
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->dispatched);
		$this->assertSame('draft', $draft->getStatus());
	}//end testSomebodyElseIsRefused()

	/**
	 * An object that is not a draft answers 409; an unknown id, an empty id and a failing read answer 404.
	 */
	public function testNotADraftAndUnknownIdsAreRefused(): void {
		$this->store(uuid: 'a1', owner: 'agent', status: 'active', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->store(uuid: 'a2', owner: 'agent', status: null, data: ['subject' => 'A', 'channel' => 'phone']);
		$owner = DraftOwner::ofUser(user: $this->user(uid: 'agent'));

		$this->assertSame(409, $this->refusalOf(draftId: 'a1', owner: $owner)->getStatus());
		$this->assertSame('not-a-draft', $this->refusalOf(draftId: 'a2', owner: $owner)->getReason());
		$this->assertSame(404, $this->refusalOf(draftId: 'nope', owner: $owner)->getStatus());
		$this->assertSame(404, $this->refusalOf(draftId: 'gone', owner: $owner)->getStatus());
		$this->assertSame('not-found', $this->refusalOf(draftId: '', owner: $owner)->getReason());
		$this->assertSame([], $this->saves);
	}//end testNotADraftAndUnknownIdsAreRefused()

	/**
	 * Decision 195: a scope-value owner activates its own draft; there is no user for RBAC to judge.
	 */
	public function testAScopeValueOwnerActivatesItsOwnDraft(): void {
		$this->store(uuid: 'p1', owner: 'portal:subject-1', status: 'draft', data: ['subject' => 'A', 'channel' => 'counter']);

		$saved = $this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'p1', owner: DraftOwner::portalSubject(subjectRef: 'subject-1'));

		$this->assertSame('active', $saved->getStatus());
		$this->assertFalse($this->saves[0]['rbac']);
		$this->assertNull($this->saves[0]['user']);

		// Control: another subject of the same scope is refused.
		$this->store(uuid: 'p2', owner: 'portal:subject-2', status: 'draft', data: ['subject' => 'B', 'channel' => 'counter']);
		$this->assertSame(403, $this->refusalOf(draftId: 'p2', owner: DraftOwner::portalSubject(subjectRef: 'subject-1'))->getStatus());
		$this->assertCount(1, $this->saves);
	}//end testAScopeValueOwnerActivatesItsOwnDraft()

	/**
	 * A save the schema refuses after the first check (a hook, uniqueness) answers 422 with findings.
	 */
	public function testASaveTimeRefusalAnswers422(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->refuseSave = new ValidationException(message: 'The subject is taken.');

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('The subject is taken.', $refused->getFindings()[0]['message']);
		$this->assertSame([], $this->dispatched);
	}//end testASaveTimeRefusalAnswers422()

	/**
	 * A failure that is nobody's refusal is not dressed up as one.
	 */
	public function testAnOutageIsRethrown(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->refuseSave = new RuntimeException('database gone');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('database gone');
		$this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));
	}//end testAnOutageIsRethrown()

	/**
	 * The read that finds the draft does not go through RBAC; the owner comparison is the check.
	 */
	public function testTheDraftIsReadInItsOwnRegisterAndSchema(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);

		$this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(['id' => 'd1', 'rbac' => false, 'register' => $this->register, 'schema' => $this->schema], $this->finds[0]);
	}//end testTheDraftIsReadInItsOwnRegisterAndSchema()

	/**
	 * Two activations of one draft: the one that finds it already sent announces nothing.
	 *
	 * A parallel request makes the draft definitive between this one's read and
	 * its save. The real policy refuses the save (an allowance for an object
	 * that is no longer a draft): 409, nothing written, no second creation event.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function testALostRaceToActivateAnnouncesNothing(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->beforeGate = function (): void {
			$this->stored['d1']->setStatus('active');
		};

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(DraftActivationRefusedException::NOT_A_DRAFT, $refused->getReason());
		$this->assertSame([], $this->dispatched);
	}//end testALostRaceToActivateAnnouncesNothing()

	/**
	 * A save that did not use the promotion allowance does not announce the creation either.
	 *
	 * The save returns an object that was already `active` without the policy
	 * consuming the allowance; goes red without the check after the write.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function testASaveThatDidNotUseThePromotionAnnouncesNothing(): void {
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);
		$this->saveSkipsGate = true;
		$this->beforeGate = function (): void {
			$this->stored['d1']->setStatus('active');
		};

		$refused = $this->refusalOf(draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame(DraftActivationRefusedException::NOT_A_DRAFT, $refused->getReason());
		$this->assertSame([], $this->dispatched);
	}//end testASaveThatDidNotUseThePromotionAnnouncesNothing()

	/**
	 * A required property that carries a generated identifier does not keep a draft from leaving draft.
	 *
	 * The listener issues the number on the save that leaves draft, so the
	 * draft cannot hold it; excused like `serverSet`, or such a draft could
	 * never be sent. Goes red without the `x-openregister-generated` excusal.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function testAGeneratedRequiredIdentifierDoesNotBlockActivation(): void {
		$this->schema->setRequired(['subject', 'channel', 'caseNumber']);
		$this->schema->setProperties(
			$this->schema->getProperties() + [
				'caseNumber' => ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{year}-{seq:4}']],
			]
		);
		$this->store(uuid: 'd1', owner: 'agent', status: 'draft', data: ['subject' => 'A', 'channel' => 'phone']);

		$saved = $this->activation->activate(register: $this->register, schema: $this->schema, draftId: 'd1', owner: DraftOwner::ofUser(user: $this->user(uid: 'agent')));

		$this->assertSame('active', $saved->getStatus());
	}//end testAGeneratedRequiredIdentifierDoesNotBlockActivation()
}//end class
