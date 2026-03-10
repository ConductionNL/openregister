<?php

namespace Unit\Event;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimpleCrudEventsTest extends TestCase
{
    /**
     * Created and Deleted events: single entity constructor, single getter.
     */
    public static function singleEntityEventProvider(): array
    {
        return [
            'AgentCreatedEvent' => [AgentCreatedEvent::class, Agent::class, 'getAgent'],
            'AgentDeletedEvent' => [AgentDeletedEvent::class, Agent::class, 'getAgent'],
            'ApplicationCreatedEvent' => [ApplicationCreatedEvent::class, Application::class, 'getApplication'],
            'ApplicationDeletedEvent' => [ApplicationDeletedEvent::class, Application::class, 'getApplication'],
            'ConfigurationCreatedEvent' => [ConfigurationCreatedEvent::class, Configuration::class, 'getConfiguration'],
            'ConfigurationDeletedEvent' => [ConfigurationDeletedEvent::class, Configuration::class, 'getConfiguration'],
            'ConversationCreatedEvent' => [ConversationCreatedEvent::class, Conversation::class, 'getConversation'],
            'ConversationDeletedEvent' => [ConversationDeletedEvent::class, Conversation::class, 'getConversation'],
            'ObjectCreatedEvent' => [ObjectCreatedEvent::class, ObjectEntity::class, 'getObject'],
            'ObjectDeletedEvent' => [ObjectDeletedEvent::class, ObjectEntity::class, 'getObject'],
            'OrganisationCreatedEvent' => [OrganisationCreatedEvent::class, Organisation::class, 'getOrganisation'],
            'OrganisationDeletedEvent' => [OrganisationDeletedEvent::class, Organisation::class, 'getOrganisation'],
            'RegisterCreatedEvent' => [RegisterCreatedEvent::class, Register::class, 'getRegister'],
            'RegisterDeletedEvent' => [RegisterDeletedEvent::class, Register::class, 'getRegister'],
            'SchemaCreatedEvent' => [SchemaCreatedEvent::class, Schema::class, 'getSchema'],
            'SchemaDeletedEvent' => [SchemaDeletedEvent::class, Schema::class, 'getSchema'],
            'SourceCreatedEvent' => [SourceCreatedEvent::class, Source::class, 'getSource'],
            'SourceDeletedEvent' => [SourceDeletedEvent::class, Source::class, 'getSource'],
            'ViewCreatedEvent' => [ViewCreatedEvent::class, View::class, 'getView'],
            'ViewDeletedEvent' => [ViewDeletedEvent::class, View::class, 'getView'],
        ];
    }

    /**
     * Updated events with getters: two entity constructor, getNewX and getOldX.
     */
    public static function updatedEventWithGettersProvider(): array
    {
        return [
            'AgentUpdatedEvent' => [AgentUpdatedEvent::class, Agent::class, 'getNewAgent', 'getOldAgent'],
            'ApplicationUpdatedEvent' => [ApplicationUpdatedEvent::class, Application::class, 'getNewApplication', 'getOldApplication'],
            'ConversationUpdatedEvent' => [ConversationUpdatedEvent::class, Conversation::class, 'getNewConversation', 'getOldConversation'],
            'OrganisationUpdatedEvent' => [OrganisationUpdatedEvent::class, Organisation::class, 'getNewOrganisation', 'getOldOrganisation'],
            'RegisterUpdatedEvent' => [RegisterUpdatedEvent::class, Register::class, 'getNewRegister', 'getOldRegister'],
            'SchemaUpdatedEvent' => [SchemaUpdatedEvent::class, Schema::class, 'getNewSchema', 'getOldSchema'],
            'SourceUpdatedEvent' => [SourceUpdatedEvent::class, Source::class, 'getNewSource', 'getOldSource'],
        ];
    }

    /**
     * Updated events without getters (store-only).
     */
    public static function updatedEventNoGettersProvider(): array
    {
        return [
            'ConfigurationUpdatedEvent' => [ConfigurationUpdatedEvent::class, Configuration::class],
            'ViewUpdatedEvent' => [ViewUpdatedEvent::class, View::class],
        ];
    }

    #[DataProvider('singleEntityEventProvider')]
    public function testSingleEntityExtendsEvent(string $eventClass, string $entityClass, string $getter): void
    {
        $entity = new $entityClass();
        $event = new $eventClass($entity);
        $this->assertInstanceOf(Event::class, $event);
    }

    #[DataProvider('singleEntityEventProvider')]
    public function testSingleEntityConstructAndGet(string $eventClass, string $entityClass, string $getter): void
    {
        $entity = new $entityClass();
        $event = new $eventClass($entity);
        $this->assertSame($entity, $event->$getter());
    }

    #[DataProvider('singleEntityEventProvider')]
    public function testSingleEntityGetterReturnsSameInstance(string $eventClass, string $entityClass, string $getter): void
    {
        $entity = new $entityClass();
        $event = new $eventClass($entity);
        $this->assertSame($event->$getter(), $event->$getter());
    }

    #[DataProvider('updatedEventWithGettersProvider')]
    public function testUpdatedEventExtendsEvent(string $eventClass, string $entityClass, string $newGetter, string $oldGetter): void
    {
        $newEntity = new $entityClass();
        $oldEntity = new $entityClass();
        $event = new $eventClass($newEntity, $oldEntity);
        $this->assertInstanceOf(Event::class, $event);
    }

    #[DataProvider('updatedEventWithGettersProvider')]
    public function testUpdatedEventGetNewEntity(string $eventClass, string $entityClass, string $newGetter, string $oldGetter): void
    {
        $newEntity = new $entityClass();
        $oldEntity = new $entityClass();
        $event = new $eventClass($newEntity, $oldEntity);
        $this->assertSame($newEntity, $event->$newGetter());
    }

    #[DataProvider('updatedEventWithGettersProvider')]
    public function testUpdatedEventGetOldEntity(string $eventClass, string $entityClass, string $newGetter, string $oldGetter): void
    {
        $newEntity = new $entityClass();
        $oldEntity = new $entityClass();
        $event = new $eventClass($newEntity, $oldEntity);
        $this->assertSame($oldEntity, $event->$oldGetter());
    }

    #[DataProvider('updatedEventWithGettersProvider')]
    public function testUpdatedEventNewAndOldAreDifferentInstances(string $eventClass, string $entityClass, string $newGetter, string $oldGetter): void
    {
        $newEntity = new $entityClass();
        $oldEntity = new $entityClass();
        $event = new $eventClass($newEntity, $oldEntity);
        $this->assertNotSame($event->$newGetter(), $event->$oldGetter());
    }

    #[DataProvider('updatedEventNoGettersProvider')]
    public function testUpdatedEventNoGettersExtendsEvent(string $eventClass, string $entityClass): void
    {
        $newEntity = new $entityClass();
        $oldEntity = new $entityClass();
        $event = new $eventClass($newEntity, $oldEntity);
        $this->assertInstanceOf(Event::class, $event);
    }

    /**
     * ObjectUpdatedEvent is special: getObject() (backward compat), getNewObject(), optional getOldObject().
     */
    public function testObjectUpdatedEventGetObject(): void
    {
        $newObject = new ObjectEntity();
        $oldObject = new ObjectEntity();
        $event = new ObjectUpdatedEvent($newObject, $oldObject);
        $this->assertSame($newObject, $event->getObject());
        $this->assertSame($newObject, $event->getNewObject());
        $this->assertSame($oldObject, $event->getOldObject());
    }

    public function testObjectUpdatedEventOldObjectNullByDefault(): void
    {
        $newObject = new ObjectEntity();
        $event = new ObjectUpdatedEvent($newObject);
        $this->assertSame($newObject, $event->getNewObject());
        $this->assertNull($event->getOldObject());
    }
}
