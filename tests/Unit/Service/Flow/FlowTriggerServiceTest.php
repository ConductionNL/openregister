<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCA\OpenRegister\Service\Flow\IFlowResolver;
use OCA\OpenRegister\Service\Flow\RegisterFlowResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A resolver over an in-memory map of flows and their triggers. */
class ArrayResolver implements IFlowResolver
{
    /**
     * @param array<string, array>              $flows    flowId => document
     * @param array<string, array<int, string>> $triggers "event|register|schema" => flowIds
     */
    public function __construct(
        private array $flows = [],
        private array $triggers = []
    ) {
    }

    public function resolveFlow(string $flowId): ?array
    {
        return $this->flows[$flowId] ?? null;
    }

    public function resolveSubject(string $uuid, string $register, string $schema): ?object
    {
        return null;
    }

    public function flowsForTrigger(string $event, string $register, string $schema): array
    {
        return $this->triggers[$event.'|'.$register.'|'.$schema] ?? [];
    }
}

class FlowTriggerServiceTest extends TestCase
{
    private function registryWith(IFlowResolver ...$resolvers): FlowResolverRegistry
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use ($resolvers): void {
                if ($event instanceof RegisterFlowResolversEvent) {
                    foreach ($resolvers as $r) {
                        $event->registerResolver($r);
                    }
                }
            }
        );

        return new FlowResolverRegistry($dispatcher, new \Psr\Log\NullLogger());
    }

    public function testFiringQueuesARunForEveryWiredFlow(): void
    {
        $resolver = new ArrayResolver(
            flows: ['f1' => ['id' => 'f1'], 'f2' => ['id' => 'f2']],
            triggers: ['object.created|hermiq|agent' => ['f1', 'f2']]
        );
        $registry = $this->registryWith($resolver);

        $mapper = $this->createMock(FlowRunMapper::class);
        $mapper->method('insert')->willReturnArgument(0);
        $queued = [];
        $mapper->method('insert')->willReturnCallback(function (FlowRun $r) use (&$queued) {
            $queued[] = $r->getFlowId();
            return $r;
        });

        $runner = new FlowRunService(
            $mapper,
            $this->createMock(\OCA\OpenRegister\Service\Flow\FlowEngine::class),
            $this->createMock(\OCA\OpenRegister\Service\Flow\FlowNodeRegistry::class),
            new \Psr\Log\NullLogger()
        );

        $service = new FlowTriggerService($registry, $runner, new \Psr\Log\NullLogger());

        $count = $service->fire(
            event: 'object.created',
            subject: ['uuid' => 'u1', 'register' => 'hermiq', 'schema' => 'agent'],
            user: 'alice'
        );

        $this->assertSame(2, $count);
        $this->assertSame(['f1', 'f2'], $queued);
    }

    public function testAnEventWithNoWiredFlowQueuesNothing(): void
    {
        $registry = $this->registryWith(new ArrayResolver());
        $runner = $this->createMock(FlowRunService::class);
        $runner->expects($this->never())->method('queue');

        $service = new FlowTriggerService($registry, $runner, new \Psr\Log\NullLogger());

        $this->assertSame(0, $service->fire(event: 'object.created', subject: ['register' => 'x', 'schema' => 'y']));
    }

    /**
     * A trigger runs inside a user's save; a failure to queue must be swallowed,
     * never thrown into that action.
     */
    public function testAQueueFailureIsSwallowed(): void
    {
        $registry = $this->registryWith(
            new ArrayResolver(triggers: ['object.created||' => ['f1']])
        );
        $runner = $this->createMock(FlowRunService::class);
        $runner->method('queue')->willThrowException(new \RuntimeException('db down'));

        $service = new FlowTriggerService($registry, $runner, new \Psr\Log\NullLogger());

        // No exception escapes; returns 0.
        $this->assertSame(0, $service->fire(event: 'object.created'));
    }

    public function testTheRegistryDedupesFlowIdsAcrossResolvers(): void
    {
        $a = new ArrayResolver(triggers: ['e||' => ['shared', 'onlyA']]);
        $b = new ArrayResolver(triggers: ['e||' => ['shared', 'onlyB']]);
        $registry = $this->registryWith($a, $b);

        $ids = $registry->flowsForTrigger('e', '', '');
        sort($ids);
        $this->assertSame(['onlyA', 'onlyB', 'shared'], $ids);
    }

    public function testTheRegistryResolvesAFlowFromTheFirstOwningResolver(): void
    {
        $a = new ArrayResolver(flows: ['x' => ['id' => 'x', 'owner' => 'A']]);
        $b = new ArrayResolver(flows: ['y' => ['id' => 'y', 'owner' => 'B']]);
        $registry = $this->registryWith($a, $b);

        $this->assertSame('A', $registry->resolveFlow('x')['owner']);
        $this->assertSame('B', $registry->resolveFlow('y')['owner']);
        $this->assertNull($registry->resolveFlow('missing'));
    }

    /**
     * One resolver throwing must not stop the others from being asked.
     */
    public function testAThrowingResolverDoesNotBlockTheRest(): void
    {
        $bad = $this->createMock(IFlowResolver::class);
        $bad->method('flowsForTrigger')->willThrowException(new \RuntimeException('boom'));
        $good = new ArrayResolver(triggers: ['e||' => ['f1']]);

        $registry = $this->registryWith($bad, $good);

        $this->assertSame(['f1'], $registry->flowsForTrigger('e', '', ''));
    }
}
