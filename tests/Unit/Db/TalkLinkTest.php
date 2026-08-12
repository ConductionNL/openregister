<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Db\TalkLink}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-talk/tasks.md
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TalkLink;
use PHPUnit\Framework\TestCase;

class TalkLinkTest extends TestCase {
	public function testJsonSerializeReturnsAllFields(): void {
		$link = new TalkLink();
		$link->setObjectUuid('abc-123');
		$link->setRegisterId(5);
		$link->setSchemaId(7);
		$link->setRoomToken('room-tok');
		$link->setRoomId(42);
		$link->setRoomName('Test Room');
		$link->setRoomType(2);
		$link->setSubtitle('Group');
		$link->setParticipantCount(3);
		$link->setLastActivity(new DateTime('2026-05-25T11:00:00+00:00'));
		$link->setLinkedBy('admin');
		$link->setLinkedAt(new DateTime('2026-05-25T11:00:00+00:00'));

		$json = $link->jsonSerialize();

		$this->assertSame('abc-123', $json['objectUuid']);
		$this->assertSame(5, $json['registerId']);
		$this->assertSame(7, $json['schemaId']);
		$this->assertSame('room-tok', $json['roomToken']);
		$this->assertSame(42, $json['roomId']);
		$this->assertSame('Test Room', $json['roomName']);
		$this->assertSame(2, $json['roomType']);
		$this->assertSame('Group', $json['subtitle']);
		$this->assertSame(3, $json['participantCount']);
		$this->assertSame('/index.php/call/room-tok', $json['url']);
	}

	public function testJsonSerializeHandlesNulls(): void {
		$link = new TalkLink();

		$json = $link->jsonSerialize();

		$this->assertNull($json['roomToken']);
		$this->assertNull($json['roomName']);
		$this->assertNull($json['lastMessage']);
		$this->assertNull($json['lastActivity']);
		$this->assertNull($json['linkedAt']);
		$this->assertNull($json['url']);
	}

	public function testJsonSerializeDecodesLastMessageData(): void {
		$link = new TalkLink();
		$link->setLastMessageData(json_encode([
			'actor' => ['type' => 'users', 'id' => 'admin'],
			'text' => 'Hello',
			'timestamp' => 1716640000,
		]));

		$json = $link->jsonSerialize();

		$this->assertIsArray($json['lastMessage']);
		$this->assertSame('Hello', $json['lastMessage']['text']);
		$this->assertSame('admin', $json['lastMessage']['actor']['id']);
	}

	public function testJsonSerializeReturnsNullOnMalformedLastMessageData(): void {
		$link = new TalkLink();
		$link->setLastMessageData('not-json{');

		$json = $link->jsonSerialize();

		$this->assertNull($json['lastMessage']);
	}

	public function testSettersAndGetters(): void {
		$link = new TalkLink();
		$link->setRoomToken('xyz');
		$link->setRoomType(3);

		$this->assertSame('xyz', $link->getRoomToken());
		$this->assertSame(3, $link->getRoomType());
	}
}
