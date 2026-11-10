<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyLinkTarget;
use OCA\OpenRegister\Service\Party\PartyLinkWriter;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of writing a party link: the refusals in their order, and
 * which row a write inserts or refreshes, pinned before the write was split.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartyLinkWriterAccessGoldenTest extends TestCase {

	/**
	 * Every mapper call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build a writer.
	 *
	 * @param string|null $signedIn The signed-in uid.
	 * @param ObjectEntity|null $scoped What the scoped lookup finds.
	 * @param ObjectEntity|null $unscoped What the unscoped lookup finds.
	 * @param PartyDefinition|null $definition The party's declaration.
	 * @param ContactLink|null $existing The link the party already holds.
	 *
	 * @return PartyLinkWriter The writer.
	 */
	private function writer(
		?string $signedIn,
		?ObjectEntity $scoped,
		?ObjectEntity $unscoped,
		?PartyDefinition $definition,
		?ContactLink $existing=null
	): PartyLinkWriter {
		$parties = $this->createMock(PartyService::class);
		$parties->method('find')->willReturnCallback(
			function () use ($scoped): ?ObjectEntity {
				$this->calls[] = 'find';
				return $scoped;
			}
		);
		$parties->method('findUnscoped')->willReturnCallback(
			function () use ($unscoped): ?ObjectEntity {
				$this->calls[] = 'findUnscoped';
				return $unscoped;
			}
		);
		$parties->method('definitionFor')->willReturn($definition);
		$parties->method('addressesOfKind')->willReturn([]);
		$parties->method('addresses')->willReturn([['type' => 'phone', 'value' => '06'], ['type' => 'email', 'value' => 'a@example.org']]);

		$links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectContactAndRole', 'insert', 'update'])
			->getMock();
		$links->method('findByObjectContactAndRole')->willReturn($existing);
		$links->method('insert')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->calls[] = 'insert';
				return $link;
			}
		);
		$links->method('update')->willReturnCallback(
			function (ContactLink $link): ContactLink {
				$this->calls[] = 'update';
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

		return new PartyLinkWriter($links, $parties, $session);
	}//end writer()

	/**
	 * A party.
	 *
	 * @return ObjectEntity The party.
	 */
	private function party(): ObjectEntity {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$party->setName('  Jan  ');

		return $party;
	}//end party()

	/**
	 * Write and return the refusal as [code, message], or the stored link.
	 *
	 * @param PartyLinkWriter $writer The writer.
	 * @param string|null $linkedBy The named linker.
	 *
	 * @return ContactLink|array<int, int|string> The outcome.
	 */
	private function outcome(PartyLinkWriter $writer, ?string $linkedBy): ContactLink|array {
		try {
			return $writer->write(new PartyLinkTarget('case-1', 3, 7), 'party-a', 'aanvrager', $linkedBy);
		} catch (Exception $e) {
			return [$e->getCode(), $e->getMessage()];
		}
	}//end outcome()

	/**
	 * The refusals: 404 before 400 before 401, each before any write.
	 *
	 * @return void
	 */
	public function testTheRefusalsAndTheirOrder(): void {
		$definition = new PartyDefinition(kind: 'person');

		$this->assertSame([404, 'Party not found'], $this->outcome($this->writer('u', null, $this->party(), $definition), null));
		$this->assertSame([404, 'Party not found'], $this->outcome($this->writer(null, $this->party(), null, $definition), 'app'));
		$this->assertSame(
			[400, 'Object "party-a" is not a party: its schema declares no party model'],
			$this->outcome($this->writer(null, $this->party(), $this->party(), null), null)
		);
		$this->assertSame([401, 'No user logged in'], $this->outcome($this->writer(null, $this->party(), $this->party(), $definition), null));
		$this->assertSame([401, 'No user logged in'], $this->outcome($this->writer(null, $this->party(), $this->party(), $definition), ''));
		$this->assertSame(['find', 'findUnscoped', 'find', 'find', 'findUnscoped'], $this->calls);
	}//end testTheRefusalsAndTheirOrder()

	/**
	 * A new link is inserted with every field set.
	 *
	 * @return void
	 */
	public function testANewLinkIsInserted(): void {
		$link = $this->outcome($this->writer('jan', $this->party(), null, new PartyDefinition(kind: 'person')), 'app');

		$this->assertInstanceOf(ContactLink::class, $link);
		$this->assertSame(['find', 'insert'], $this->calls);
		$this->assertSame('case-1', $link->getObjectUuid());
		$this->assertSame(ContactLink::partyUid(partyUuid: 'party-a'), $link->getContactUid());
		$this->assertFalse((bool)$link->getPrimaryParty());
		$this->assertSame(3, $link->getRegisterId());
		$this->assertSame(7, $link->getSchemaId());
		$this->assertSame('party-a', $link->getPartyUuid());
		$this->assertSame('person', $link->getPartyKind());
		$this->assertSame('Jan', $link->getDisplayName());
		$this->assertSame('a@example.org', $link->getEmail());
		$this->assertSame('aanvrager', $link->getRole());
		$this->assertSame('jan', $link->getLinkedBy());
		$this->assertNotNull($link->getLinkedAt());
	}//end testANewLinkIsInserted()

	/**
	 * An existing link is refreshed in place and keeps its primary flag and object.
	 *
	 * @return void
	 */
	public function testAnExistingLinkIsUpdated(): void {
		$existing = new ContactLink();
		$existing->setObjectUuid('case-1');
		$existing->setContactUid('kept');
		$existing->setPrimaryParty(true);

		$link = $this->outcome($this->writer(null, null, $this->party(), new PartyDefinition(kind: 'org'), $existing), 'app');

		$this->assertSame($existing, $link);
		$this->assertSame(['findUnscoped', 'update'], $this->calls);
		$this->assertSame('kept', $link->getContactUid());
		$this->assertTrue((bool)$link->getPrimaryParty());
		$this->assertSame('org', $link->getPartyKind());
		$this->assertSame('app', $link->getLinkedBy());
	}//end testAnExistingLinkIsUpdated()
}//end class
