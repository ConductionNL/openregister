<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A party without an account: its addresses, its indicators and its tree.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartyServiceTest extends TestCase {

	/**
	 * The object layer.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The schemas.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemas;

	/**
	 * The service under test.
	 *
	 * @var PartyService
	 */
	private PartyService $service;

	/**
	 * The parties this test's object layer knows, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Build the service on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();

		$this->objects->method('find')->willReturnCallback(
			function (int|string $id) {
				return ($this->stored[(string)$id] ?? null);
			}
		);

		$this->schemas->method('find')->willReturn($this->partySchema());
		$this->schemas->method('findAll')->willReturn([$this->partySchema()]);

		$this->service = new PartyService($this->objects, $this->schemas);
	}//end setUp()

	/**
	 * A schema declaring itself a party schema with a nesting parent.
	 *
	 * @return Schema The schema.
	 */
	private function partySchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['naam' => [], 'adressen' => [], 'indicatoren' => [], 'moeder' => []]);
		$schema->setConfiguration(
			[
				'x-openregister-party' => [
					'kind' => 'organisation',
					'nameProperty' => 'naam',
					'addressesProperty' => 'adressen',
					'indicatorsProperty' => 'indicatoren',
					'parentProperty' => 'moeder',
					'maxDepth' => 3,
				],
			]
		);

		return $schema;
	}//end partySchema()

	/**
	 * Store a party the object layer will hand back.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The party.
	 */
	private function party(string $uuid, array $data): ObjectEntity {
		$party = new ObjectEntity();
		$party->setUuid($uuid);
		$party->setRegister('1');
		$party->setSchema('7');
		$party->setObject($data);
		$this->stored[$uuid] = $party;

		return $party;
	}//end party()

	/**
	 * Addresses carry a kind, and a register that has always held one bare
	 * e-mail per party keeps working: a bare string reads as correspondence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAddressesCarryAKindAndABareStringStillReads(): void {
		$party = $this->party(
			uuid: 'party-a',
			data: [
				'adressen' => [
					'oud@example.org',
					['kind' => 'case', 'type' => 'postal', 'value' => 'Dorpsstraat 1'],
					['kind' => 'correspondence', 'value' => 'nieuw@example.org'],
				],
			]
		);

		$addresses = $this->service->addresses(party: $party);

		$this->assertCount(3, $addresses);
		$this->assertSame('correspondence', $addresses[0]['kind']);
		$this->assertSame('email', $addresses[0]['type']);
		$this->assertSame('oud@example.org', $addresses[0]['value']);
		$this->assertCount(1, $this->service->addressesOfKind(party: $party, kind: 'case'));
		$this->assertCount(2, $this->service->addressesOfKind(party: $party, kind: 'correspondence'));
	}//end testAddressesCarryAKindAndABareStringStillReads()

	/**
	 * Mail from a party's second address resolves to that party, and no
	 * second party is created — nothing is written at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testASecondAddressResolvesToTheSameParty(): void {
		$party = $this->party(
			uuid: 'party-a',
			data: ['adressen' => ['eerste@example.org', 'tweede@example.org']]
		);

		$this->objects->method('findAll')->willReturn([$party]);
		$this->objects->expects($this->never())->method('saveObject');

		$resolved = $this->service->resolveByAddress(address: 'TWEEDE@example.org');

		$this->assertNotNull($resolved);
		$this->assertSame('party-a', $resolved->getUuid());
	}//end testASecondAddressResolvesToTheSameParty()

	/**
	 * A search that matches loosely never returns the wrong party: the exact
	 * comparison over the party's own addresses decides.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testALooseSearchMatchIsNotAResolution(): void {
		$other = $this->party(uuid: 'party-b', data: ['adressen' => ['iemand.anders@example.org']]);
		$this->objects->method('findAll')->willReturn([$other]);

		$this->assertNull($this->service->resolveByAddress(address: 'anders@example.org'));
	}//end testALooseSearchMatchIsNotAResolution()

	/**
	 * An indicator declares its effect; one naming none, and one naming an
	 * effect this version does not know, both warn rather than vanish.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testAnIndicatorWithoutAKnownEffectWarns(): void {
		$party = $this->party(
			uuid: 'party-a',
			data: [
				'indicatoren' => [
					['key' => 'overleden', 'label' => 'Overleden'],
					['key' => 'geheim', 'label' => 'Geheimhouding', 'effect' => 'refuse-publication'],
					['key' => 'raar', 'effect' => 'explode'],
					'kort',
				],
			]
		);

		$indicators = $this->service->indicators(party: $party);

		$this->assertCount(4, $indicators);
		$this->assertSame('warn', $indicators[0]['effect']);
		$this->assertSame('refuse-publication', $indicators[1]['effect']);
		$this->assertSame('warn', $indicators[2]['effect']);
		$this->assertSame('kort', $indicators[3]['key']);
		$this->assertSame('kort', $indicators[3]['label']);
	}//end testAnIndicatorWithoutAKnownEffectWarns()

	/**
	 * B is A's parent; giving B the parent A would close a cycle, and the
	 * refusal names it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAParentThatWouldCloseACycleIsRefused(): void {
		$this->party(uuid: 'org-a', data: ['moeder' => 'org-b']);
		$this->party(uuid: 'org-b', data: []);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('cycle');

		$this->service->assertParentAllowed(partyUuid: 'org-b', parentUuid: 'org-a');
	}//end testAParentThatWouldCloseACycleIsRefused()

	/**
	 * A party cannot be its own parent, which is the one-step cycle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAPartyCannotBeItsOwnParent(): void {
		$this->party(uuid: 'org-a', data: []);

		$this->expectException(Exception::class);

		$this->service->assertParentAllowed(partyUuid: 'org-a', parentUuid: 'org-a');
	}//end testAPartyCannotBeItsOwnParent()

	/**
	 * A chain longer than the declared bound is refused, naming the bound.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAChainDeeperThanTheBoundIsRefused(): void {
		$this->party(uuid: 'org-1', data: ['moeder' => 'org-2']);
		$this->party(uuid: 'org-2', data: ['moeder' => 'org-3']);
		$this->party(uuid: 'org-3', data: ['moeder' => 'org-4']);
		$this->party(uuid: 'org-4', data: []);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('maximum of 3');

		$this->service->assertParentAllowed(partyUuid: 'org-0', parentUuid: 'org-1');
	}//end testAChainDeeperThanTheBoundIsRefused()

	/**
	 * A parent inside the bound and outside any cycle is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function testAGoodParentIsAllowed(): void {
		$this->party(uuid: 'org-a', data: []);
		$this->party(uuid: 'org-b', data: []);

		$this->service->assertParentAllowed(partyUuid: 'org-a', parentUuid: 'org-b');

		$this->addToAssertionCount(1);
	}//end testAGoodParentIsAllowed()

	/**
	 * A party service whose object layer records every lookup and holds `$held` in register 3, schema 7.
	 *
	 * @param array<int, array<string, mixed>> $calls Every lookup, as its arguments.
	 * @param ObjectEntity|null $inPartyTable What the party table holds for the id.
	 * @param ObjectEntity|null $elsewhere    What the instance-wide search finds.
	 *
	 * @return PartyService The service.
	 */
	private function scopedService(array &$calls, ?ObjectEntity $inPartyTable, ?ObjectEntity $elsewhere): PartyService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null
			) use (
				&$calls,
				$inPartyTable,
				$elsewhere
			): ?ObjectEntity {
				$calls[] = ['id' => (string)$id, 'register' => $register, 'schema' => $schema];
				if ($schema === null) {
					return $elsewhere;
				}

				if ($inPartyTable === null) {
					throw new DoesNotExistException('Not in this table');
				}

				return $inPartyTable;
			}
		);

		$registers = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getAllRegisterIdsWithSchema'])
			->getMock();
		$registers->method('getAllRegisterIdsWithSchema')->willReturn([3]);

		return new PartyService($objects, $this->schemas, $registers);
	}//end scopedService()

	/**
	 * A party is read from the party table, without searching every table on the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-is-looked-up-in-the-party-tables-first
	 */
	public function testAPartyIsReadFromThePartyTable(): void {
		$calls = [];
		$party = $this->party(uuid: 'org-a', data: ['naam' => 'Gemeente Zuiddrecht']);

		$found = $this->scopedService(calls: $calls, inPartyTable: $party, elsewhere: null)->find(partyUuid: 'org-a');

		$this->assertSame($party, $found);
		$this->assertSame([['id' => 'org-a', 'register' => 3, 'schema' => 7]], $calls);
	}//end testAPartyIsReadFromThePartyTable()

	/**
	 * An id the party tables do not hold is still looked up across the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-is-looked-up-in-the-party-tables-first
	 */
	public function testAnIdOutsideThePartyTablesFallsBackToTheInstance(): void {
		$calls = [];
		$elsewhere = $this->party(uuid: 'org-b', data: ['naam' => 'Elders']);

		$found = $this->scopedService(calls: $calls, inPartyTable: null, elsewhere: $elsewhere)->find(partyUuid: 'org-b');

		$this->assertSame($elsewhere, $found);
		$this->assertSame(
			[
				['id' => 'org-b', 'register' => 3, 'schema' => 7],
				['id' => 'org-b', 'register' => null, 'schema' => null],
			],
			$calls
		);
	}//end testAnIdOutsideThePartyTablesFallsBackToTheInstance()
}//end class
