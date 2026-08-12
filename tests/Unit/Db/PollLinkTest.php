<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\PollLink;
use PHPUnit\Framework\TestCase;

class PollLinkTest extends TestCase {
	public function testJsonSerializeReturnsAllFields(): void {
		$link = new PollLink();
		$link->setObjectUuid('abc-123');
		$link->setRegisterId(5);
		$link->setSchemaId(7);
		$link->setPollId(42);
		$link->setPollTitle('Lunch');
		$link->setPollType('datePoll');
		$link->setDeadline(new DateTime('2026-06-01T12:00:00+00:00'));
		$link->setVoterCount(3);
		$link->setOptionCount(2);
		$link->setClosed(false);
		$link->setLinkedBy('admin');
		$link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

		$json = $link->jsonSerialize();

		$this->assertSame('abc-123', $json['objectUuid']);
		$this->assertSame(5, $json['registerId']);
		$this->assertSame(7, $json['schemaId']);
		$this->assertSame(42, $json['pollId']);
		$this->assertSame('Lunch', $json['pollTitle']);
		$this->assertSame('datePoll', $json['pollType']);
		$this->assertSame(3, $json['voterCount']);
		$this->assertSame(2, $json['optionCount']);
		$this->assertFalse($json['closed']);
		$this->assertSame('admin', $json['linkedBy']);
		$this->assertNotNull($json['deadline']);
		$this->assertNotNull($json['linkedAt']);
	}

	public function testJsonSerializeHandlesNulls(): void {
		$link = new PollLink();

		$json = $link->jsonSerialize();

		$this->assertNull($json['pollTitle']);
		$this->assertNull($json['pollType']);
		$this->assertNull($json['deadline']);
		$this->assertNull($json['voterCount']);
		$this->assertNull($json['optionCount']);
		$this->assertNull($json['linkedAt']);
		$this->assertFalse($json['closed']);
	}

	public function testSettersAndGetters(): void {
		$link = new PollLink();
		$link->setPollId(99);
		$link->setVoterCount(5);

		$this->assertSame(99, $link->getPollId());
		$this->assertSame(5, $link->getVoterCount());
	}
}
