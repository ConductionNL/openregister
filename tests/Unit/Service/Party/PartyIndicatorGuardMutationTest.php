<?php

/**
 * Mutation-killing tests for the guard that lets a party indicator refuse publication or sending.
 *
 * Written against the mutants Infection left alive in PartyIndicatorGuard:
 * an object linked to several parties carrying several indicators, a
 * non-refusing indicator listed before a refusing one, the refusal message
 * and code, the de-duplicated object list of a party, and a link without a
 * party that must not reach the party service at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Party
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PartyIndicatorGuardMutationTest extends TestCase {
	/**
	 * Test double or fixture for links.
	 *
	 * @var ContactLinkMapperInterface&MockObject
	 */
	private ContactLinkMapperInterface&MockObject $links;

	/**
	 * Test double or fixture for parties.
	 *
	 * @var PartyServiceInterface&MockObject
	 */
	private PartyServiceInterface&MockObject $parties;

	protected function setUp(): void {
		$this->links   = $this->createMock(ContactLinkMapperInterface::class);
		$this->parties = $this->createMock(PartyServiceInterface::class);
	}//end setUp()

	private function guard(): PartyIndicatorGuard {
		return new PartyIndicatorGuard($this->links, $this->parties);
	}//end guard()

	private function link(?string $object, ?string $party, string $kind='person', string $role='aanvrager'): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($object);
		$link->setPartyUuid($party);
		$link->setPartyKind($kind);
		$link->setRole($role);

		return $link;
	}//end link()

	private function party(string $uuid): ObjectEntity {
		$party = new ObjectEntity();
		$party->setUuid($uuid);

		return $party;
	}//end party()

	/**
	 * @param array<string, list<array<string, string>>> $indicatorsByParty Indicators keyed by party uuid.
	 *
	 * @return void
	 */
	private function partiesCarry(array $indicatorsByParty): void {
		$this->parties->method('find')
			->willReturnCallback(fn (string $uuid): ObjectEntity => $this->party($uuid));
		$this->parties->method('indicators')
			->willReturnCallback(
				static fn (ObjectEntity $party): array => ($indicatorsByParty[$party->getUuid()] ?? [])
			);
	}//end partiesCarry()

	public function testEveryIndicatorOfEveryLinkedPartyIsReturned(): void {
		$this->links->method('findPartiesForObject')->willReturn([
			$this->link('case-1', 'party-a', 'person', 'aanvrager'),
			$this->link('case-1', 'party-b', 'organisation', 'gemachtigde'),
		]);
		$this->partiesCarry([
			'party-a' => [
				['effect' => 'warn', 'label' => 'Curatele'],
				['effect' => 'refuse-send', 'label' => 'Geen post'],
			],
			'party-b' => [['effect' => 'refuse-publication', 'label' => 'Geheim adres']],
		]);

		$this->assertSame(
			[
				['party' => 'party-a', 'partyKind' => 'person', 'role' => 'aanvrager', 'effect' => 'warn', 'label' => 'Curatele'],
				['party' => 'party-a', 'partyKind' => 'person', 'role' => 'aanvrager', 'effect' => 'refuse-send', 'label' => 'Geen post'],
				['party' => 'party-b', 'partyKind' => 'organisation', 'role' => 'gemachtigde', 'effect' => 'refuse-publication', 'label' => 'Geheim adres'],
			],
			$this->guard()->indicatorsForObject('case-1')
		);
	}//end testEveryIndicatorOfEveryLinkedPartyIsReturned()

	public function testARefusingIndicatorAfterAHarmlessOneStillRefuses(): void {
		$this->links->method('findPartiesForObject')->willReturn([$this->link('case-1', 'party-a')]);
		$this->partiesCarry([
			'party-a' => [
				['effect' => 'warn', 'label' => 'Curatele'],
				['effect' => 'refuse-publication', 'label' => 'Geheim adres'],
			],
		]);

		try {
			$this->guard()->assertPublicationAllowed('case-1');
			$this->fail('Publication was allowed past a refuse-publication indicator.');
		} catch (Exception $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertSame(
				'Publication is refused by the indicator "Geheim adres" on party "party-a"',
				$e->getMessage()
			);
		}
	}//end testARefusingIndicatorAfterAHarmlessOneStillRefuses()

	public function testOnlyHarmlessIndicatorsAllowPublication(): void {
		$this->links->method('findPartiesForObject')->willReturn([$this->link('case-1', 'party-a')]);
		$this->partiesCarry(['party-a' => [['effect' => 'refuse-send', 'label' => 'Geen post']]]);

		$this->guard()->assertPublicationAllowed('case-1');

		$this->assertSame('Geen post', $this->guard()->sendRefusalFor('party-a'));
	}//end testOnlyHarmlessIndicatorsAllowPublication()

	public function testObjectsOfAPartyAreDeduplicatedAndSkipEmptyUuids(): void {
		$this->links->method('findByPartyUuid')->willReturn([
			$this->link('case-1', 'party-a'),
			$this->link(null, 'party-a'),
			$this->link('case-2', 'party-a'),
			$this->link('case-1', 'party-a', 'person', 'belanghebbende'),
			$this->link('', 'party-a'),
		]);

		$this->assertSame(['case-1', 'case-2'], $this->guard()->objectsOfParty('party-a'));
	}//end testObjectsOfAPartyAreDeduplicatedAndSkipEmptyUuids()

	public function testALinkWithoutAPartyNeverReachesThePartyService(): void {
		$this->links->method('findPartiesForObject')->willReturn([$this->link('case-1', null)]);
		$this->parties->expects($this->never())->method('find');

		$this->assertSame([], $this->guard()->indicatorsForObject('case-1'));
	}//end testALinkWithoutAPartyNeverReachesThePartyService()
}//end class
