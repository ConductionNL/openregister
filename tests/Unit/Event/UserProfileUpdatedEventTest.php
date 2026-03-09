<?php

namespace Unit\Event;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserProfileUpdatedEventTest extends TestCase
{
    private function createEvent(
        array $oldData = [],
        array $newData = [],
        array $changes = []
    ): UserProfileUpdatedEvent {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user-123');
        return new UserProfileUpdatedEvent($user, $oldData, $newData, $changes);
    }

    public function testExtendsEvent(): void
    {
        $event = $this->createEvent();
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testGetUser(): void
    {
        $user = $this->createMock(IUser::class);
        $event = new UserProfileUpdatedEvent($user, [], [], []);
        $this->assertSame($user, $event->getUser());
    }

    public function testGetUserId(): void
    {
        $event = $this->createEvent();
        $this->assertSame('test-user-123', $event->getUserId());
    }

    public function testGetOldData(): void
    {
        $oldData = ['firstName' => 'John', 'lastName' => 'Doe'];
        $event = $this->createEvent($oldData);
        $this->assertSame($oldData, $event->getOldData());
    }

    public function testGetNewData(): void
    {
        $newData = ['firstName' => 'Jane', 'lastName' => 'Doe'];
        $event = $this->createEvent([], $newData);
        $this->assertSame($newData, $event->getNewData());
    }

    public function testGetChanges(): void
    {
        $changes = ['firstName', 'displayName'];
        $event = $this->createEvent([], [], $changes);
        $this->assertSame($changes, $event->getChanges());
    }

    public function testGetEmptyData(): void
    {
        $event = $this->createEvent();
        $this->assertSame([], $event->getOldData());
        $this->assertSame([], $event->getNewData());
        $this->assertSame([], $event->getChanges());
    }

    public function testHasChangedReturnsTrue(): void
    {
        $event = $this->createEvent([], [], ['firstName', 'email']);
        $this->assertTrue($event->hasChanged('firstName'));
        $this->assertTrue($event->hasChanged('email'));
    }

    public function testHasChangedReturnsFalse(): void
    {
        $event = $this->createEvent([], [], ['firstName']);
        $this->assertFalse($event->hasChanged('lastName'));
        $this->assertFalse($event->hasChanged('email'));
    }

    public function testHasChangedWithEmptyChanges(): void
    {
        $event = $this->createEvent([], [], []);
        $this->assertFalse($event->hasChanged('firstName'));
    }

    public static function nameChangeProvider(): array
    {
        return [
            'firstName changed' => [['firstName'], true],
            'lastName changed' => [['lastName'], true],
            'middleName changed' => [['middleName'], true],
            'displayName changed' => [['displayName'], true],
            'multiple name fields' => [['firstName', 'lastName'], true],
            'name + other fields' => [['firstName', 'email'], true],
            'only email changed' => [['email'], false],
            'only phone changed' => [['phone'], false],
            'no changes' => [[], false],
            'non-name fields only' => [['email', 'phone', 'address'], false],
        ];
    }

    #[DataProvider('nameChangeProvider')]
    public function testHasNameChanges(array $changes, bool $expected): void
    {
        $event = $this->createEvent([], [], $changes);
        $this->assertSame($expected, $event->hasNameChanges());
    }
}
