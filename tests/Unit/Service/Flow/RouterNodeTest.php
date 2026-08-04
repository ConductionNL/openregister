<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class RouterNodeTest extends TestCase
{
    private RouterNode $node;

    protected function setUp(): void
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $this->node = new RouterNode($l, $this->createMock(IURLGenerator::class));
    }

    private function items(array $ns): array
    {
        return array_map(static fn (int $n): array => FlowItems::item(json: ['n' => $n]), $ns);
    }

    private function config(): array
    {
        // n > 5 -> "high", otherwise the "low" fallback.
        return [
            'rules'   => [['condition' => ['>' => [['var' => 'json.n'], 5]], 'output' => 'high']],
            'default' => 'low',
        ];
    }

    public function testItTagsEachItemForTheOutputItMatches(): void
    {
        $out = $this->node->execute($this->items([1, 7, 3]), $this->config(), []);

        $this->assertCount(3, $out);
        $this->assertSame('low', $out[0][FlowItems::OUTPUT]);
        $this->assertSame('high', $out[1][FlowItems::OUTPUT]);
        $this->assertSame('low', $out[2][FlowItems::OUTPUT]);
        // The record is carried through untouched.
        $this->assertSame(7, $out[1]['json']['n']);
    }

    public function testTheFirstMatchingRuleWins(): void
    {
        $config = [
            'rules' => [
                ['condition' => ['>' => [['var' => 'json.n'], 0]], 'output' => 'positive'],
                ['condition' => ['>' => [['var' => 'json.n'], 5]], 'output' => 'big'],
            ],
        ];

        $out = $this->node->execute($this->items([7]), $config, []);
        // 7 matches both, but the first rule (positive) wins.
        $this->assertSame('positive', $out[0][FlowItems::OUTPUT]);
    }

    public function testAnItemMatchingNothingWithNoFallbackIsDropped(): void
    {
        $config = [
            'rules' => [['condition' => ['>' => [['var' => 'json.n'], 5]], 'output' => 'high']],
        ];

        // 1 matches no rule and there is no default -> it is dropped.
        $out = $this->node->execute($this->items([1, 7]), $config, []);

        $this->assertCount(1, $out);
        $this->assertSame('high', $out[0][FlowItems::OUTPUT]);
    }

    public function testARouterNeedsAtLeastOneRule(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['rules' => []]);
    }

    public function testItIsAvailableInBothScopes(): void
    {
        $this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
        $this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
    }

    public function testTheTypeIsStable(): void
    {
        $this->assertSame('openregister.route', $this->node->getId());
    }
}
