<?php

/**
 * The party records answer the party notifications' recipients question: one
 * entry per linked party, narrowed by role, at the correspondence address
 * when there is one, with the indicator's refusal read per party.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Party
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Notifications\PartyRecipientsInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Party\PartyAddressSelection;
use OCA\OpenRegister\Service\Party\PartyRecipients;
use OCA\OpenRegister\Service\Party\PartySendRefusalInterface;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use PHPUnit\Framework\TestCase;

final class PartyRecipientsTest extends TestCase {

	private function link(?string $party, string $role, string $name): ContactLink {
		$link = new ContactLink();
		$link->setPartyUuid($party);
		$link->setRole($role);
		$link->setDisplayName($name);
		return $link;
	}//end link()

	public function testEveryLinkedPartyIsAnEntryAtItsCorrespondenceAddress(): void {
		$links = $this->createMock(ContactLinkMapperInterface::class);
		$links->method('findPartiesForObject')->with('obj-1')->willReturn([
			$this->link(party: 'p-1', role: 'melder', name: 'Ada'),
			$this->link(party: null, role: 'melder', name: 'Nobody'),
			$this->link(party: 'p-gone', role: 'melder', name: 'Gone'),
			$this->link(party: 'p-2', role: 'behandelaar', name: 'Bob'),
		]);

		$party1 = new ObjectEntity();
		$party2 = new ObjectEntity();
		$parties = $this->createMock(PartyServiceInterface::class);
		$parties->method('find')->willReturnCallback(
			static fn (string $partyUuid): ?ObjectEntity => ['p-1' => $party1, 'p-2' => $party2][$partyUuid] ?? null
		);
		$parties->method('definitionFor')->willReturn(null);
		$parties->method('addressesOfKind')->willReturnCallback(
			function (ObjectEntity $party, PartyAddressSelection $selection) use ($party1): array {
				$this->assertSame(PartyRecipientsInterface::OUTBOUND_KIND, $selection->kind);
				return $party === $party1 ? [['type' => 'phone', 'value' => '06'], ['type' => 'email', 'value' => 'ada@example.org']] : [];
			}
		);
		$parties->method('addresses')->willReturn([['type' => 'email', 'value' => 'bob@example.org']]);

		$indicators = $this->createMock(PartySendRefusalInterface::class);
		$indicators->method('sendRefusalFor')->willReturnCallback(
			static fn (string $partyUuid): ?string => $partyUuid === 'p-2' ? 'geheimhouding' : null
		);

		$recipients = (new PartyRecipients(links: $links, parties: $parties, indicators: $indicators))->recipientsForObject(objectUuid: 'obj-1');

		$this->assertSame(
			[
				['party' => 'p-1', 'role' => 'melder', 'displayName' => 'Ada', 'address' => 'ada@example.org', 'refusedBy' => null],
				['party' => 'p-2', 'role' => 'behandelaar', 'displayName' => 'Bob', 'address' => 'bob@example.org', 'refusedBy' => 'geheimhouding'],
			],
			$recipients
		);
	}//end testEveryLinkedPartyIsAnEntryAtItsCorrespondenceAddress()

	public function testARoleNarrowsTheLinks(): void {
		$links = $this->createMock(ContactLinkMapperInterface::class);
		$links->method('findPartiesForObject')->willReturn([
			$this->link(party: 'p-1', role: 'melder', name: 'Ada'),
			$this->link(party: 'p-2', role: 'behandelaar', name: 'Bob'),
		]);
		$parties = $this->createMock(PartyServiceInterface::class);
		$parties->method('find')->willReturn(new ObjectEntity());
		$parties->method('addressesOfKind')->willReturn([]);
		$parties->method('addresses')->willReturn([]);

		$recipients = (new PartyRecipients(links: $links, parties: $parties, indicators: $this->createMock(PartySendRefusalInterface::class)))
			->recipientsForObject(objectUuid: 'obj-1', role: 'behandelaar');

		$this->assertSame([['party' => 'p-2', 'role' => 'behandelaar', 'displayName' => 'Bob', 'address' => null, 'refusedBy' => null]], $recipients);
	}//end testARoleNarrowsTheLinks()

	public function testThePortIsBoundInTheProcessesWiring(): void {
		$this->assertSame(PartyRecipients::class, FlowWiring::BINDINGS[PartyRecipientsInterface::class]);
	}//end testThePortIsBoundInTheProcessesWiring()
}//end class
