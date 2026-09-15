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
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An indicator declares its effect, and the effect is evaluated at the act.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */
class PartyIndicatorGuardTest extends TestCase {

	/**
	 * The link rows.
	 *
	 * @var ContactLinkMapper&MockObject
	 */
	private $links;

	/**
	 * The party records.
	 *
	 * @var PartyService&MockObject
	 */
	private $parties;

	/**
	 * The guard under test.
	 *
	 * @var PartyIndicatorGuard
	 */
	private PartyIndicatorGuard $guard;

	/**
	 * Build the guard on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->links = $this->getMockBuilder(ContactLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findPartiesForObject', 'findByPartyUuid'])
			->getMock();
		$this->parties = $this->createMock(PartyService::class);
		$this->guard = new PartyIndicatorGuard($this->links, $this->parties);
	}//end setUp()

	/**
	 * One party on an object, carrying the indicators given.
	 *
	 * @param array<int, array<string, mixed>> $indicators The indicators.
	 *
	 * @return void
	 */
	private function objectHasPartyCarrying(array $indicators): void {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setPartyUuid('party-a');
		$link->setPartyKind('person');
		$link->setRole('aanvrager');
		$this->links->method('findPartiesForObject')->willReturn([$link]);

		$party = new ObjectEntity();
		$party->setUuid('party-a');
		$this->parties->method('find')->willReturn($party);
		$this->parties->method('indicators')->willReturn($indicators);
	}//end objectHasPartyCarrying()

	/**
	 * An indicator whose effect is refuse publication refuses the
	 * publication, and the message names the indicator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testAProtectedAddressRefusesPublication(): void {
		$this->objectHasPartyCarrying(
			indicators: [
				['key' => 'geheim', 'label' => 'Geheimhouding persoonsgegevens', 'effect' => 'refuse-publication', 'note' => null],
			]
		);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(403);
		$this->expectExceptionMessage('Geheimhouding persoonsgegevens');

		$this->guard->assertPublicationAllowed(objectUuid: 'case-1');
	}//end testAProtectedAddressRefusesPublication()

	/**
	 * A warn indicator is read, and refuses nothing. The difference between
	 * the three effects is the whole point of declaring one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testAWarnIndicatorRefusesNothing(): void {
		$this->objectHasPartyCarrying(
			indicators: [['key' => 'overleden', 'label' => 'Overleden', 'effect' => 'warn', 'note' => null]]
		);

		$this->guard->assertPublicationAllowed(objectUuid: 'case-1');
		$this->assertNull($this->guard->sendRefusalFor(partyUuid: 'party-a'));

		$read = $this->guard->indicatorsForObject(objectUuid: 'case-1');
		$this->assertCount(1, $read);
		$this->assertSame('party-a', $read[0]['party']);
		$this->assertSame('aanvrager', $read[0]['role']);
		$this->assertSame('warn', $read[0]['effect']);
	}//end testAWarnIndicatorRefusesNothing()

	/**
	 * A refuse-send indicator stops an outbound message and leaves a
	 * publication alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testARefuseSendIndicatorStopsOnlyTheSend(): void {
		$this->objectHasPartyCarrying(
			indicators: [['key' => 'geen-post', 'label' => 'Geen post', 'effect' => 'refuse-send', 'note' => null]]
		);

		// The publication is untouched: the effects are distinct, which is the
		// whole point of declaring one rather than "this party is sensitive".
		$this->guard->assertPublicationAllowed(objectUuid: 'case-1');

		// The send refuses PER PARTY and names the indicator, so the caller can
		// leave that party out and say why.
		$this->assertSame('Geen post', $this->guard->sendRefusalFor(partyUuid: 'party-a'));
	}//end testARefuseSendIndicatorStopsOnlyTheSend()

	/**
	 * An indicator set on a party reaches every object that party holds a
	 * role on, and none of those objects is read or written to find out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testAnIndicatorReachesEveryCaseOfThatParty(): void {
		$links = [];
		foreach (['case-1', 'case-2', 'case-3'] as $objectUuid) {
			$link = new ContactLink();
			$link->setObjectUuid($objectUuid);
			$link->setPartyUuid('party-a');
			$links[] = $link;
		}

		$this->links->expects($this->once())
			->method('findByPartyUuid')
			->with('party-a')
			->willReturn($links);

		$this->assertSame(
			['case-1', 'case-2', 'case-3'],
			$this->guard->objectsOfParty(partyUuid: 'party-a')
		);
	}//end testAnIndicatorReachesEveryCaseOfThatParty()

	/**
	 * A link that names no party contributes no indicator, so a user or
	 * contact link on the same object is simply not a party.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function testALinkWithoutAPartyCarriesNoIndicator(): void {
		$link = new ContactLink();
		$link->setObjectUuid('case-1');
		$link->setUserId('jan');
		$this->links->method('findPartiesForObject')->willReturn([$link]);

		$this->assertSame([], $this->guard->indicatorsForObject(objectUuid: 'case-1'));
	}//end testALinkWithoutAPartyCarriesNoIndicator()
}//end class
