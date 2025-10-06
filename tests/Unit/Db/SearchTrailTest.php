<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\SearchTrail;
use DateTime;
use PHPUnit\Framework\TestCase;

class SearchTrailTest extends TestCase
{
    private SearchTrail $searchTrail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->searchTrail = new SearchTrail();
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(SearchTrail::class, $this->searchTrail);
        $this->assertNull($this->searchTrail->getUuid());
        $this->assertNull($this->searchTrail->getSearchTerm());
        $this->assertNull($this->searchTrail->getUser());
        $this->assertNull($this->searchTrail->getIpAddress());
        $this->assertNull($this->searchTrail->getUserAgent());
        $this->assertNull($this->searchTrail->getCreated());
    }

    public function testUuid(): void
    {
        $uuid = 'test-uuid-123';
        $this->searchTrail->setUuid($uuid);
        $this->assertEquals($uuid, $this->searchTrail->getUuid());
    }

    public function testSearchTerm(): void
    {
        $searchTerm = 'test search';
        $this->searchTrail->setSearchTerm($searchTerm);
        $this->assertEquals($searchTerm, $this->searchTrail->getSearchTerm());
    }

    public function testUser(): void
    {
        $user = 'user123';
        $this->searchTrail->setUser($user);
        $this->assertEquals($user, $this->searchTrail->getUser());
    }

    public function testIpAddress(): void
    {
        $ipAddress = '192.168.1.1';
        $this->searchTrail->setIpAddress($ipAddress);
        $this->assertEquals($ipAddress, $this->searchTrail->getIpAddress());
    }

    public function testUserAgent(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $this->searchTrail->setUserAgent($userAgent);
        $this->assertEquals($userAgent, $this->searchTrail->getUserAgent());
    }

    public function testCreated(): void
    {
        $created = new DateTime('2024-01-01 00:00:00');
        $this->searchTrail->setCreated($created);
        $this->assertEquals($created, $this->searchTrail->getCreated());
    }

    public function testJsonSerialize(): void
    {
        $this->searchTrail->setUuid('test-uuid');
        $this->searchTrail->setSearchTerm('test search');
        $this->searchTrail->setUser('user123');
        $this->searchTrail->setIpAddress('192.168.1.1');
        
        $json = $this->searchTrail->jsonSerialize();
        
        $this->assertIsArray($json);
        $this->assertEquals('test-uuid', $json['uuid']);
        $this->assertEquals('test search', $json['searchTerm']);
        $this->assertEquals('user123', $json['user']);
        $this->assertEquals('192.168.1.1', $json['ipAddress']);
    }
}
