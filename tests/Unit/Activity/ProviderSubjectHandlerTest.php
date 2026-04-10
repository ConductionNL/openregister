<?php

/**
 * ProviderSubjectHandler Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Activity
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Activity;

use OCA\OpenRegister\Activity\ProviderSubjectHandler;
use OCP\Activity\IEvent;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProviderSubjectHandler.
 */
class ProviderSubjectHandlerTest extends TestCase
{
    private ProviderSubjectHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ProviderSubjectHandler();
    }

    /**
     * Create a mock l10n that returns the string as-is with sprintf applied.
     */
    private function mockL10n(): IL10N
    {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            return vsprintf($text, $params) ?: $text;
        });
        return $l;
    }

    /**
     * Test: applySubjectText sets parsed and rich subjects for object_created.
     */
    public function testApplySubjectTextObjectCreated(): void
    {
        $l     = $this->mockL10n();
        $event = $this->createMock(IEvent::class);
        $event->method('getSubject')->willReturn('object_created');
        $event->method('getObjectId')->willReturn(42);

        $event->expects($this->once())->method('setParsedSubject')->with('Object created: My Object');
        $event->expects($this->once())->method('setRichSubject');

        $this->handler->applySubjectText($event, $l, ['title' => 'My Object']);
    }

    /**
     * Test: applySubjectText sets parsed subject for register_deleted.
     */
    public function testApplySubjectTextRegisterDeleted(): void
    {
        $l     = $this->mockL10n();
        $event = $this->createMock(IEvent::class);
        $event->method('getSubject')->willReturn('register_deleted');
        $event->method('getObjectId')->willReturn(10);

        $event->expects($this->once())->method('setParsedSubject')->with('Register deleted: Test Reg');

        $this->handler->applySubjectText($event, $l, ['title' => 'Test Reg']);
    }

    /**
     * Test: applySubjectText handles empty title gracefully.
     */
    public function testApplySubjectTextEmptyTitle(): void
    {
        $l     = $this->mockL10n();
        $event = $this->createMock(IEvent::class);
        $event->method('getSubject')->willReturn('schema_updated');
        $event->method('getObjectId')->willReturn(20);

        $event->expects($this->once())->method('setParsedSubject')->with('Schema updated: ');

        $this->handler->applySubjectText($event, $l, []);
    }

    /**
     * Test: applySubjectText builds correct rich parameters.
     */
    public function testApplySubjectTextBuildRichParams(): void
    {
        $l     = $this->mockL10n();
        $event = $this->createMock(IEvent::class);
        $event->method('getSubject')->willReturn('object_created');
        $event->method('getObjectId')->willReturn(99);

        $event->expects($this->once())->method('setRichSubject')->with(
            $this->anything(),
            [
                'title' => [
                    'type' => 'highlight',
                    'id'   => '99',
                    'name' => 'Rich Test',
                ],
            ]
        );

        $this->handler->applySubjectText($event, $l, ['title' => 'Rich Test']);
    }
}
