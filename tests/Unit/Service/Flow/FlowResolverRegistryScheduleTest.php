<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\IFlowResolver;
use OCA\OpenRegister\Service\Flow\IScheduledFlowSource;
use OCA\OpenRegister\Service\Flow\RegisterFlowResolversEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The registry gathers scheduled flows from every app that owns some.
 *
 * This is the enumeration the scheduler had never had. Event triggers went
 * through the resolvers from day one; the schedule read one hard-coded store, so
 * a flow owned by any other app could not fire — no error, no run, nothing.
 */
class FlowResolverRegistryScheduleTest extends TestCase
{

    /**
     * A registry whose resolvers are exactly the given ones.
     *
     * @param array<int, IFlowResolver> $resolvers The resolvers to contribute.
     *
     * @return FlowResolverRegistry The registry.
     */
    private function registryWith(array $resolvers): FlowResolverRegistry
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (object $event) use ($resolvers): void {
                if (($event instanceof RegisterFlowResolversEvent) === false) {
                    return;
                }

                foreach ($resolvers as $resolver) {
                    $event->registerResolver($resolver);
                }
            }
        );

        return new FlowResolverRegistry($dispatcher, $this->createMock(\Psr\Log\LoggerInterface::class));
    }//end registryWith()

    /**
     * A resolver that also sources scheduled flows.
     *
     * @param array $candidates What it reports (or a Throwable to throw).
     *
     * @return IFlowResolver&IScheduledFlowSource The source.
     */
    private function source(array $candidates): object
    {
        return new class($candidates) implements IFlowResolver, IScheduledFlowSource {
            public function __construct(private array $candidates)
            {
            }

            public function resolveFlow(string $flowId): ?array
            {
                return null;
            }

            public function resolveSubject(string $uuid, string $register, string $schema): ?object
            {
                return null;
            }

            public function flowsForTrigger(string $event, string $register, string $schema): array
            {
                return [];
            }

            public function scheduledFlows(): array
            {
                return $this->candidates;
            }
        };
    }//end source()

    /**
     * A resolver that owns only event-triggered flows.
     *
     * @return IFlowResolver The resolver.
     */
    private function plainResolver(): IFlowResolver
    {
        return new class implements IFlowResolver {
            public function resolveFlow(string $flowId): ?array
            {
                return null;
            }

            public function resolveSubject(string $uuid, string $register, string $schema): ?object
            {
                return null;
            }

            public function flowsForTrigger(string $event, string $register, string $schema): array
            {
                return [];
            }
        };
    }//end plainResolver()

    /**
     * Every app that owns scheduled flows contributes them.
     *
     * @return void
     */
    public function testItGathersScheduledFlowsFromEveryContributingApp(): void
    {
        $registry = $this->registryWith(
            [
                $this->source([['id' => 'or-flow', 'enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *']]),
                $this->source([['id' => 'leaf-flow', 'enabled' => true, 'trigger' => 'schedule', 'cron' => '*/5 * * * *']]),
            ]
        );

        $ids = array_column($registry->scheduledFlows(), 'id');

        $this->assertSame(['or-flow', 'leaf-flow'], $ids);
    }//end testItGathersScheduledFlowsFromEveryContributingApp()

    /**
     * A resolver that owns no scheduled flows is not asked and costs nothing.
     *
     * The capability is a SEPARATE interface precisely so an existing resolver
     * keeps working untouched.
     *
     * @return void
     */
    public function testAResolverWithoutTheCapabilityIsSkipped(): void
    {
        $registry = $this->registryWith([$this->plainResolver()]);

        $this->assertSame([], $registry->scheduledFlows());
    }//end testAResolverWithoutTheCapabilityIsSkipped()

    /**
     * One broken app must not stop the whole instance's schedule.
     *
     * @return void
     */
    public function testAThrowingSourceIsSkippedAndTheRestStillReport(): void
    {
        $broken = new class implements IFlowResolver, IScheduledFlowSource {
            public function resolveFlow(string $flowId): ?array
            {
                return null;
            }

            public function resolveSubject(string $uuid, string $register, string $schema): ?object
            {
                return null;
            }

            public function flowsForTrigger(string $event, string $register, string $schema): array
            {
                return [];
            }

            public function scheduledFlows(): array
            {
                throw new \RuntimeException('store is gone');
            }
        };

        $registry = $this->registryWith(
            [
                $broken,
                $this->source([['id' => 'ok', 'enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *']]),
            ]
        );

        $this->assertSame(['ok'], array_column($registry->scheduledFlows(), 'id'));
    }//end testAThrowingSourceIsSkippedAndTheRestStillReport()

    /**
     * Two apps claiming one flow id yield ONE candidate, not two runs.
     *
     * `resolveFlow()` takes the first non-null answer, so the first source is
     * also the app that would actually run the flow. Reporting the id twice
     * would fire it twice per tick — a way of breaking the no-overlap guarantee
     * (openregister#2218) that the singleton check cannot catch, because both
     * fires happen before either run starts.
     *
     * @return void
     */
    public function testADuplicatedFlowIdIsReportedOnce(): void
    {
        $registry = $this->registryWith(
            [
                $this->source([['id' => 'dup', 'enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *']]),
                $this->source([['id' => 'dup', 'enabled' => true, 'trigger' => 'schedule', 'cron' => '0 0 * * *']]),
            ]
        );

        $candidates = $registry->scheduledFlows();

        $this->assertCount(1, $candidates);
        // First source wins, so the cron is the first one's.
        $this->assertSame('* * * * *', $candidates[0]['cron']);
    }//end testADuplicatedFlowIdIsReportedOnce()

    /**
     * A candidate with no id is dropped rather than normalised into one.
     *
     * @return void
     */
    public function testACandidateWithoutAnIdIsDropped(): void
    {
        $registry = $this->registryWith(
            [
                $this->source([['enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *']]),
            ]
        );

        $this->assertSame([], $registry->scheduledFlows());
    }//end testACandidateWithoutAnIdIsDropped()
}//end class
