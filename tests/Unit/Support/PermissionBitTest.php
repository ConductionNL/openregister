<?php

/**
 * Unit tests for PermissionBit — the one table mapping a verb onto a core bit.
 *
 * The table moved off `ObjectGrantResolver` so the hierarchy expander could
 * reach it without wiring a service that talks to live shares. These tests
 * assert the two properties the move had to preserve: every core verb still
 * resolves to the SAME bit core defines, and a verb outside core's five
 * resolves to null so the caller fails closed rather than widening.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Support;

use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Support\PermissionBit;
use OCP\Constants;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the action to permission bit table.
 */
class PermissionBitTest extends TestCase
{


    /**
     * Every core verb resolves to the bit core itself defines.
     *
     * Asserted against `Constants::` rather than against a literal, because a
     * literal would keep passing if core renumbered its bitmask.
     *
     * @return void
     */
    public function testEveryCoreVerbResolvesToCoresOwnBit(): void
    {
        $this->assertSame(Constants::PERMISSION_READ, PermissionBit::forAction(action: 'read'));
        $this->assertSame(Constants::PERMISSION_UPDATE, PermissionBit::forAction(action: 'update'));
        $this->assertSame(Constants::PERMISSION_CREATE, PermissionBit::forAction(action: 'create'));
        $this->assertSame(Constants::PERMISSION_DELETE, PermissionBit::forAction(action: 'delete'));
        $this->assertSame(Constants::PERMISSION_SHARE, PermissionBit::forAction(action: 'share'));

    }//end testEveryCoreVerbResolvesToCoresOwnBit()


    /**
     * A verb outside core's five has no bit, so the caller fails closed.
     *
     * An extension verb such as ZGW's `besluit_nemen` is enforced at the
     * endpoint that performs it, never by a share mask. Returning any bit here
     * would be the widening direction.
     *
     * @return void
     */
    public function testAnExtensionVerbHasNoBit(): void
    {
        $this->assertNull(PermissionBit::forAction(action: 'besluit_nemen'));
        $this->assertNull(PermissionBit::forAction(action: ''));
        $this->assertNull(PermissionBit::forAction(action: 'READ'));

    }//end testAnExtensionVerbHasNoBit()


    /**
     * The resolver's instance method answers from this same table.
     *
     * The point of the move was ONE table, not two. A second copy is a second
     * answer to "which bit is update", and the day they disagree an inherited
     * grant carries a verb the ancestor never had. This asserts the service
     * still delegates rather than keeping its own map.
     *
     * @return void
     */
    public function testTheGrantResolverAnswersFromTheSameTable(): void
    {
        $resolver = new ObjectGrantResolver(
            logger: $this->createMock(LoggerInterface::class),
            container: $this->createMock(ContainerInterface::class),
        );

        foreach (['read', 'update', 'create', 'delete', 'share', 'besluit_nemen'] as $verb) {
            $this->assertSame(
                PermissionBit::forAction(action: $verb),
                $resolver->permissionFor(action: $verb),
                sprintf("the service and the shared table must agree on '%s'", $verb)
            );
        }

    }//end testTheGrantResolverAnswersFromTheSameTable()


}//end class
