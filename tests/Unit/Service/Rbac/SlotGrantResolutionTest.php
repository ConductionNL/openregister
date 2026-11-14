<?php

/**
 * A grant to a slot, resolved through the one evaluator (REQ-GSR-001).
 *
 * The object's own block names a party role instead of a principal, and the
 * permission handler admits whoever occupies that role on the object at the
 * moment the question is asked. Every case here goes through
 * `PermissionHandler::hasPermission()` with a real `PartyRoleSlotResolver`
 * over a link table the case controls, so "the right follows the
 * behandelaar" is measured where the verdict is taken and not in a helper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver;
use OCA\OpenRegister\Service\Rbac\ProvenanceResolver;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The slot grant end to end through the permission handler.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @covers \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @covers \OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver
 * @covers \OCA\OpenRegister\Service\Rbac\ProvenanceResolver
 */
class SlotGrantResolutionTest extends TestCase {

	/**
	 * The role every case grants through.
	 *
	 * @var string
	 */
	private const ROLE = 'behandelaar';

	/**
	 * The link rows per object uuid, as the fake link table answers them.
	 *
	 * @var array<string, array<int, ContactLink>>
	 */
	private array $links = [];

	/**
	 * When set, the link table throws this instead of answering.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $linkFailure = null;

	/**
	 * Hands out a distinct schema id per schema, so the verdict memo never answers for another case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 700;

	/**
	 * Hands out a distinct object uuid per object.
	 *
	 * @var integer
	 */
	private int $objectCounter = 0;

	/**
	 * Reset the link table before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->links = [];
		$this->linkFailure = null;
	}//end setUp()

	/**
	 * A handler answering for one caller, with the slot resolver over the fake link table.
	 *
	 * @param string   $userId    The caller.
	 * @param string[] $groups    The caller's groups.
	 * @param bool     $withSlots Whether the handler is handed a slot resolver at all.
	 *
	 * @return PermissionHandler The handler, deny enforcing.
	 */
	private function handlerFor(string $userId, array $groups = [], bool $withSlots = true): PermissionHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$logger = new NullLogger();
		$slots = null;
		if ($withSlots === true) {
			$slots = $this->slotResolver();
		}

		return PermissionHandlerFactory::create(
			userSession: $userSession,
			userManager: $userManager,
			groupManager: $groupManager,
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->createMock(ConditionMatcher::class),
			appConfig: $appConfig,
			logger: $logger,
			container: $this->container(),
			objectScopeResolver: new ObjectScopeResolver(),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode($appConfig, $logger),
			partyRoleSlots: $slots
		);
	}//end handlerFor()

	/**
	 * The resolver under test, over the fake link table.
	 *
	 * @return PartyRoleSlotResolver The resolver.
	 */
	private function slotResolver(): PartyRoleSlotResolver {
		$mapper = $this->createMock(ContactLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willReturnCallback(
			function (string $objectUuid): array {
				if ($this->linkFailure !== null) {
					throw $this->linkFailure;
				}

				return ($this->links[$objectUuid] ?? []);
			}
		);

		return new PartyRoleSlotResolver(links: $mapper, logger: new NullLogger());
	}//end slotResolver()

	/**
	 * A container that knows a register and nothing else; any other lookup throws.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end container()

	/**
	 * A schema whose own rules let staff read and nobody else do anything.
	 *
	 * @param array|null $authorization The schema block; the default lets `medewerkers` read.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?array $authorization = ['read' => ['medewerkers']]): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A case carrying its own block, owned by somebody who is never the caller.
	 *
	 * @param array|null $authorization The object's own block.
	 *
	 * @return ObjectEntity The case.
	 */
	private function caseWith(?array $authorization): ObjectEntity {
		$this->objectCounter++;

		$object = new ObjectEntity();
		$object->setUuid(sprintf('5107a000-0000-4000-8000-%012d', $this->objectCounter));
		$object->setObject(['title' => 'een zaak']);
		$object->setOwner('eigenaar');
		$object->setAuthorization($authorization);

		return $object;
	}//end caseWith()

	/**
	 * Put a user in a role on an object.
	 *
	 * @param ObjectEntity  $object     The object.
	 * @param string        $userId     The user.
	 * @param string        $role       The role.
	 * @param DateTime|null $validUntil The last day, null for open.
	 * @param DateTime|null $validFrom  The first day, null for open.
	 *
	 * @return void
	 */
	private function occupy(ObjectEntity $object, string $userId, string $role = self::ROLE, ?DateTime $validUntil = null, ?DateTime $validFrom = null): void {
		$link = new ContactLink();
		$link->setObjectUuid((string)$object->getUuid());
		$link->setContactUid(ContactLink::USER_UID_PREFIX . $userId);
		$link->setUserId($userId);
		$link->setRole($role);
		$link->setValidFrom($validFrom);
		$link->setValidUntil($validUntil);

		$this->links[(string)$object->getUuid()][] = $link;
	}//end occupy()

	/**
	 * Empty every role on an object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 */
	private function vacate(ObjectEntity $object): void {
		$this->links[(string)$object->getUuid()] = [];
	}//end vacate()

	/**
	 * The object's block granting `update` to the slot.
	 *
	 * @return array The block.
	 */
	private static function slotBlock(): array {
		return ['update' => [['partyRole' => self::ROLE]]];
	}//end slotBlock()

	/**
	 * Whether a user may do one action on one object, asked fresh.
	 *
	 * @param string       $userId The caller.
	 * @param ObjectEntity $object The object.
	 * @param string       $action The action.
	 * @param string[]     $groups The caller's groups.
	 *
	 * @return bool The verdict.
	 */
	private function may(string $userId, ObjectEntity $object, string $action = 'update', array $groups = []): bool {
		return $this->handlerFor(userId: $userId, groups: $groups)->hasPermission(
			schema: $this->schema(),
			action: $action,
			userId: $userId,
			objectOwner: $object->getOwner(),
			object: $object
		);
	}//end may()

	/**
	 * The occupant holds the slot's verb; somebody else does not.
	 *
	 * @return void
	 */
	public function testTheSlotGrantResolvesToTheCurrentOccupant(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');

		$this->assertTrue($this->may(userId: 'ana', object: $case));
		$this->assertFalse($this->may(userId: 'bob', object: $case));
	}//end testTheSlotGrantResolvesToTheCurrentOccupant()

	/**
	 * The slot carries only the verbs written for it.
	 *
	 * @return void
	 */
	public function testTheSlotCarriesOnlyItsOwnVerbs(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');

		$this->assertFalse($this->may(userId: 'ana', object: $case, action: 'delete'));
	}//end testTheSlotCarriesOnlyItsOwnVerbs()

	/**
	 * An empty slot grants nothing to anybody.
	 *
	 * @return void
	 */
	public function testAnEmptySlotGrantsNothing(): void {
		$case = $this->caseWith(self::slotBlock());

		foreach (['ana', 'bob', 'eigenaar-niet'] as $userId) {
			$this->assertFalse($this->may(userId: $userId, object: $case), $userId . ' holds an empty slot');
		}
	}//end testAnEmptySlotGrantsNothing()

	/**
	 * Replacing the occupant moves the access, and the grant is not touched.
	 *
	 * @return void
	 */
	public function testReplacingTheOccupantMovesTheAccessWithNoGrantEdited(): void {
		$case = $this->caseWith(self::slotBlock());
		$before = $case->getAuthorization();

		$this->occupy(object: $case, userId: 'ana');
		$this->assertTrue($this->may(userId: 'ana', object: $case));
		$this->assertFalse($this->may(userId: 'bob', object: $case));

		$this->vacate(object: $case);
		$this->occupy(object: $case, userId: 'bob');

		$this->assertTrue($this->may(userId: 'bob', object: $case));
		$this->assertFalse($this->may(userId: 'ana', object: $case));
		$this->assertSame($before, $case->getAuthorization(), 'the grant was edited');
	}//end testReplacingTheOccupantMovesTheAccessWithNoGrantEdited()

	/**
	 * The same handler, asked again after the occupant changed, answers for the new occupant.
	 *
	 * An object carrying its own block is never served from the verdict memo,
	 * so a reassignment inside one request is seen by the next question.
	 *
	 * @return void
	 */
	public function testOneHandlerSeesTheOccupantChangeWithinARequest(): void {
		$case = $this->caseWith(self::slotBlock());
		$handler = $this->handlerFor(userId: 'ana');
		$schema = $this->schema();

		$this->occupy(object: $case, userId: 'ana');
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'update', userId: 'ana', objectOwner: 'eigenaar', object: $case));

		$this->vacate(object: $case);
		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'update', userId: 'ana', objectOwner: 'eigenaar', object: $case));
	}//end testOneHandlerSeesTheOccupantChangeWithinARequest()

	/**
	 * An occupant whose period has ended, or not yet begun, holds nothing.
	 *
	 * @return void
	 */
	public function testAnOccupantOutsideTheirPeriodHoldsNothing(): void {
		$ended = $this->caseWith(self::slotBlock());
		$this->occupy(object: $ended, userId: 'ana', validUntil: new DateTime('-2 days'));
		$this->assertFalse($this->may(userId: 'ana', object: $ended));

		$future = $this->caseWith(self::slotBlock());
		$this->occupy(object: $future, userId: 'ana', validFrom: new DateTime('+2 days'));
		$this->assertFalse($this->may(userId: 'ana', object: $future));

		$running = $this->caseWith(self::slotBlock());
		$this->occupy(object: $running, userId: 'ana', validUntil: new DateTime('+2 days'), validFrom: new DateTime('-2 days'));
		$this->assertTrue($this->may(userId: 'ana', object: $running));
	}//end testAnOccupantOutsideTheirPeriodHoldsNothing()

	/**
	 * Somebody holding a different role on the object does not hold this slot.
	 *
	 * @return void
	 */
	public function testAnotherRoleIsNotTheSlot(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana', role: 'adviseur');
		$this->occupy(object: $case, userId: 'ana', role: 'Behandelaar');

		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testAnotherRoleIsNotTheSlot()

	/**
	 * A party without an account, and a contact, hold the role but never the access.
	 *
	 * @return void
	 */
	public function testAPartyOrContactLinkIsNoOccupantForAccess(): void {
		$case = $this->caseWith(self::slotBlock());

		$party = new ContactLink();
		$party->setObjectUuid((string)$case->getUuid());
		$party->setContactUid(ContactLink::partyUid('ana'));
		$party->setPartyUuid('ana');
		$party->setRole(self::ROLE);

		$contact = new ContactLink();
		$contact->setObjectUuid((string)$case->getUuid());
		$contact->setContactUid('ana');
		$contact->setRole(self::ROLE);

		$this->links[(string)$case->getUuid()] = [$party, $contact];

		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testAPartyOrContactLinkIsNoOccupantForAccess()

	/**
	 * A malformed role value names no slot, even when somebody holds a role spelled like it.
	 *
	 * @return void
	 */
	public function testAMalformedRoleValueGrantsNothing(): void {
		foreach ([123, '', '   ', null, ['behandelaar'], true] as $malformed) {
			$case = $this->caseWith(['update' => [['partyRole' => $malformed]]]);
			$this->occupy(object: $case, userId: 'ana', role: '123');
			$this->occupy(object: $case, userId: 'ana', role: '1');

			$this->assertFalse($this->may(userId: 'ana', object: $case), 'malformed role ' . var_export($malformed, true) . ' granted');
		}

		// The string spelling is not a slot either: it reads as a group id, as every bare string always has.
		$case = $this->caseWith(['update' => ['partyRole:' . self::ROLE]]);
		$this->occupy(object: $case, userId: 'ana');
		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testAMalformedRoleValueGrantsNothing()

	/**
	 * A link table that cannot be read leaves the slot held by nobody.
	 *
	 * @return void
	 */
	public function testALookupFailureGrantsNothing(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');
		$this->linkFailure = new RuntimeException('database gone');

		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testALookupFailureGrantsNothing()

	/**
	 * With no slot resolver anywhere, a slot grants nothing.
	 *
	 * @return void
	 */
	public function testNoResolverGrantsNothing(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');

		$handler = $this->handlerFor(userId: 'ana', withSlots: false);

		$this->assertFalse(
			$handler->hasPermission(schema: $this->schema(), action: 'update', userId: 'ana', objectOwner: 'eigenaar', object: $case)
		);
	}//end testNoResolverGrantsNothing()

	/**
	 * A slot on the schema is not a per-object grant and is not honoured.
	 *
	 * The schema's validator refuses the entry, and the list emitters filter on
	 * the schema's block; honouring it here alone would admit on a read what a
	 * list hides.
	 *
	 * @return void
	 */
	public function testASlotWrittenOnTheSchemaIsNotHonoured(): void {
		$case = $this->caseWith(null);
		$this->occupy(object: $case, userId: 'ana');

		$granted = $this->handlerFor(userId: 'ana')->hasPermission(
			schema: $this->schema(['read' => ['medewerkers'], 'update' => [['partyRole' => self::ROLE]]]),
			action: 'update',
			userId: 'ana',
			objectOwner: 'eigenaar',
			object: $case
		);

		$this->assertFalse($granted);
	}//end testASlotWrittenOnTheSchemaIsNotHonoured()

	/**
	 * A deny beats the slot, as it beats every grant.
	 *
	 * @return void
	 */
	public function testADenyBeatsTheSlot(): void {
		$case = $this->caseWith(['update' => [['partyRole' => self::ROLE]], 'deny' => ['update' => ['user:ana']]]);
		$this->occupy(object: $case, userId: 'ana');

		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testADenyBeatsTheSlot()

	/**
	 * A private object is not reopened by a slot; only its owner, owning group or a share does that.
	 *
	 * @return void
	 */
	public function testASlotDoesNotReopenAPrivateObject(): void {
		$case = $this->caseWith(['scope' => 'private', 'update' => [['partyRole' => self::ROLE]]]);
		$this->occupy(object: $case, userId: 'ana');

		$this->assertFalse($this->may(userId: 'ana', object: $case));
	}//end testASlotDoesNotReopenAPrivateObject()

	/**
	 * A slot marked as not travelling still admits on its own object, and never reaches a child.
	 *
	 * @return void
	 */
	public function testTheNotInheritableFlagIsRespectedForASlot(): void {
		$parent = $this->caseWith(['update' => [['partyRole' => self::ROLE, 'inheritable' => false]]]);
		$this->occupy(object: $parent, userId: 'ana');

		$child = $this->caseWith(['update' => ['medewerkers']]);
		$child->setObject(['title' => 'deelzaak', 'parentCase' => $parent->getUuid()]);

		$this->assertTrue($this->may(userId: 'ana', object: $parent));
		$this->assertFalse($this->may(userId: 'ana', object: $child));

		// And an inheritable slot does not travel either: a slot is resolved on
		// the object whose own block names it, against that object's occupants.
		$open = $this->caseWith(self::slotBlock());
		$this->occupy(object: $open, userId: 'ana');
		$openChild = $this->caseWith(['update' => ['medewerkers']]);
		$openChild->setObject(['title' => 'deelzaak', 'parentCase' => $open->getUuid()]);
		$this->assertFalse($this->may(userId: 'ana', object: $openChild));
	}//end testTheNotInheritableFlagIsRespectedForASlot()

	/**
	 * Principal grants resolve as before, with and without a slot in the block.
	 *
	 * @return void
	 */
	public function testExistingPrincipalGrantsAreUnchanged(): void {
		$groupCase = $this->caseWith(['update' => ['behandelaars']]);
		$this->assertTrue($this->may(userId: 'ana', object: $groupCase, groups: ['behandelaars']));
		$this->assertFalse($this->may(userId: 'bob', object: $groupCase, groups: ['medewerkers']));

		$userCase = $this->caseWith(['update' => ['user:ana']]);
		$this->assertTrue($this->may(userId: 'ana', object: $userCase));
		$this->assertFalse($this->may(userId: 'bob', object: $userCase));

		// A slot beside the group: the group member still holds it without the
		// slot, the occupant holds it without the group, a third holds nothing.
		$mixed = $this->caseWith(['update' => ['behandelaars', ['partyRole' => self::ROLE]]]);
		$this->occupy(object: $mixed, userId: 'carla');
		$this->assertTrue($this->may(userId: 'ana', object: $mixed, groups: ['behandelaars']));
		$this->assertTrue($this->may(userId: 'carla', object: $mixed));
		$this->assertFalse($this->may(userId: 'bob', object: $mixed, groups: ['medewerkers']));

		// The schema rule still answers the verb the object does not name.
		$this->assertTrue($this->may(userId: 'bob', object: $mixed, action: 'read', groups: ['medewerkers']));
		$this->assertFalse($this->may(userId: 'carla', object: $mixed, action: 'read'));
	}//end testExistingPrincipalGrantsAreUnchanged()

	/**
	 * The scopes read names the slot as the source of the grant.
	 *
	 * @return void
	 */
	public function testTheScopesReadNamesTheSlot(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');

		$provenance = $this->handlerFor(userId: 'ana')->provenanceFor(
			request: new ProvenanceRequest(schema: $this->schema(), actions: ['update', 'delete'], userId: 'ana', object: $case)
		);

		$this->assertTrue($provenance['update']['granted']);
		$this->assertSame(ProvenanceResolver::SOURCE_SLOT, $provenance['update']['source']);
		$this->assertSame(self::ROLE, $provenance['update']['slot']);
		$this->assertSame(ProvenanceResolver::SOURCE_OBJECT, $provenance['update']['declaredAt']);
		$this->assertSame(['partyRole' => self::ROLE], $provenance['update']['rule']);
		$this->assertFalse($provenance['delete']['granted']);
	}//end testTheScopesReadNamesTheSlot()

	/**
	 * Somebody who does not occupy the slot is not told they hold it.
	 *
	 * @return void
	 */
	public function testTheScopesReadDoesNotNameASlotTheCallerDoesNotHold(): void {
		$case = $this->caseWith(self::slotBlock());
		$this->occupy(object: $case, userId: 'ana');

		$provenance = $this->handlerFor(userId: 'bob', groups: ['medewerkers'])->provenanceFor(
			request: new ProvenanceRequest(schema: $this->schema(), actions: ['update'], userId: 'bob', object: $case)
		);

		$this->assertNotSame(ProvenanceResolver::SOURCE_SLOT, $provenance['update']['source']);
		$this->assertArrayNotHasKey('slot', $provenance['update']);
	}//end testTheScopesReadDoesNotNameASlotTheCallerDoesNotHold()

	/**
	 * A direct grant's provenance keeps its shape: no slot key, the object as its source.
	 *
	 * @return void
	 */
	public function testADirectGrantsProvenanceIsUnchanged(): void {
		$case = $this->caseWith(['update' => ['behandelaars', ['partyRole' => self::ROLE]]]);
		$this->occupy(object: $case, userId: 'ana');

		$provenance = $this->handlerFor(userId: 'ana', groups: ['behandelaars'])->provenanceFor(
			request: new ProvenanceRequest(schema: $this->schema(), actions: ['update'], userId: 'ana', object: $case)
		);

		$this->assertSame(ProvenanceResolver::SOURCE_OBJECT, $provenance['update']['source']);
		$this->assertSame('behandelaars', $provenance['update']['rule']);
		$this->assertArrayNotHasKey('slot', $provenance['update']);
	}//end testADirectGrantsProvenanceIsUnchanged()
}//end class
