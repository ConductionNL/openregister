<?php

declare(strict_types=1);

namespace Unit\Event;

use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the reference-validation event family.
 *
 * Pure value-object tests — no DI, no service wiring. Confirms each
 * event is a Symfony/NC `Event` subclass and exposes the typed fields
 * declared by the spec ("Validation events dispatched for notification
 * and extensibility").
 */
class ReferenceValidationEventsTest extends TestCase
{
    public function testValidatedEventExtendsEvent(): void
    {
        $event = new ReferenceValidatedEvent(
            propertyName: 'organisation',
            referencedUuid: 'uuid-1',
            targetSchemaSlug: 'organisations'
        );
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testValidatedEventExposesAllFields(): void
    {
        $event = new ReferenceValidatedEvent(
            propertyName: 'organisation',
            referencedUuid: 'uuid-1',
            targetSchemaSlug: 'organisations',
            targetRegister: '42'
        );

        $this->assertSame('organisation', $event->getPropertyName());
        $this->assertSame('uuid-1', $event->getReferencedUuid());
        $this->assertSame('organisations', $event->getTargetSchemaSlug());
        $this->assertSame('42', $event->getTargetRegister());
    }

    public function testValidatedEventDefaultsTargetRegisterToNull(): void
    {
        $event = new ReferenceValidatedEvent(
            propertyName: 'organisation',
            referencedUuid: 'uuid-1',
            targetSchemaSlug: 'organisations'
        );

        $this->assertNull($event->getTargetRegister());
    }

    public function testFailedEventExtendsEvent(): void
    {
        $event = new ReferenceValidationFailedEvent(
            propertyName: 'organisation',
            referencedUuid: 'missing-uuid',
            targetSchemaSlug: 'organisations'
        );
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testFailedEventExposesAllFields(): void
    {
        $event = new ReferenceValidationFailedEvent(
            propertyName: 'organisation',
            referencedUuid: 'missing-uuid',
            targetSchemaSlug: 'organisations',
            targetRegister: '42'
        );

        $this->assertSame('organisation', $event->getPropertyName());
        $this->assertSame('missing-uuid', $event->getReferencedUuid());
        $this->assertSame('organisations', $event->getTargetSchemaSlug());
        $this->assertSame('42', $event->getTargetRegister());
    }

    public function testFailedEventDefaultsTargetRegisterToNull(): void
    {
        $event = new ReferenceValidationFailedEvent(
            propertyName: 'organisation',
            referencedUuid: 'missing-uuid',
            targetSchemaSlug: 'organisations'
        );

        $this->assertNull($event->getTargetRegister());
    }

    public function testValidatedAndFailedEventsAreDistinctTypes(): void
    {
        $valid = new ReferenceValidatedEvent(
            propertyName: 'organisation',
            referencedUuid: 'uuid-1',
            targetSchemaSlug: 'organisations'
        );
        $failed = new ReferenceValidationFailedEvent(
            propertyName: 'organisation',
            referencedUuid: 'uuid-1',
            targetSchemaSlug: 'organisations'
        );

        // Listeners must be able to subscribe to one without catching
        // the other — these are siblings, not parent/child.
        $this->assertNotInstanceOf(ReferenceValidatedEvent::class, $failed);
        $this->assertNotInstanceOf(ReferenceValidationFailedEvent::class, $valid);
    }
}
