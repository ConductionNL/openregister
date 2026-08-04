<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\TranslationProjectionJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\TranslationProjectionListener;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationProjectionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener defers the heavy projection to TranslationProjectionJob for
 * schemas with translatable properties and keeps the cheap paths inline:
 * delete-time purge, the no-translatable-properties prune, and the kill
 * switch.
 */
class TranslationProjectionListenerTest extends TestCase
{
    private TranslationProjectionService&MockObject $projection;
    private SchemaMapper&MockObject $schemaMapper;
    private TranslationHandler&MockObject $translationHandler;
    private ListenerDeferralService&MockObject $deferral;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projection         = $this->createMock(TranslationProjectionService::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->translationHandler = $this->createMock(TranslationHandler::class);
        $this->deferral           = $this->createMock(ListenerDeferralService::class);
    }

    private function makeListener(): TranslationProjectionListener
    {
        return new TranslationProjectionListener(
            projection: $this->projection,
            schemaMapper: $this->schemaMapper,
            translationHandler: $this->translationHandler,
            deferral: $this->deferral,
        );
    }

    private function fixtureObject(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-1');
        $object->setRegister('test-register');
        $object->setSchema('test-schema');
        return $object;
    }

    public function testTranslatableSchemaDefersInsteadOfProjectingInline(): void
    {
        $object = $this->fixtureObject();
        $this->schemaMapper->method('find')->willReturn(new Schema());
        $this->translationHandler->method('getTranslatableProperties')->willReturn(['title']);
        $this->deferral->method('isDeferralEnabled')->willReturn(true);

        // The heavy reconciliation must NOT run on the request path.
        $this->projection->expects($this->never())->method('project');
        $this->deferral->expects($this->once())->method('defer')
            ->willReturnCallback(
                function (string $jobClass, array $entry, int $chunkSize, ?string $dedupeKey): void {
                    $this->assertSame(TranslationProjectionJob::class, $jobClass);
                    $this->assertSame('obj-1', $entry['uuid']);
                    $this->assertSame('test-register', $entry['register']);
                    $this->assertSame('test-schema', $entry['schema']);
                    // Repeat saves of one object converge to one projection.
                    $this->assertSame('obj-1', $dedupeKey);
                }
            );

        $this->makeListener()->handle(new ObjectCreatedEvent($object));
    }

    public function testUpdatedEventUsesTheNewObject(): void
    {
        $object = $this->fixtureObject();
        $this->schemaMapper->method('find')->willReturn(new Schema());
        $this->translationHandler->method('getTranslatableProperties')->willReturn(['title']);
        $this->deferral->method('isDeferralEnabled')->willReturn(true);

        $this->deferral->expects($this->once())->method('defer');
        $this->projection->expects($this->never())->method('project');

        $this->makeListener()->handle(new ObjectUpdatedEvent($object, $object));
    }

    public function testDeleteEventPurgesInline(): void
    {
        $object = $this->fixtureObject();

        // Purge stays synchronous: cheap bounded DELETE and the entity is
        // not re-fetchable once the delete lands.
        $this->projection->expects($this->once())->method('purge')->with($object);
        $this->deferral->expects($this->never())->method('defer');

        $this->makeListener()->handle(new ObjectDeletedEvent($object));
    }

    public function testSchemaWithoutTranslatablePropertiesRunsTheInlinePrune(): void
    {
        $object = $this->fixtureObject();
        $this->schemaMapper->method('find')->willReturn(new Schema());
        $this->translationHandler->method('getTranslatableProperties')->willReturn([]);
        $this->deferral->method('isDeferralEnabled')->willReturn(true);

        // Parity with pre-deferral behaviour: project() performs only the
        // stale-row prune for schemas without translatable properties.
        $this->projection->expects($this->once())->method('project')->with($object);
        $this->deferral->expects($this->never())->method('defer');

        $this->makeListener()->handle(new ObjectCreatedEvent($object));
    }

    public function testKillSwitchProjectsInline(): void
    {
        $object = $this->fixtureObject();
        $this->deferral->method('isDeferralEnabled')->willReturn(false);

        $this->projection->expects($this->once())->method('project')->with($object);
        $this->deferral->expects($this->never())->method('defer');

        $this->makeListener()->handle(new ObjectCreatedEvent($object));
    }

    public function testUnresolvableSchemaFallsBackToInlineProjection(): void
    {
        $object = $this->fixtureObject();
        $this->schemaMapper->method('find')->willThrowException(new \RuntimeException('missing'));
        $this->deferral->method('isDeferralEnabled')->willReturn(true);

        // project() re-resolves the schema itself and bails safely.
        $this->projection->expects($this->once())->method('project')->with($object);
        $this->deferral->expects($this->never())->method('defer');

        $this->makeListener()->handle(new ObjectCreatedEvent($object));
    }
}
