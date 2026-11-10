<?php

/**
 * Behaviour tests for CaseAdvanceNode: which realisation of a repeated plan item is advanced.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case\Nodes;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseStepRunner;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Case\CaseItemTransition;

/**
 * The plan service is a double: only the choice of row is under test here.
 */
class CaseAdvanceNodeRealisationTest extends TestCase
{
    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: string}>
     */
    public static function realisationProvider(): array
    {
        $closedLater = ['key' => 'review', 'uuid' => 'closed-2', 'isTerminal' => true, 'realisationCount' => 2];
        $openEarlier = ['key' => 'review', 'uuid' => 'open-1', 'isTerminal' => false, 'realisationCount' => 1];
        $openLater   = ['key' => 'review', 'uuid' => 'open-3', 'isTerminal' => false, 'realisationCount' => 3];
        $other       = ['key' => 'intake', 'uuid' => 'intake-1', 'isTerminal' => false];
        return [
            'open wins over a later closed one'      => [[$closedLater, $openEarlier, $other], 'open-1'],
            'a closed one never displaces open'      => [[$openEarlier, $closedLater], 'open-1'],
            'the later of two open ones'             => [[$openEarlier, $openLater, $closedLater], 'open-3'],
            'the earlier of two open ones stays out' => [[$openLater, $openEarlier], 'open-3'],
        ];
    }//end realisationProvider()

    #[DataProvider('realisationProvider')]
    public function testTheLiveRealisationOfTheKeyIsAdvanced(array $rows, string $expectedUuid): void
    {
        $plans = $this->createMock(CasePlanService::class);
        $plans->method('getPlan')->with('obj-1', 'alice')->willReturn(['items' => $rows]);
        $moved = new CaseItem();
        $moved->setState(CaseItem::STATE_COMPLETED);
        $plans->expects($this->once())
            ->method('transition')
            ->with(new CaseItemTransition(itemUuid: $expectedUuid, to: CaseItem::STATE_COMPLETED, actor: 'alice'))
            ->willReturn($moved);
        $scope = $this->createMock(FlowRunAsScope::class);
        $scope->method('call')->willReturnCallback(static fn(array $context, callable $operation): mixed => $operation());
        $session = $this->createMock(IUserSession::class);
        $node    = new CaseAdvanceNode(
            new CaseStepRunner(plans: $plans, scope: $scope, session: $session),
            new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class))
        );

        $out = $node->execute(
            [[FlowItems::JSON => ['uuid' => 'obj-1']]],
            ['item' => 'review', 'to' => CaseItem::STATE_COMPLETED],
            [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice']
        );

        $this->assertSame(['item' => 'review', 'state' => CaseItem::STATE_COMPLETED], $out[0][FlowItems::JSON]['case']);
    }//end testTheLiveRealisationOfTheKeyIsAdvanced()
}//end class
