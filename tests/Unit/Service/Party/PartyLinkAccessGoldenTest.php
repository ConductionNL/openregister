<?php

/**
 * Golden test: who may link a party onto an object, and with whose rights
 * the party is read. Runs the role service over the REAL link writer so the
 * scope decision is pinned end to end through the stable public surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 * @spec openspec/changes/example-set-install-agrees-with-the-store/specs/example-sets/spec.md
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
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\PersonLinkEvents;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the allowed and refused outcomes of linking a party.
 *
 * @covers \OCA\OpenRegister\Service\Party\PartyRoleService
 * @covers \OCA\OpenRegister\Service\Party\PartyLinkWriter
 */
class PartyLinkAccessGoldenTest extends TestCase {

	/**
	 * The rows inserted through the writer.
	 *
	 * @var array<int, ContactLink>
	 */
	private array $inserted = [];

	/**
	 * Which reads ran: 'scoped' or 'unscoped'.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * A role service over the real writer.
	 *
	 * @param string|null $signedIn   The signed-in uid, or null.
	 * @param bool        $rbacSeesIt Whether the caller-scoped read returns the party.
	 * @param array<int, array<string, mixed>> $kinds The schema's accepted kinds.
	 *
	 * @return PartyRoleService
	 */
	private function service(?string $signedIn, bool $rbacSeesIt, array $kinds=[]): PartyRoleService {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$party->setName('Jan');

		$parties = $this->createMock(PartyService::class);
		$parties->method('find')->willReturnCallback(
			function () use ($party, $rbacSeesIt): ?ObjectEntity {
				$this->reads[] = 'scoped';
				if ($rbacSeesIt === true) {
					return $party;
				}

				return null;
			}
		);
		$parties->method('findUnscoped')->willReturnCallback(
			function () use ($party): ObjectEntity {
				$this->reads[] = 'unscoped';
				return $party;
			}
		);
		$parties->method('definitionFor')->willReturn(new PartyDefinition(kind: 'person'));
		$parties->method('addressesOfKind')->willReturn([]);
		$parties->method('addresses')->willReturn([]);

		$links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findPartiesForObject', 'findPrimaryParty', 'findByObjectContactAndRole', 'insert', 'update', 'delete'])
			->getMock();
		$links->method('findPartiesForObject')->willReturn([]);
		$links->method('findByObjectContactAndRole')->willReturn(null);
		$links->method('update')->willReturnArgument(0);
		$links->method('insert')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->inserted[] = $link;
				return $link;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signedIn !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($signedIn);
		}

		$session->method('getUser')->willReturn($user);

		$schema = new Schema();
		$schema->setConfiguration(['partyKinds' => $kinds]);
		$schemas = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemas->method('find')->willReturn($schema);

		$audit = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['createAuditTrailEntry'])
			->getMock();

		return new PartyRoleService(
			$links,
			new PartyLinkWriter($links, $parties, $session),
			$parties,
			$schemas,
			$audit,
			new PersonLinkEvents($this->createMock(IEventDispatcher::class))
		);
	}//end service()

	/**
	 * Allowed: a signed-in caller who can read the party links it as themself,
	 * with the object's register and numeric schema id stored.
	 *
	 * @return void
	 */
	public function testASignedInCallerWhoCanReadThePartyLinksIt(): void {
		$link = $this->service(signedIn: 'pieter', rbacSeesIt: true)
			->addParty('case-1', 3, '9', ['partyUuid' => 'party-a', 'role' => 'aanvrager']);

		$this->assertSame('pieter', $link->getLinkedBy());
		$this->assertSame('case-1', $link->getObjectUuid());
		$this->assertSame(3, $link->getRegisterId());
		$this->assertSame(9, $link->getSchemaId());
		$this->assertSame('aanvrager', $link->getRole());
		$this->assertSame(['scoped'], $this->reads);
	}//end testASignedInCallerWhoCanReadThePartyLinksIt()

	/**
	 * A schema slug is not a numeric id: the link stores no schema id.
	 *
	 * @return void
	 */
	public function testASlugSchemaStoresNoSchemaId(): void {
		$link = $this->service(signedIn: 'pieter', rbacSeesIt: true)
			->addParty('case-1', 3, 'person', ['partyUuid' => 'party-a']);

		$this->assertNull($link->getSchemaId());
	}//end testASlugSchemaStoresNoSchemaId()

	/**
	 * Refused: a signed-in caller who cannot read the party gets 404, on the
	 * kind check and in the writer alike, and nothing is stored.
	 *
	 * @return void
	 */
	public function testASignedInCallerWhoCannotReadThePartyIsRefused(): void {
		foreach ([[], [['key' => 'person']]] as $kinds) {
			try {
				$this->service(signedIn: 'pieter', rbacSeesIt: false, kinds: $kinds)
					->addParty('case-1', 3, 9, ['partyUuid' => 'party-a']);
				$this->fail('Expected a refusal.');
			} catch (Exception $e) {
				$this->assertSame(404, $e->getCode());
			}
		}

		$this->assertSame([], $this->inserted);
		$this->assertNotContains('unscoped', $this->reads);
	}//end testASignedInCallerWhoCannotReadThePartyIsRefused()

	/**
	 * Refused: nobody signed in on the API path is 401, however the payload
	 * names a linker.
	 *
	 * @return void
	 */
	public function testNobodySignedInOnTheApiPathIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service(signedIn: null, rbacSeesIt: true)
			->addParty('case-1', 3, 9, ['partyUuid' => 'party-a', 'linkedBy' => 'spoofed']);
	}//end testNobodySignedInOnTheApiPathIsRefused()

	/**
	 * Allowed: the trusted import (nobody signed in, a linker named) reads the
	 * party without RBAC, on the kind check and in the writer.
	 *
	 * @return void
	 */
	public function testTheTrustedImportReadsWithoutRbac(): void {
		$link = $this->service(signedIn: null, rbacSeesIt: false, kinds: [['key' => 'person']])
			->importParty('case-1', 3, 9, ['partyUuid' => 'party-a'], 'dossiq');

		$this->assertSame('dossiq', $link->getLinkedBy());
		$this->assertSame(['unscoped', 'unscoped'], $this->reads);
	}//end testTheTrustedImportReadsWithoutRbac()

	/**
	 * Refused: an import while somebody IS signed in reads with their rights
	 * in the writer, so a party they cannot read is 404.
	 *
	 * @return void
	 */
	public function testAnImportWhileSignedInUsesTheCallersRights(): void {
		try {
			$this->service(signedIn: 'pieter', rbacSeesIt: false, kinds: [['key' => 'person']])
				->importParty('case-1', 3, 9, ['partyUuid' => 'party-a'], 'dossiq');
			$this->fail('Expected a refusal.');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame(['unscoped', 'scoped'], $this->reads);
		$this->assertSame([], $this->inserted);
	}//end testAnImportWhileSignedInUsesTheCallersRights()

	/**
	 * Refused: a kind the schema does not accept is 400 on the import path too.
	 *
	 * @return void
	 */
	public function testAnUnacceptedKindIsRefusedOnImport(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service(signedIn: null, rbacSeesIt: true, kinds: [['key' => 'organisation']])
			->importParty('case-1', 3, 9, ['partyUuid' => 'party-a'], 'dossiq');
	}//end testAnUnacceptedKindIsRefusedOnImport()
}//end class
