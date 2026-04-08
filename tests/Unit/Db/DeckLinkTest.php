<?php

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DeckLink;
use PHPUnit\Framework\TestCase;

class DeckLinkTest extends TestCase
{
    public function testJsonSerializeReturnsAllFields(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setRegisterId(5);
        $link->setBoardId(1);
        $link->setStackId(2);
        $link->setCardId(15);
        $link->setCardTitle('Test Card');
        $link->setLinkedBy('admin');
        $link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

        $json = $link->jsonSerialize();

        $this->assertSame('abc-123', $json['objectUuid']);
        $this->assertSame(1, $json['boardId']);
        $this->assertSame(2, $json['stackId']);
        $this->assertSame(15, $json['cardId']);
        $this->assertSame('Test Card', $json['cardTitle']);
    }

    public function testJsonSerializeHandlesNulls(): void
    {
        $link = new DeckLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['cardTitle']);
        $this->assertNull($json['linkedAt']);
    }

    public function testSettersAndGetters(): void
    {
        $link = new DeckLink();
        $link->setBoardId(10);
        $link->setCardId(20);

        $this->assertSame(10, $link->getBoardId());
        $this->assertSame(20, $link->getCardId());
    }
}
