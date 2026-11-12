<?php

/**
 * Behaviour tests for BulkActionRegistry lookups and the catalogue's reversibility column.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\BulkActionRegistry
 * @uses   \OCA\OpenRegister\Event\BulkActionRegistrationEvent
 */
class BulkActionRegistryLookupTest extends TestCase
{
    private function registry(): BulkActionRegistry
    {
        return new BulkActionRegistry($this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
    }//end registry()

    public function testAnUnknownActionIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown bulk action: demo:nope');
        $this->registry()->get('demo:nope');
    }//end testAnUnknownActionIsRefusedByName()

    public function testTheCatalogueStatesTheReversalWindowOfAReversibleActionOnly(): void
    {
        $plain = $this->createMock(BulkActionInterface::class);
        $plain->method('getId')->willReturn('demo:archive');
        $plain->method('getLabel')->willReturn('Archive');
        $plain->method('getDescription')->willReturn('Archive the objects');
        $plain->method('requiresJustification')->willReturn(true);
        $plain->method('getGuards')->willReturn([]);
        $reversible = $this->createMock(ReversibleBulkActionInterface::class);
        $reversible->method('getId')->willReturn('demo:retag');
        $reversible->method('getLabel')->willReturn('Retag');
        $reversible->method('getDescription')->willReturn('Change tags');
        $reversible->method('requiresJustification')->willReturn(false);
        $reversible->method('getGuards')->willReturn(['max' => 100]);
        $reversible->method('getReversalWindow')->willReturn(86400);
        $registry = $this->registry();
        $registry->register($plain);
        $registry->register($reversible);

        $catalogue = $registry->describe();

        $this->assertSame([false, null], [$catalogue[0]['reversible'], $catalogue[0]['reversalWindow']]);
        $this->assertSame(
            ['id' => 'demo:retag', 'label' => 'Retag', 'description' => 'Change tags', 'requiresJustification' => false, 'guards' => ['max' => 100], 'reversible' => true, 'reversalWindow' => 86400],
            $catalogue[1]
        );
        $this->assertSame($reversible, $registry->get('demo:retag'));
    }//end testTheCatalogueStatesTheReversalWindowOfAReversibleActionOnly()
}//end class
