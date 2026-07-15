<?php

/**
 * LifecycleActionRegistry resolution tests (lifecycle-action-executor).
 *
 * Exercises the anti-phantom guard (mirrors LifecycleGuardRegistry):
 *  - a built-in action name resolves to its handler;
 *  - an app-registered handler resolves through the container;
 *  - a declared action naming an unregistered handler FAILS LOUDLY;
 *  - a resolved service that does not implement the interface FAILS LOUDLY.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry
 */
class LifecycleActionRegistryTest extends TestCase
{
    private ContainerInterface $container;
    private IServerContainer $serverContainer;
    private LifecycleActionRegistry $registry;

    protected function setUp(): void
    {
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->serverContainer = $this->createMock(IServerContainer::class);
        $logger                = $this->createMock(LoggerInterface::class);

        $this->registry = new LifecycleActionRegistry(
            $this->container,
            $this->serverContainer,
            $logger
        );
    }//end setUp()

    /**
     * A built-in action name (`set-fields`) resolves to its handler FQCN through
     * the OR container.
     */
    public function testBuiltinResolvesToHandler(): void
    {
        $handler = new SetFieldsAction();
        $this->container->method('get')
            ->with(SetFieldsAction::class)
            ->willReturn($handler);

        $resolved = $this->registry->resolve('set-fields');
        $this->assertInstanceOf(LifecycleActionInterface::class, $resolved);
        $this->assertSame($handler, $resolved);
    }//end testBuiltinResolvesToHandler()

    /**
     * An app-registered handler resolves by its declared action name.
     */
    public function testAppRegisteredHandlerResolves(): void
    {
        $handler = $this->createMock(LifecycleActionInterface::class);
        $this->container->method('get')
            ->with('materialise-gl-transaction')
            ->willReturn($handler);

        $this->assertSame($handler, $this->registry->resolve('materialise-gl-transaction'));
    }//end testAppRegisteredHandlerResolves()

    /**
     * FAIL LOUD: a declared action naming no registered handler throws — the
     * exact defect (silent no-op) this executor eliminates.
     */
    public function testMissingHandlerFailsLoudly(): void
    {
        $notFound = new class extends \Exception implements NotFoundExceptionInterface {
        };
        $this->container->method('get')->willThrowException($notFound);
        $this->serverContainer->method('get')->willThrowException($notFound);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is declared but no handler is registered');

        $this->registry->resolve('does-not-exist');
    }//end testMissingHandlerFailsLoudly()

    /**
     * FAIL LOUD: a resolved service that does not implement the interface throws.
     */
    public function testWrongTypeFailsLoudly(): void
    {
        $this->container->method('get')->willReturn(new \stdClass());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not implement');

        $this->registry->resolve('bad-service');
    }//end testWrongTypeFailsLoudly()
}//end class
