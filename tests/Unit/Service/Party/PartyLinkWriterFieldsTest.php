<?php

/**
 * Tests for the fields PartyLinkWriter copies onto a link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Service\Party\PartyAddressSelection;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyLinkRequest;
use OCA\OpenRegister\Service\Party\PartyLinkTarget;
use OCA\OpenRegister\Service\Party\PartyLinkWriter;
use OCA\OpenRegister\Service\Party\PartyPropertyNames;
use OCA\OpenRegister\Service\Party\TrustedPartyReaderInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Display name and e-mail resolution, in their order of preference.
 *
 * @covers \OCA\OpenRegister\Service\Party\PartyLinkWriter
 */
class PartyLinkWriterFieldsTest extends TestCase {

	/**
	 * Write a link for a party and return what was inserted.
	 *
	 * @param ObjectEntity                     $party         The party.
	 * @param PartyDefinition                  $definition    Its declaration.
	 * @param array<int, array<string,string>> $correspondence Correspondence addresses.
	 * @param array<int, array<string,string>> $all           All addresses.
	 *
	 * @return ContactLink
	 */
	private function write(ObjectEntity $party, PartyDefinition $definition, array $correspondence, array $all): ContactLink {
		$parties = $this->createMock(TrustedPartyReaderInterface::class);
		$parties->method('find')->with('party-a')->willReturn($party);
		$parties->expects($this->never())->method('findUnscoped');
		$parties->method('definitionFor')->willReturn($definition);
		$parties->method('addressesOfKind')->with($party, new PartyAddressSelection('correspondence', $definition))->willReturn($correspondence);
		$parties->method('addresses')->willReturn($all);

		$links = $this->createMock(ContactLinkMapperInterface::class);
		$links->method('findByObjectContactAndRole')->willReturn(null);
		$links->expects($this->once())->method('insert')->willReturnArgument(0);
		$links->expects($this->never())->method('update');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return (new PartyLinkWriter(links: $links, parties: $parties, session: $session))
			->write(request: new PartyLinkRequest(target: new PartyLinkTarget(objectUuid: 'case-1', registerId: 3, schemaId: null), partyUuid: 'party-a', role: null, linkedBy: 'ignored'));
	}//end write()

	/**
	 * The declared name property wins over the entity name; a correspondence e-mail wins
	 * over any other address and the general list is not consulted.
	 *
	 * @return void
	 */
	public function testDeclaredNameAndCorrespondenceEmailWin(): void {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$party->setName('Entity name');
		$party->setObject(['fullName' => '  Jan Jansen ']);
		$definition = new PartyDefinition(kind: 'person', properties: new PartyPropertyNames(nameProperty: 'fullName'));

		$link = $this->write(
			party: $party,
			definition: $definition,
			correspondence: [['type' => 'post', 'value' => 'Straat 1'], ['type' => 'email', 'value' => 'post@example.org']],
			all: [['type' => 'email', 'value' => 'other@example.org']]
		);

		$this->assertSame('Jan Jansen', $link->getDisplayName());
		$this->assertSame('post@example.org', $link->getEmail());
		$this->assertSame('jan', $link->getLinkedBy(), 'The signed-in user is the linker, not the named one.');
		$this->assertSame('person', $link->getPartyKind());
		$this->assertNull($link->getSchemaId());
	}//end testDeclaredNameAndCorrespondenceEmailWin()

	/**
	 * An empty declared name falls back to the uuid when the entity has no name, and no
	 * e-mail anywhere leaves the e-mail empty.
	 *
	 * @return void
	 */
	public function testFallsBackToUuidAndNoEmail(): void {
		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$party->setObject(['fullName' => '   ']);
		$definition = new PartyDefinition(kind: 'org', properties: new PartyPropertyNames(nameProperty: 'fullName'));

		$link = $this->write(party: $party, definition: $definition, correspondence: [], all: [['type' => 'phone', 'value' => '06']]);

		$this->assertSame('party-a', $link->getDisplayName());
		$this->assertNull($link->getEmail());
	}//end testFallsBackToUuidAndNoEmail()
}//end class
