<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

use DateTime;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Service\TalkLink\TalkLinkFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\TalkLink\TalkLinkFactory
 */
class TalkLinkFactoryTest extends TestCase {

	public function testNewLinkCarriesObjectRoomCacheAndLinker(): void {
		$cache = [
			'roomId' => 4,
			'roomName' => 'Zaak',
			'roomType' => 2,
			'subtitle' => 'Group',
			'participantCount' => 3,
			'lastMessage' => ['text' => 'hi'],
			'lastActivity' => new DateTime('2026-01-01T00:00:00+00:00'),
		];

		$factory = new TalkLinkFactory();
		$link = $factory->newLink(['objectUuid' => 'o-1', 'registerId' => 1, 'schemaId' => 2], 'tok');
		$factory->applyCache($link, $cache);
		$this->assertSame($link, $factory->linkedBy($link, 'alice'));

		$this->assertSame(['o-1', 1, 2, 'tok'], [$link->getObjectUuid(), $link->getRegisterId(), $link->getSchemaId(), $link->getRoomToken()]);
		$this->assertSame(['Zaak', 2, 3, '{"text":"hi"}'], [$link->getRoomName(), $link->getRoomType(), $link->getParticipantCount(), $link->getLastMessageData()]);
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertInstanceOf(DateTime::class, $link->getLinkedAt());
	}

	public function testApplyCacheClearsTheLastMessageWhenThereIsNone(): void {
		$link = new TalkLink();
		$link->setLastMessageData('{"old":true}');

		(new TalkLinkFactory())->applyCache($link, ['roomId' => null, 'roomName' => null, 'roomType' => null, 'subtitle' => null, 'participantCount' => null, 'lastMessage' => null, 'lastActivity' => null]);

		$this->assertNull($link->getLastMessageData());
	}
}
