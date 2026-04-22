<?php

/**
 * Activity Provider Unit Test
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

use OCA\OpenRegister\Activity\Provider;
use OCA\OpenRegister\Activity\ProviderSubjectHandler;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Activity Provider.
 */
class ProviderTest extends TestCase
{
    /** @var IFactory&MockObject */
    private IFactory $l10nFactory;

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;

    /** @var ProviderSubjectHandler&MockObject */
    private ProviderSubjectHandler $subjectHandler;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->l10nFactory    = $this->createMock(IFactory::class);
        $this->urlGenerator   = $this->createMock(IURLGenerator::class);
        $this->subjectHandler = $this->createMock(ProviderSubjectHandler::class);

        $this->provider = new Provider(
            $this->l10nFactory,
            $this->urlGenerator,
            $this->subjectHandler,
        );
    }

    /**
     * Create a mock IEvent.
     */
    private function mockEvent(string $app = 'openregister', string $subject = 'object_created'): IEvent
    {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn($app);
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn(['title' => 'Test']);
        $event->method('setIcon')->willReturnSelf();
        return $event;
    }

    /**
     * Test: parse() processes a valid openregister event correctly.
     */
    public function testParseHandlesValidEvent(): void
    {
        $l = $this->createMock(IL10N::class);
        $this->l10nFactory->method('get')->willReturn($l);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://example.com/icon.svg');
        $this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

        $event = $this->mockEvent();
        $event->expects($this->once())->method('setIcon');

        $this->subjectHandler->expects($this->once())->method('applySubjectText');

        $result = $this->provider->parse('en', $event);
        $this->assertSame($event, $result);
    }

    /**
     * Test: parse() throws UnknownActivityException for foreign app.
     */
    public function testParseThrowsForForeignApp(): void
    {
        $this->expectException(UnknownActivityException::class);
        $event = $this->mockEvent('files');
        $this->provider->parse('en', $event);
    }

    /**
     * Test: parse() throws UnknownActivityException for unknown subject.
     */
    public function testParseThrowsForUnknownSubject(): void
    {
        $this->expectException(UnknownActivityException::class);
        $event = $this->mockEvent('openregister', 'nonexistent_subject');
        $this->provider->parse('en', $event);
    }

    /**
     * Test: parse() handles all 9 valid subjects without throwing.
     *
     * @dataProvider validSubjectsProvider
     */
    public function testParseHandlesAllNineSubjects(string $subject): void
    {
        $l = $this->createMock(IL10N::class);
        $this->l10nFactory->method('get')->willReturn($l);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://example.com/icon.svg');
        $this->urlGenerator->method('imagePath')->willReturn('/icon.svg');

        $event = $this->mockEvent('openregister', $subject);
        $result = $this->provider->parse('en', $event);
        $this->assertSame($event, $result);
    }

    /**
     * Data provider for all 9 valid subjects.
     *
     * @return array<string, array{string}>
     */
    public static function validSubjectsProvider(): array
    {
        return [
            'object_created'   => ['object_created'],
            'object_updated'   => ['object_updated'],
            'object_deleted'   => ['object_deleted'],
            'register_created' => ['register_created'],
            'register_updated' => ['register_updated'],
            'register_deleted' => ['register_deleted'],
            'schema_created'   => ['schema_created'],
            'schema_updated'   => ['schema_updated'],
            'schema_deleted'   => ['schema_deleted'],
        ];
    }
}
