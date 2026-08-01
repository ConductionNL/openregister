<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\OpenRegisterFlowResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpenRegisterFlowResolverTest extends TestCase
{
    private ObjectService&MockObject $objects;

    private OpenRegisterFlowResolver $resolver;

    protected function setUp(): void
    {
        $this->objects = $this->createMock(ObjectService::class);

        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => $default
        );

        // The trigger index is read through a distributed cache. A miss on every
        // get() keeps these tests exercising the build path rather than a value
        // a previous test seeded, which is what they were written to assert.
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $this->resolver = new OpenRegisterFlowResolver(
            $this->objects,
            $config,
            $cacheFactory,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
    }

    private function flowObject(array $data): ObjectEntity
    {
        $o = new ObjectEntity();
        $o->setUuid('flow-1');
        $o->setObject($data);
        return $o;
    }

    public function testItResolvesAFlowObjectIntoADocument(): void
    {
        $this->objects->method('find')->willReturn(
            $this->flowObject(['nodes' => [['id' => 'a']], 'edges' => [['id' => 'e']]])
        );

        $doc = $this->resolver->resolveFlow('flow-1');

        $this->assertIsArray($doc);
        $this->assertSame('flow-1', $doc['id']);
        $this->assertCount(1, $doc['nodes']);
        $this->assertCount(1, $doc['edges']);
    }

    public function testAnObjectThatIsNotFlowShapedIsNotAFlow(): void
    {
        // An ordinary object in the flow store with no nodes/edges is not a flow.
        $this->objects->method('find')->willReturn($this->flowObject(['title' => 'not a flow']));

        $this->assertNull($this->resolver->resolveFlow('flow-1'));
    }

    public function testAFlowThatCannotBeLoadedResolvesNull(): void
    {
        $this->objects->method('find')->willThrowException(new RuntimeException('nope'));
        $this->assertNull($this->resolver->resolveFlow('ghost'));
    }

    public function testFlowsForTriggerReturnsOnlyEnabledMatches(): void
    {
        $match = $this->flowObject(['enabled' => true, 'trigger' => 'object.created']);
        $disabled = $this->flowObject(['enabled' => false, 'trigger' => 'object.created']);
        $otherEvent = $this->flowObject(['enabled' => true, 'trigger' => 'object.deleted']);
        $match->setUuid('yes');
        $disabled->setUuid('off');
        $otherEvent->setUuid('other');

        $this->objects->method('findAll')->willReturn([$match, $disabled, $otherEvent]);

        $ids = $this->resolver->flowsForTrigger('object.created', 'reg', 'sch');
        $this->assertSame(['yes'], $ids);
    }

    public function testATriggerRegisterScopesTheMatch(): void
    {
        $scoped = $this->flowObject([
            'enabled'         => true,
            'trigger'         => 'object.created',
            'triggerRegister' => 'sales',
        ]);
        $scoped->setUuid('scoped');
        $this->objects->method('findAll')->willReturn([$scoped]);

        // Same event on a different register does not match.
        $this->assertSame([], $this->resolver->flowsForTrigger('object.created', 'hr', 'sch'));
        // The scoped register matches.
        $this->assertSame(['scoped'], $this->resolver->flowsForTrigger('object.created', 'sales', 'sch'));
    }

    public function testNoFlowStoreYieldsNoTriggers(): void
    {
        $this->objects->method('findAll')->willThrowException(new RuntimeException('no such register'));
        $this->assertSame([], $this->resolver->flowsForTrigger('object.created', 'reg', 'sch'));
    }
}
