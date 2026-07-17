<?php

/**
 * OpenRegister SystemOperationContext unit tests
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SystemOperationContextTest extends TestCase
{
    /**
     * Outside any run() scope the context must be inactive.
     *
     * @return void
     */
    public function testInactiveByDefault(): void
    {
        $this->assertFalse(SystemOperationContext::isActive());
    }//end testInactiveByDefault()

    /**
     * Inside run() the context is active; afterwards it is released.
     *
     * @return void
     */
    public function testActiveInsideRunAndReleasedAfter(): void
    {
        $observed = null;
        $result   = SystemOperationContext::run(
            function () use (&$observed) {
                $observed = SystemOperationContext::isActive();
                return 'done';
            }
        );

        $this->assertTrue($observed);
        $this->assertSame('done', $result);
        $this->assertFalse(SystemOperationContext::isActive());
    }//end testActiveInsideRunAndReleasedAfter()

    /**
     * An exception inside the operation must not leak the elevation.
     *
     * @return void
     */
    public function testScopeReleasedOnException(): void
    {
        try {
            SystemOperationContext::run(
                function (): void {
                    throw new RuntimeException('boom');
                }
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse(SystemOperationContext::isActive());
    }//end testScopeReleasedOnException()

    /**
     * Nested scopes compose: elevation ends only when the outermost exits.
     *
     * @return void
     */
    public function testNestedScopesCompose(): void
    {
        $innerActive        = null;
        $afterInnerActive   = null;

        SystemOperationContext::run(
            function () use (&$innerActive, &$afterInnerActive): void {
                SystemOperationContext::run(
                    function () use (&$innerActive): void {
                        $innerActive = SystemOperationContext::isActive();
                    }
                );
                $afterInnerActive = SystemOperationContext::isActive();
            }
        );

        $this->assertTrue($innerActive);
        $this->assertTrue($afterInnerActive);
        $this->assertFalse(SystemOperationContext::isActive());
    }//end testNestedScopesCompose()
}//end class
