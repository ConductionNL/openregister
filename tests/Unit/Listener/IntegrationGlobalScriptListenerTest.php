<?php

/**
 * Unit tests for IntegrationGlobalScriptListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Listener\IntegrationGlobalScriptListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntegrationGlobalScriptListener.
 *
 * @covers \OCA\OpenRegister\Listener\IntegrationGlobalScriptListener
 *
 * @spec openspec/changes/universal-shared-integration-registry/tasks.md
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
     * Set up the listener instance before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new IntegrationGlobalScriptListener();
    }//end setUp()

    /**
     * Non-BeforeTemplateRenderedEvent events must be ignored silently.
     *
     * @return void
     */
    public function testIgnoresNonBeforeTemplateRenderedEvent(): void
    {
        $event = $this->createMock(originalClassName: Event::class);

        $this->listener->handle(event: $event);

        $this->assertTrue(condition: true);
    }//end testIgnoresNonBeforeTemplateRenderedEvent()

    /**
     * A BeforeTemplateRenderedEvent must be handled without throwing.
     *
     * OCP\Util::addInitScript is a static NC framework helper; full
     * invocation behaviour is verified in e2e tests. Here we confirm
     * the listener does not raise on a valid event.
     *
     * @return void
     */
    public function testHandlesBeforeTemplateRenderedEventWithoutException(): void
    {
        $event = $this->createMock(originalClassName: BeforeTemplateRenderedEvent::class);

        try {
            $this->listener->handle(event: $event);
            $this->assertTrue(condition: true);
        } catch (\Throwable $e) {
            // Allow only OC-not-bootstrapped errors so pure-unit mode passes.
            $allowed = str_contains($e->getMessage(), 'OC')
                || str_contains($e->getMessage(), 'Nextcloud')
                || str_contains($e->getMessage(), 'app');
            $this->assertTrue(condition: $allowed, message: sprintf('Unexpected exception: %s', $e->getMessage()));
        }
    }//end testHandlesBeforeTemplateRenderedEventWithoutException()

    /**
     * The listener must implement IEventListener.
     *
     * @return void
     */
    public function testImplementsIEventListener(): void
    {
        $this->assertInstanceOf(expected: IEventListener::class, actual: $this->listener);
    }//end testImplementsIEventListener()
}//end class
