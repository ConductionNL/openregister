<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditHashService.
 */
class AuditHashServiceTest extends TestCase
{
    private AuditHashService $service;
    private IDBConnection&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db      = $this->createMock(IDBConnection::class);
        $this->service = new AuditHashService($this->db);
    }

    public function testGetGenesisHash(): void
    {
        $expected = hash('sha256', 'openregister-genesis-v1');
        $result   = $this->service->getGenesisHash();

        $this->assertSame($expected, $result);
        $this->assertSame(64, strlen($result));
    }

    public function testGetGenesisHashIsConsistent(): void
    {
        $first  = $this->service->getGenesisHash();
        $second = $this->service->getGenesisHash();

        $this->assertSame($first, $second);
    }

    public function testGetCanonicalJsonExcludesHashFields(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('test-uuid');
        $entry->setAction('create');
        $entry->setHash('somehash');
        $entry->setPreviousHash('prevhash');

        $json = $this->service->getCanonicalJson($entry);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('hash', $data);
        $this->assertArrayNotHasKey('previousHash', $data);
        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayHasKey('action', $data);
    }

    public function testGetCanonicalJsonHasSortedKeys(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('test-uuid');
        $entry->setAction('create');
        $entry->setUser('admin');

        $json = $this->service->getCanonicalJson($entry);
        $data = json_decode($json, true);
        $keys = array_keys($data);

        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    public function testComputeHashReturns64CharHex(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('test-uuid');
        $entry->setAction('create');

        $previousHash = $this->service->getGenesisHash();
        $hash         = $this->service->computeHash($entry, $previousHash);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testComputeHashIsDeterministic(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('test-uuid');
        $entry->setAction('create');

        $previousHash = $this->service->getGenesisHash();
        $hash1        = $this->service->computeHash($entry, $previousHash);
        $hash2        = $this->service->computeHash($entry, $previousHash);

        $this->assertSame($hash1, $hash2);
    }

    public function testComputeHashDiffersWithDifferentPreviousHash(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('test-uuid');
        $entry->setAction('create');

        $hash1 = $this->service->computeHash($entry, 'aaaa');
        $hash2 = $this->service->computeHash($entry, 'bbbb');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testComputeHashDiffersWithDifferentEntryData(): void
    {
        $previousHash = $this->service->getGenesisHash();

        $entry1 = new AuditTrail();
        $entry1->setUuid('uuid-1');
        $entry1->setAction('create');

        $entry2 = new AuditTrail();
        $entry2->setUuid('uuid-2');
        $entry2->setAction('update');

        $hash1 = $this->service->computeHash($entry1, $previousHash);
        $hash2 = $this->service->computeHash($entry2, $previousHash);

        $this->assertNotSame($hash1, $hash2);
    }

    public function testGetLastHashReturnsGenesisWhenEmpty(): void
    {
        $qb     = $this->createMock(IQueryBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false);

        $hash = $this->service->getLastHash();

        $this->assertSame($this->service->getGenesisHash(), $hash);
    }

    public function testGetLastHashReturnsStoredHash(): void
    {
        $qb     = $this->createMock(IQueryBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(['hash' => 'abc123def456']);

        $hash = $this->service->getLastHash();

        $this->assertSame('abc123def456', $hash);
    }
}
