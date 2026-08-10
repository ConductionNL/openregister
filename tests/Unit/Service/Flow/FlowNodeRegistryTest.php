<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowStartNode;
use OCA\OpenRegister\Service\Flow\IFlowStopNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/** A node that tags each item it sees. */
class TaggingNode implements IFlowNode
{
    public function __construct(
        private readonly string $id = 'test.tag',
        private readonly int $scope = IManager::SCOPE_ADMIN
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return 'Tag';
    }

    public function getDescription(): string
    {
        return 'Tags items.';
    }

    public function getIcon(): string
    {
        return 'icon.svg';
    }

    public function isAvailableForScope(int $scope): bool
    {
        return $scope === $this->scope;
    }

    public function validateConfig(array $config): void
    {
    }

    public function execute(array $items, array $config, array $context): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $json = (array) ($item['json'] ?? []);
            $json['taggedBy'] = $this->id;
            $json['label'] = (string) ($config['label'] ?? '');
            $out[] = FlowItems::item(json: $json, binary: [], fromItemIndex: $index);
        }

        return $out;
    }
}

class FlowNodeRegistryTest extends TestCase
{
    /**
     * Build a registry whose contribution event registers the given nodes.
     *
     * @param array<IFlowNode> $nodes Nodes to contribute.
     */
    private function registryWith(array $nodes, ?LoggerInterface $logger = null): FlowNodeRegistry
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $registry = new FlowNodeRegistry($dispatcher, ($logger ?? $this->createMock(LoggerInterface::class)));

        $dispatcher->method('dispatchTyped')->willReturnCallback(
            static function (Event $event) use ($nodes): void {
                if ($event instanceof RegisterFlowNodesEvent) {
                    foreach ($nodes as $node) {
                        $event->registerNode($node);
                    }
                }
            }
        );

        return $registry;
    }

    public function testAnAppContributesANodeThroughTheEvent(): void
    {
        $registry = $this->registryWith([new TaggingNode()]);

        $this->assertArrayHasKey('test.tag', $registry->all());
    }

    public function testContributionIsCollectedOnlyOncePerRequest(): void
    {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatchTyped');

        $registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
        $registry->all();
        $registry->all();
        $registry->palette();
    }

    /**
     * Two apps claiming one type must not resolve by load order — whichever
     * app's code ran would then depend on install order, which is a bug that
     * only appears on someone else's instance.
     */
    public function testADuplicateTypeIsRefusedRatherThanOverwriting(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('duplicate'), $this->anything());

        $first = new TaggingNode('test.tag');
        $second = new TaggingNode('test.tag');
        $registry = $this->registryWith([$first, $second], $logger);

        $this->assertSame($first, $registry->get('test.tag'));
    }

    public function testAnUnknownTypeThrowsRatherThanBeingSkipped(): void
    {
        $registry = $this->registryWith([]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/No app provides the flow node type "ghost.step"/');
        $registry->get('ghost.step');
    }

    public function testThePaletteIsNarrowedToTheRequestedScope(): void
    {
        $registry = $this->registryWith([
            new TaggingNode('admin.only', IManager::SCOPE_ADMIN),
            new TaggingNode('user.only', IManager::SCOPE_USER),
        ]);

        $adminIds = array_column($registry->palette(IManager::SCOPE_ADMIN), 'id');
        $userIds = array_column($registry->palette(IManager::SCOPE_USER), 'id');

        $this->assertSame(['admin.only'], $adminIds);
        $this->assertSame(['user.only'], $userIds);
    }

    public function testThePaletteCarriesTheMetadataAnEditorNeeds(): void
    {
        $registry = $this->registryWith([new TaggingNode()]);
        $entry = $registry->palette()[0];

        $this->assertSame(['id', 'displayName', 'description', 'icon', 'role'], array_keys($entry));
        $this->assertSame('Tag', $entry['displayName']);

        // `role` is ALWAYS present, so an editor never has to infer it from the
        // id. A node that marks itself neither start nor stop is a step.
        $this->assertSame('step', $entry['role']);
    }

    public function testThePaletteReportsTheRoleTheNodeDeclaresRatherThanItsId(): void
    {
        // Ids that give the naming convention nothing to match on: an editor
        // that inferred the role from the string would call both of these
        // steps.
        $start = new class implements IFlowNode, IFlowStartNode {
            public function getId(): string
            {
                return 'other.begins-here';
            }

            public function getDisplayName(): string
            {
                return 'Begins here';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getIcon(): string
            {
                return '';
            }

            public function isAvailableForScope(int $scope): bool
            {
                return true;
            }

            public function validateConfig(array $config): void
            {
            }

            public function execute(array $items, array $config, array $context): array
            {
                return $items;
            }
        };

        $stop = new class implements IFlowNode, IFlowStopNode {
            public function getId(): string
            {
                return 'other.ends-here';
            }

            public function getDisplayName(): string
            {
                return 'Ends here';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getIcon(): string
            {
                return '';
            }

            public function isAvailableForScope(int $scope): bool
            {
                return true;
            }

            public function validateConfig(array $config): void
            {
            }

            public function execute(array $items, array $config, array $context): array
            {
                return $items;
            }
        };

        $registry = $this->registryWith([$start, $stop]);
        $roles    = [];
        foreach ($registry->palette() as $entry) {
            $roles[$entry['id']] = $entry['role'];
        }

        $this->assertSame('start', $roles['other.begins-here'], 'a contributed start node was not reported as one');
        $this->assertSame('stop', $roles['other.ends-here'], 'a contributed stop node was not reported as one');
        $this->assertSame('start', $registry->roleOf('other.begins-here'));
        $this->assertSame('stop', $registry->roleOf('other.ends-here'));
        $this->assertSame('step', $registry->roleOf('not.registered.at.all'));
    }

    public function testTheDispatcherRoutesAStepToTheNodeThatOwnsItsType(): void
    {
        $registry = $this->registryWith([new TaggingNode()]);
        $dispatcher = new RegistryStepDispatcher($registry);

        $out = $dispatcher->dispatch(
            ['type' => 'test.tag', 'config' => ['label' => 'hello']],
            [FlowItems::item(json: ['a' => 1])],
            []
        );

        $this->assertSame('test.tag', $out[0]['json']['taggedBy']);
        $this->assertSame('hello', $out[0]['json']['label']);
        $this->assertSame(1, $out[0]['json']['a']);
    }

    /**
     * An edge drawn purely to shape the graph carries no work. This is NOT
     * leniency about unknown types — those still throw.
     */
    public function testAStepWithNoTypePassesItemsThrough(): void
    {
        $dispatcher = new RegistryStepDispatcher($this->registryWith([]));
        $items = [FlowItems::item(json: ['a' => 1])];

        $this->assertSame($items, $dispatcher->dispatch([], $items, []));
    }

    public function testTheDispatcherPropagatesAnUnknownType(): void
    {
        $dispatcher = new RegistryStepDispatcher($this->registryWith([]));

        $this->expectException(UnexpectedValueException::class);
        $dispatcher->dispatch(['type' => 'ghost.step'], [], []);
    }
}
