<?php

/**
 * Unit tests for RegisterCalendar
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
<<<<<<< HEAD
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\Constants;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterCalendarTest extends TestCase
{
    private RegisterCalendar $calendar;
    private Schema $schema;
    private MagicMapper $magicMapper;
    private RegisterMapper $registerMapper;
    private CalendarEventTransformer $transformer;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
<<<<<<< HEAD
        $this->schema = new Schema();
        $this->schema->setId(42);
        $this->schema->setTitle('Test Schema');

=======
        $this->schema         = $this->createMock(Schema::class);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->magicMapper    = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->transformer    = $this->createMock(CalendarEventTransformer::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

<<<<<<< HEAD
=======
        $this->schema->method('getId')->willReturn(42);
        $this->schema->method('getTitle')->willReturn('Test Schema');

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $config = [
            'enabled'       => true,
            'dtstart'       => 'startdatum',
            'titleTemplate' => '{naam}',
            'displayName'   => 'Test Calendar',
            'color'         => '#FF0000',
        ];

        $this->calendar = new RegisterCalendar(
            $this->schema,
            $config,
            $this->magicMapper,
            $this->registerMapper,
            $this->transformer,
            'principals/users/admin',
            $this->logger
        );
    }

    public function testGetKeyReturnsSchemaBasedKey(): void
    {
        $this->assertSame('openregister-schema-42', $this->calendar->getKey());
    }

    public function testGetUriReturnsSchemaBasedUri(): void
    {
        $this->assertSame('openregister-schema-42', $this->calendar->getUri());
    }

    public function testGetDisplayNameReturnsConfiguredName(): void
    {
        $this->assertSame('Test Calendar', $this->calendar->getDisplayName());
    }

    public function testGetDisplayNameFallsBackToSchemaTitle(): void
    {
        $calendar = new RegisterCalendar(
            $this->schema,
            ['enabled' => true, 'dtstart' => 'd', 'titleTemplate' => '{t}'],
            $this->magicMapper,
            $this->registerMapper,
            $this->transformer,
            'principals/users/admin',
            $this->logger
        );

        $this->assertSame('Test Schema', $calendar->getDisplayName());
    }

    public function testGetDisplayColorReturnsConfiguredColor(): void
    {
        $this->assertSame('#FF0000', $this->calendar->getDisplayColor());
    }

    public function testGetDisplayColorDefaultsWhenNotConfigured(): void
    {
        $calendar = new RegisterCalendar(
            $this->schema,
            ['enabled' => true, 'dtstart' => 'd', 'titleTemplate' => '{t}'],
            $this->magicMapper,
            $this->registerMapper,
            $this->transformer,
            'principals/users/admin',
            $this->logger
        );

        $this->assertSame('#0082C9', $calendar->getDisplayColor());
    }

    public function testGetPermissionsReturnsReadOnly(): void
    {
        $this->assertSame(Constants::PERMISSION_READ, $this->calendar->getPermissions());
    }

    public function testIsDeletedReturnsFalse(): void
    {
        $this->assertFalse($this->calendar->isDeleted());
    }

    public function testSearchReturnsEmptyForInvalidPrincipal(): void
    {
        $calendar = new RegisterCalendar(
            $this->schema,
            ['enabled' => true, 'dtstart' => 'd', 'titleTemplate' => '{t}'],
            $this->magicMapper,
            $this->registerMapper,
            $this->transformer,
            'principals/groups/everyone',
            $this->logger
        );

        $result = $calendar->search('');
        $this->assertSame([], $result);
    }

    public function testSearchReturnsTransformedEvents(): void
    {
<<<<<<< HEAD
        $register = new Register();
        $register->setId(1);
        $register->setSchemas([42]);

        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object = new ObjectEntity();
=======
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);
        $register->method('getSchemas')->willReturn([42]);

        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object = $this->createMock(ObjectEntity::class);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$object]);

        $eventArray = [
            'id'           => 'openregister-42-test-uuid',
            'type'         => 'VEVENT',
            'calendar-key' => 'openregister-schema-42',
            'calendar-uri' => 'openregister-schema-42',
            'objects'      => [
                ['SUMMARY' => ['Test Event', []]],
            ],
        ];

        $this->transformer->method('transform')->willReturn($eventArray);

        $result = $this->calendar->search('');

        $this->assertCount(1, $result);
        $this->assertSame('openregister-42-test-uuid', $result[0]['id']);
    }

    public function testSearchSkipsNullTransformResults(): void
    {
<<<<<<< HEAD
        $register = new Register();
        $register->setSchemas([42]);
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object = new ObjectEntity();
=======
        $register = $this->createMock(Register::class);
        $register->method('getSchemas')->willReturn([42]);
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object = $this->createMock(ObjectEntity::class);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$object]);

        // Transformer returns null for objects with no date.
        $this->transformer->method('transform')->willReturn(null);

        $result = $this->calendar->search('');

        $this->assertCount(0, $result);
    }

    public function testSearchFiltersEventsByPattern(): void
    {
<<<<<<< HEAD
        $register = new Register();
        $register->setSchemas([42]);
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object1 = new ObjectEntity();
        $object2 = new ObjectEntity();
=======
        $register = $this->createMock(Register::class);
        $register->method('getSchemas')->willReturn([42]);
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $object1 = $this->createMock(ObjectEntity::class);
        $object2 = $this->createMock(ObjectEntity::class);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturn([$object1, $object2]);

        $this->transformer->method('transform')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 'e1', 'type' => 'VEVENT',
                    'calendar-key' => 'k', 'calendar-uri' => 'k',
                    'objects' => [['SUMMARY' => ['Matching Event', []]]],
                ],
                [
                    'id' => 'e2', 'type' => 'VEVENT',
                    'calendar-key' => 'k', 'calendar-uri' => 'k',
                    'objects' => [['SUMMARY' => ['Other Thing', []]]],
                ]
            );

        $result = $this->calendar->search('Matching');

        $this->assertCount(1, $result);
        $this->assertSame('e1', $result[0]['id']);
    }

    public function testSearchReturnsEmptyWhenNoRegistersContainSchema(): void
    {
<<<<<<< HEAD
        $register = new Register();
        $register->setSchemas([99]);  // Different schema ID
=======
        $register = $this->createMock(Register::class);
        $register->method('getSchemas')->willReturn([99]);  // Different schema ID
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $result = $this->calendar->search('');

        $this->assertSame([], $result);
    }
}
