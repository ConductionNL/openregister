<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\BookmarkLink;
use PHPUnit\Framework\TestCase;

class BookmarkLinkTest extends TestCase
{
    public function testJsonSerializeReturnsAllFields(): void
    {
        $link = new BookmarkLink();
        $link->setObjectUuid('abc-123');
        $link->setRegisterId(5);
        $link->setSchemaId(7);
        $link->setBookmarkId(42);
        $link->setTitle('Conduction');
        $link->setUrl('https://conduction.nl');
        $link->setDescription('Company site');
        $link->setTags(['reference', 'vendor']);
        $link->setAddedAt(new DateTime('2026-06-01T12:00:00+00:00'));
        $link->setLinkedBy('admin');
        $link->setLinkedAt(new DateTime('2026-03-25T11:00:00+00:00'));

        $json = $link->jsonSerialize();

        $this->assertSame('abc-123', $json['objectUuid']);
        $this->assertSame(5, $json['registerId']);
        $this->assertSame(7, $json['schemaId']);
        $this->assertSame(42, $json['bookmarkId']);
        $this->assertSame('Conduction', $json['title']);
        $this->assertSame('https://conduction.nl', $json['url']);
        $this->assertSame('Company site', $json['description']);
        $this->assertSame(['reference', 'vendor'], $json['tags']);
        $this->assertSame('admin', $json['linkedBy']);
        $this->assertNotNull($json['addedAt']);
        $this->assertNotNull($json['linkedAt']);
    }

    public function testJsonSerializeHandlesNulls(): void
    {
        $link = new BookmarkLink();

        $json = $link->jsonSerialize();

        $this->assertNull($json['title']);
        $this->assertNull($json['url']);
        $this->assertNull($json['description']);
        $this->assertSame([], $json['tags']);
        $this->assertNull($json['addedAt']);
        $this->assertNull($json['linkedAt']);
    }

    public function testSettersAndGetters(): void
    {
        $link = new BookmarkLink();
        $link->setBookmarkId(99);
        $link->setTitle('Docs');

        $this->assertSame(99, $link->getBookmarkId());
        $this->assertSame('Docs', $link->getTitle());
    }
}
