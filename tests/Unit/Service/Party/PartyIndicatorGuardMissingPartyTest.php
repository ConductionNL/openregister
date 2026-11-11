<?php

/**
 * Missing-party tests for PartyIndicatorGuard.
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

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * A party that can no longer be found carries no indicator, and a refuse-send is
 * reported by its label.
 *
 * @covers \OCA\OpenRegister\Service\Party\PartyIndicatorGuard
 */
class PartyIndicatorGuardMissingPartyTest extends TestCase {

	/**
	 * A linked party that cannot be read contributes nothing; a readable one does.
	 *
	 * @return void
	 */
	public function testUnreadablePartyContributesNoIndicator(): void {
		$gone = new ContactLink();
		$gone->setPartyUuid('gone');
		$kept = new ContactLink();
		$kept->setPartyUuid('kept');
		$kept->setRole('aanvrager');
		$links = $this->createMock(ContactLinkMapperInterface::class);
		$links->method('findPartiesForObject')->with('case-1')->willReturn([$gone, $kept]);

		$party   = new ObjectEntity();
		$parties = $this->createMock(PartyServiceInterface::class);
		$parties->method('find')->willReturnCallback(static fn (string $partyUuid): ?ObjectEntity => $partyUuid === 'kept' ? $party : null);
		$parties->method('indicators')->with($party)->willReturn([['label' => 'Geheim', 'effect' => 'warn']]);

		$found = (new PartyIndicatorGuard(links: $links, parties: $parties))->indicatorsForObject(objectUuid: 'case-1');

		$this->assertSame([['party' => 'kept', 'partyKind' => null, 'role' => 'aanvrager', 'label' => 'Geheim', 'effect' => 'warn']], $found);
	}//end testUnreadablePartyContributesNoIndicator()

	/**
	 * No party means no send refusal; a refuse-send indicator is named by its label.
	 *
	 * @return void
	 */
	public function testSendRefusal(): void {
		$party   = new ObjectEntity();
		$parties = $this->createMock(PartyServiceInterface::class);
		$parties->method('find')->willReturnCallback(static fn (string $partyUuid): ?ObjectEntity => $partyUuid === 'p' ? $party : null);
		$parties->method('indicators')->willReturn([['label' => 'Let op', 'effect' => 'warn'], ['label' => 'Niet sturen', 'effect' => 'refuse-send']]);
		$guard = new PartyIndicatorGuard(links: $this->createMock(ContactLinkMapperInterface::class), parties: $parties);

		$this->assertNull($guard->sendRefusalFor(partyUuid: 'unknown'));
		$this->assertSame('Niet sturen', $guard->sendRefusalFor(partyUuid: 'p'));
	}//end testSendRefusal()
}//end class
