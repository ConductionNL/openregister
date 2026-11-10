<?php

/**
 * Behaviour tests for BulkJobGuards::assertUndoCeiling() with plans that cannot be encoded.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
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

namespace OCA\OpenRegister\Tests\Unit\Service\BulkJob;

use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkJob\BulkJobGuards;
use OCA\OpenRegister\Service\BulkJob\RehearsedJob;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\BulkJob\BulkJobGuards
 * @uses   \OCA\OpenRegister\Exception\BulkJobRefusedException
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class BulkJobGuardsUndoPlanTest extends TestCase
{
    /**
     * An action whose undo plan for the n-th object is the n-th given plan.
     */
    private function action(array $plans): ReversibleBulkActionInterface
    {
        $action = $this->createMock(ReversibleBulkActionInterface::class);
        $action->method('reversalPlanFor')->willReturnOnConsecutiveCalls(...$plans);
        return $action;
    }//end action()

    public function testAPlanThatCannotBeEncodedDoesNotCountTowardsTheCeiling(): void
    {
        $huge = ['note' => str_repeat("\xB1", 500)];
        $guards = new BulkJobGuards(64);

        $guards->assertUndoCeiling(new RehearsedJob($this->action([$huge, ['tag' => 'a']]), [new ObjectEntity(), new ObjectEntity()], []));

        $this->expectException(BulkJobRefusedException::class);
        $guards->assertUndoCeiling(new RehearsedJob($this->action([['note' => str_repeat('x', 500)]]), [new ObjectEntity()], []));
    }//end testAPlanThatCannotBeEncodedDoesNotCountTowardsTheCeiling()
}//end class
