<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyLinkWriter;
use OCA\OpenRegister\Service\Party\PartyRoleRecords;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\PersonLinkEvents;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden outcomes of the party-kind and role acceptance check and of
 * removing a party link: which links are allowed, which are refused and
 * with what, pinned before the checks were split into smaller steps.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartyRoleServiceAccessGoldenTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The link writer.
	 *
	 * @var PartyLinkWriter&MockObject
	 */
	private $writer;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The schemas.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemas;

	/**
	 * The rows `findPartiesForObject` hands back.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $rows = [];

	/**
	 * Every mapper/event call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findPartiesForObject', 'findPrimaryParty', 'update', 'delete'])
			->getMock();
		$this->links->method('findPartiesForObject')->willReturnCallback(fn (): array => $this->rows);
		$this->links->method('delete')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->calls[] = 'delete:' . $link->getPartyUuid() . ':' . (string)$link->getRole();
				return $link;
			}
		);
		$this->writer = $this->createMock(PartyLinkWriter::class);
		$this->writer->method('write')->willReturnCallback(fn (): ContactLink => new ContactLink());
		$this->parties = $this->createMock(PartyService::class);
		$this->schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return PartyRoleService The service.
	 */
	private function service(): PartyRoleService {
		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['recordObjectEvent'])
			->getMock();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->calls[] = 'event:' . (new \ReflectionClass($event))->getShortName();
			}
		);

		return new PartyRoleService(
			new PartyRoleRecords($this->links, $this->writer, $audit),
			$this->parties,
			$this->schemas,
			new PersonLinkEvents($dispatcher)
		);
	}//end service()

	/**
	 * The schema accepts these kinds.
	 *
	 * @param array<int, array<string, mixed>> $kinds The kinds.
	 *
	 * @return void
	 */
	private function schemaAccepts(array $kinds): void {
		$schema = new Schema();
		$schema->setConfiguration(['partyKinds' => $kinds]);
		$this->schemas->method('find')->willReturn($schema);
	}//end schemaAccepts()

	/**
	 * Add a party link; returns the refusal as [code, message], or 'allowed'.
	 *
	 * @param int|string|null $schemaId The schema.
	 * @param string|null $role The role.
	 * @param bool $import Whether to link through the trusted import.
	 *
	 * @return array<int, int|string>|string The outcome.
	 */
	private function outcome(int|string|null $schemaId, ?string $role, bool $import=false): array|string {
		$payload = ['partyUuid' => 'party-a', 'role' => $role];
		try {
			if ($import === true) {
				$this->service()->importParty($this->objectOn('case-1', 1, $schemaId), $payload, 'app');
			} else {
				$this->service()->addParty('case-1', 1, $schemaId, $payload);
			}
		} catch (Exception $e) {
			return [$e->getCode(), $e->getMessage()];
		}

		return 'allowed';
	}//end outcome()

	/**
	 * The object a trusted import puts the party on.
	 *
	 * @param string $uuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|string|null $schemaId The object's schema id.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectOn(string $uuid, int $registerId, int|string|null $schemaId): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister((string) $registerId);
		if ($schemaId !== null) {
			$object->setSchema((string) $schemaId);
		}

		return $object;
	}//end objectOn()

	/**
	 * A party of a kind, found by both lookups.
	 *
	 * @param string $kind The kind.
	 *
	 * @return void
	 */
	private function partyOfKind(string $kind): void {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$this->parties->method('find')->willReturn($party);
		$this->parties->method('findUnscoped')->willReturn($party);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition(kind: $kind));
	}//end partyOfKind()

	/**
	 * No schema, or a schema that cannot be read, accepts without looking up the party.
	 *
	 * @return void
	 */
	public function testNoSchemaAcceptsWithoutReadingTheParty(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
		$this->parties->expects($this->never())->method('find');
		$this->parties->expects($this->never())->method('findUnscoped');

		$this->assertSame('allowed', $this->outcome(null, 'aanvrager'));
		$this->assertSame('allowed', $this->outcome('', 'aanvrager'));
		$this->assertSame('allowed', $this->outcome(0, 'aanvrager'));
		$this->assertSame('allowed', $this->outcome(7, 'aanvrager'));
		$this->assertSame('allowed', $this->outcome('0', 'aanvrager'));
	}//end testNoSchemaAcceptsWithoutReadingTheParty()

	/**
	 * A party the caller cannot find is refused with 404 before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnfoundPartyIsRefused(): void {
		$this->schemaAccepts([['key' => 'person']]);
		$this->parties->method('find')->willReturn(null);
		$this->writer->expects($this->never())->method('write');

		$this->assertSame([404, 'Party not found'], $this->outcome(7, null));
	}//end testAnUnfoundPartyIsRefused()

	/**
	 * The trusted import uses the unscoped lookup; a signed-in add uses the scoped one.
	 *
	 * @return void
	 */
	public function testTheImportLooksUpUnscopedAndTheAddScoped(): void {
		$this->schemaAccepts([['key' => 'person']]);
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$this->parties->method('find')->willReturn(null);
		$this->parties->method('findUnscoped')->willReturn($party);
		$this->parties->method('definitionFor')->willReturn(new PartyDefinition(kind: 'person'));

		$this->assertSame('allowed', $this->outcome(7, null, true));
		$this->assertSame([404, 'Party not found'], $this->outcome(7, null, false));
	}//end testTheImportLooksUpUnscopedAndTheAddScoped()

	/**
	 * An object that is not a party is refused with 400.
	 *
	 * @return void
	 */
	public function testAnObjectThatIsNotAPartyIsRefused(): void {
		$this->schemaAccepts([['key' => 'person']]);
		$this->parties->method('find')->willReturn(new ObjectEntity());
		$this->parties->method('definitionFor')->willReturn(null);
		$this->writer->expects($this->never())->method('write');

		$this->assertSame(
			[400, 'Object "party-a" is not a party: its schema declares no party model'],
			$this->outcome(7, null)
		);
	}//end testAnObjectThatIsNotAPartyIsRefused()

	/**
	 * The kind and role matrix: allowed and refused outcomes.
	 *
	 * @return void
	 */
	public function testTheKindAndRoleMatrix(): void {
		$this->schemaAccepts(
			[
				['key' => 'organisation'],
				['key' => 'person', 'roles' => ['aanvrager', 'gemachtigde']],
				['key' => 'animal', 'roles' => ['later']],
			]
		);
		$this->partyOfKind('person');

		$this->assertSame('allowed', $this->outcome(7, 'aanvrager'));
		$this->assertSame('allowed', $this->outcome(7, null));
		$this->assertSame(
			[400, 'Party kind "person" holds only the roles: aanvrager, gemachtigde'],
			$this->outcome(7, 'later')
		);
	}//end testTheKindAndRoleMatrix()

	/**
	 * A kind that is not declared is refused, naming every declared key.
	 *
	 * @return void
	 */
	public function testAnUndeclaredKindIsRefused(): void {
		$this->schemaAccepts([['key' => 'organisation'], ['key' => 'animal']]);
		$this->partyOfKind('person');

		$this->assertSame(
			[400, 'Party kind "person" is not one of: organisation, animal'],
			$this->outcome(7, 'aanvrager')
		);
	}//end testAnUndeclaredKindIsRefused()

	/**
	 * Roles that are not a list, or an empty list, restrict nothing.
	 *
	 * @return void
	 */
	public function testRolesThatAreNotAListRestrictNothing(): void {
		$this->schemaAccepts([['key' => 'person', 'roles' => 'aanvrager'], ['key' => 'organisation', 'roles' => []]]);
		$this->partyOfKind('person');

		$this->assertSame('allowed', $this->outcome(7, 'iets'));
	}//end testRolesThatAreNotAListRestrictNothing()

	/**
	 * A stored link.
	 *
	 * @param string $partyUuid The party.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link.
	 */
	private function link(string $partyUuid, ?string $role): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setPartyUuid($partyUuid);
		$link->setRole($role);

		return $link;
	}//end link()

	/**
	 * Removing without a role removes every role of that party, each delete before its event.
	 *
	 * @return void
	 */
	public function testRemovingWithoutARoleRemovesEveryRole(): void {
		$this->rows = [$this->link('party-a', 'x'), $this->link('party-b', 'x'), $this->link('party-a', 'y')];

		$this->assertSame(2, $this->service()->removeParty('case-1', 'party-a'));
		$this->assertSame(
			['delete:party-a:x', 'event:PersonUnlinkedEvent', 'delete:party-a:y', 'event:PersonUnlinkedEvent'],
			$this->calls
		);
	}//end testRemovingWithoutARoleRemovesEveryRole()

	/**
	 * Removing with a role removes only that role; a role the party does not hold is 404.
	 *
	 * @return void
	 */
	public function testRemovingWithARoleRemovesOnlyThatRole(): void {
		$this->rows = [$this->link('party-a', 'x'), $this->link('party-a', 'y')];

		$this->assertSame(1, $this->service()->removeParty('case-1', 'party-a', 'y'));
		$this->assertSame(['delete:party-a:y', 'event:PersonUnlinkedEvent'], $this->calls);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Party link not found');
		$this->service()->removeParty('case-1', 'party-a', 'z');
	}//end testRemovingWithARoleRemovesOnlyThatRole()
}//end class
