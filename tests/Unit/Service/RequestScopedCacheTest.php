<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\RequestScopedCache;
use PHPUnit\Framework\TestCase;

class RequestScopedCacheTest extends TestCase
{
    private RequestScopedCache $cache;

    protected function setUp(): void
    {
        $this->cache = new RequestScopedCache();
    }

    // ── get / set ──

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get('ns', 'missing'));
    }

    public function testSetAndGetReturnsValue(): void
    {
        $this->cache->set('ns', 'key1', 'value1');
        $this->assertSame('value1', $this->cache->get('ns', 'key1'));
    }

    public function testSetOverwritesPreviousValue(): void
    {
        $this->cache->set('ns', 'key1', 'old');
        $this->cache->set('ns', 'key1', 'new');
        $this->assertSame('new', $this->cache->get('ns', 'key1'));
    }

    public function testDifferentNamespacesAreIsolated(): void
    {
        $this->cache->set('ns1', 'key1', 'value1');
        $this->cache->set('ns2', 'key1', 'value2');
        $this->assertSame('value1', $this->cache->get('ns1', 'key1'));
        $this->assertSame('value2', $this->cache->get('ns2', 'key1'));
    }

    public function testCanStoreNullValue(): void
    {
        $this->cache->set('ns', 'key1', null);
        // has should return true even if value is null.
        $this->assertTrue($this->cache->has('ns', 'key1'));
        $this->assertNull($this->cache->get('ns', 'key1'));
    }

    // ── has ──

    public function testHasReturnsFalseForMissingNamespace(): void
    {
        $this->assertFalse($this->cache->has('missing_ns', 'key'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->cache->set('ns', 'other', 'val');
        $this->assertFalse($this->cache->has('ns', 'missing'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('ns', 'key', 'val');
        $this->assertTrue($this->cache->has('ns', 'key'));
    }

    // ── getMultiple ──

    public function testGetMultipleReturnsFoundEntries(): void
    {
        $this->cache->set('ns', 'k1', 'v1');
        $this->cache->set('ns', 'k2', 'v2');
        $this->cache->set('ns', 'k3', 'v3');

        $result = $this->cache->getMultiple('ns', ['k1', 'k3', 'missing']);
        $this->assertSame(['k1' => 'v1', 'k3' => 'v3'], $result);
    }

    public function testGetMultipleReturnsEmptyForNoMatches(): void
    {
        $result = $this->cache->getMultiple('ns', ['missing1', 'missing2']);
        $this->assertSame([], $result);
    }

    public function testGetMultipleWithEmptyKeysArray(): void
    {
        $this->cache->set('ns', 'k1', 'v1');
        $result = $this->cache->getMultiple('ns', []);
        $this->assertSame([], $result);
    }

    // ── clear ──

    public function testClearNamespaceRemovesOnlyThatNamespace(): void
    {
        $this->cache->set('ns1', 'key', 'val1');
        $this->cache->set('ns2', 'key', 'val2');

        $this->cache->clear('ns1');

        $this->assertFalse($this->cache->has('ns1', 'key'));
        $this->assertTrue($this->cache->has('ns2', 'key'));
    }

    public function testClearAllRemovesEverything(): void
    {
        $this->cache->set('ns1', 'key', 'val1');
        $this->cache->set('ns2', 'key', 'val2');

        $this->cache->clear();

        $this->assertFalse($this->cache->has('ns1', 'key'));
        $this->assertFalse($this->cache->has('ns2', 'key'));
    }

    public function testClearNonexistentNamespaceDoesNotError(): void
    {
        $this->cache->clear('nonexistent');
        $this->assertFalse($this->cache->has('nonexistent', 'key'));
    }
}
