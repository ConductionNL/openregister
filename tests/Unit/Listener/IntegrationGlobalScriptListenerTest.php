<?php

/**
 * Unit tests for IntegrationGlobalScriptListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\IntegrationGlobalScriptListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntegrationGlobalScriptListener.
 */
class IntegrationGlobalScriptListenerTest extends TestCase
{

    /**
     * The listener under test.
     *
     * @var IntegrationGlobalScriptListener
     */
    private IntegrationGlobalScriptListener $listener;

    /**
     * Set up the listener before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new IntegrationGlobalScriptListener();
    }//end setUp()

    /**
     * Instantiating the listener should succeed with no constructor dependencies.
     *
     * @return void
     */
    public function testInstantiates(): void
    {
        $this->assertInstanceOf(
            expected: IntegrationGlobalScriptListener::class,
            actual: $this->listener
        );
    }//end testInstantiates()

    /**
     * An unrelated event must be silently ignored (no exception thrown).
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvent(): void
    {
        $event = $this->createMock(originalClassName: Event::class);

        // Should not throw.
        $this->listener->handle($event);

        $this->assertTrue(condition: true);
    }//end testIgnoresUnrelatedEvent()

    /**
     * A BeforeTemplateRenderedEvent triggers script injection without throwing.
     *
     * @return void
     */
    public function testHandlesBeforeTemplateRenderedEvent(): void
    {
        /*
         * @var TemplateResponse&MockObject $response
         */

        $response = $this->createMock(originalClassName: TemplateResponse::class);

        $event = new BeforeTemplateRenderedEvent(loggedIn: true, response: $response);

        // Handle() calls Util::addInitScript — the static call cannot be
        // asserted in unit scope, but we verify no exception is thrown.
        $this->listener->handle($event);

        $this->assertTrue(condition: true);
    }//end testHandlesBeforeTemplateRenderedEvent()

    /**
     * The listener handles the event even when no user is logged in.
     *
     * @return void
     */
    public function testHandlesEventWhenNotLoggedIn(): void
    {
        /*
         * @var TemplateResponse&MockObject $response
         */

        $response = $this->createMock(originalClassName: TemplateResponse::class);

        $event = new BeforeTemplateRenderedEvent(loggedIn: false, response: $response);

        $this->listener->handle($event);

        $this->assertTrue(condition: true);
    }//end testHandlesEventWhenNotLoggedIn()
}//end class
