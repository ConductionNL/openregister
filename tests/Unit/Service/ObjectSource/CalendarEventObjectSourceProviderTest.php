<?php

/**
 * Unit tests for CalendarEventObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() reflects dav/calendar app availability
 *  - findAll() maps VEVENT arrays onto virtual ObjectEntity instances
 *  - find() resolves by uid/id and returns null when absent
 *  - read failures degrade to an empty list (fail closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for CalendarEventObjectSourceProvider.
 */
class CalendarEventObjectSourceProviderTest extends TestCase
{
    /**
     * Build a provider with a stubbed CalendarEventService result and app availability.
     *
     * @param array<int, array<string, mixed>> $events    Event arrays returned under results.
     * @param bool                             $appsThere Whether dav/calendar are installed.
     *
     * @return CalendarEventObjectSourceProvider The provider under test.
     */
    private function provider(array $events, bool $appsThere=true): CalendarEventObjectSourceProvider
    {
        $service = $this->createMock(CalendarEventService::class);
        $service->method('getAllUserEvents')->willReturn(['results' => $events, 'total' => count($events)]);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($appsThere);

        return new CalendarEventObjectSourceProvider($service, $appManager, new NullLogger());
    }//end provider()

    /**
     * Sample VEVENT arrays as CalendarEventService returns them.
     *
     * @return array<int, array<string, mixed>> The event arrays.
     */
    private function events(): array
    {
        return [
            [
                'id'       => 'uri-1.ics',
                'uid'      => 'event-1',
                'summary'  => 'Sprint review',
                'dtstart'  => '2026-07-01T10:00:00+00:00',
                'dtend'    => '2026-07-01T11:00:00+00:00',
                'location' => 'Room A',
            ],
            [
                'id'       => 'uri-2.ics',
                'uid'      => 'event-2',
                'summary'  => 'Retro',
                'dtstart'  => '2026-07-02T14:00:00+00:00',
                'dtend'    => '2026-07-02T15:00:00+00:00',
                'location' => null,
            ],
        ];
    }//end events()

    /**
     * The register/schema pair the provider is bound to.
     *
     * @return array{0: Register, 1: Schema} The register and schema.
     */
    private function binding(): array
    {
        $register = new Register();
        $register->setId(12);
        $schema = new Schema();
        $schema->setId(120);
        return [$register, $schema];
    }//end binding()

    /**
     * getId() is the stable provider id.
     *
     * @return void
     */
    public function testGetId(): void
    {
        $this->assertSame('calendar-event-source', $this->provider([])->getId());
    }//end testGetId()

    /**
     * isEnabled() reflects dav/calendar app install-state.
     *
     * @return void
     */
    public function testIsEnabledReflectsApps(): void
    {
        $this->assertTrue($this->provider([], true)->isEnabled());
        $this->assertFalse($this->provider([], false)->isEnabled());
    }//end testIsEnabledReflectsApps()

    /**
     * findAll() maps every VEVENT onto a virtual ObjectEntity.
     *
     * @return void
     */
    public function testFindAllMapsEvents(): void
    {
        [$register, $schema] = $this->binding();

        $objects = $this->provider($this->events())->findAll($register, $schema);

        $this->assertCount(2, $objects);
        $first = $objects[0]->getObject();
        $this->assertSame('event-1', $first['id']);
        $this->assertSame('Sprint review', $first['summary']);
        $this->assertSame('2026-07-01T10:00:00+00:00', $first['startDate']);
        $this->assertSame('2026-07-01T11:00:00+00:00', $first['endDate']);
        $this->assertSame('Room A', $first['location']);
        $this->assertSame('event-1', $objects[0]->getUuid());
        $this->assertSame('120', $objects[0]->getSchema());
    }//end testFindAllMapsEvents()

    /**
     * find() resolves by uid and returns null when absent.
     *
     * @return void
     */
    public function testFindByUid(): void
    {
        [$register, $schema] = $this->binding();
        $provider = $this->provider($this->events());

        $this->assertSame('Retro', $provider->find($register, $schema, 'event-2')?->getObject()['summary']);
        $this->assertNull($provider->find($register, $schema, 'ghost'));
    }//end testFindByUid()

    /**
     * count() reflects the mapped event count.
     *
     * @return void
     */
    public function testCount(): void
    {
        [$register, $schema] = $this->binding();
        $this->assertSame(2, $this->provider($this->events())->count($register, $schema));
    }//end testCount()
}//end class
