<?php

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\MapLink;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MapLink entity.
 */
class MapLinkTest extends TestCase
{

    private MapLink $link;

    protected function setUp(): void
    {
        $this->link = new MapLink();
    }//end setUp()

    public function testSetAndGetObjectUuid(): void
    {
        $this->link->setObjectUuid('abc-123');
        $this->assertSame('abc-123', $this->link->getObjectUuid());
    }//end testSetAndGetObjectUuid()

    public function testSetAndGetLatLon(): void
    {
        $this->link->setLat(52.3676);
        $this->link->setLon(4.9041);
        $this->assertSame(52.3676, $this->link->getLat());
        $this->assertSame(4.9041, $this->link->getLon());
    }//end testSetAndGetLatLon()

    public function testSetAndGetAddress(): void
    {
        $this->link->setAddress('Spui 1, 1012 WX Amsterdam');
        $this->assertSame('Spui 1, 1012 WX Amsterdam', $this->link->getAddress());
    }//end testSetAndGetAddress()

    public function testSetAndGetAddressSource(): void
    {
        $this->link->setAddressSource('address-geocoded');
        $this->assertSame('address-geocoded', $this->link->getAddressSource());
    }//end testSetAndGetAddressSource()

    public function testSetAndGetLinkedBy(): void
    {
        $this->link->setLinkedBy('admin');
        $this->assertSame('admin', $this->link->getLinkedBy());
    }//end testSetAndGetLinkedBy()

    public function testJsonSerializeContainsAllFields(): void
    {
        $now = new DateTime('2026-06-05T12:00:00+00:00');

        $this->link->setObjectUuid('obj-uuid-1');
        $this->link->setRegisterId(7);
        $this->link->setAddress('Stadhuis Amsterdam');
        $this->link->setLat(52.3731);
        $this->link->setLon(4.8926);
        $this->link->setAddressSource('click-placed');
        $this->link->setLinkedBy('user1');
        $this->link->setLinkedAt($now);

        $data = $this->link->jsonSerialize();

        $this->assertArrayHasKey('objectUuid', $data);
        $this->assertArrayHasKey('registerId', $data);
        $this->assertArrayHasKey('address', $data);
        $this->assertArrayHasKey('lat', $data);
        $this->assertArrayHasKey('lon', $data);
        $this->assertArrayHasKey('addressSource', $data);
        $this->assertArrayHasKey('linkedBy', $data);
        $this->assertArrayHasKey('linkedAt', $data);
        $this->assertSame('obj-uuid-1', $data['objectUuid']);
        $this->assertSame(52.3731, $data['lat']);
    }//end testJsonSerializeContainsAllFields()

    public function testJsonSerializeWithNullValues(): void
    {
        $data = $this->link->jsonSerialize();

        $this->assertNull($data['objectUuid']);
        $this->assertNull($data['lat']);
        $this->assertNull($data['lon']);
        $this->assertNull($data['linkedAt']);
    }//end testJsonSerializeWithNullValues()
}//end class
