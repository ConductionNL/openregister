<?php

/**
 * Unit tests for RegisterCalendarProvider
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Calendar\RegisterCalendarProvider;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterCalendarProviderTest extends TestCase
{
    private RegisterCalendarProvider $provider;
    private SchemaMapper $schemaMapper;
    private RegisterMapper $registerMapper;
    private MagicMapper $magicMapper;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private CalendarEventTransformer $transformer;

    protected function setUp(): void
    {
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->magicMapper    = $this->createMock(MagicMapper::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->transformer    = $this->createMock(CalendarEventTransformer::class);

        $this->provider = new RegisterCalendarProvider(
            $this->schemaMapper,
            $this->registerMapper,
            $this->magicMapper,
            $this->userSession,
            $this->logger,
            $this->transformer
        );
    }

    public function testGetCalendarsReturnsCalendarsForEnabledSchemas(): void
    {
        $schema = new Schema();
        $schema->setId(42);
        $schema->setConfiguration([
            'calendarProvider' => [
                'enabled'       => true,
                'dtstart'       => 'startdatum',
                'titleTemplate' => '{naam}',
            ],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $calendars = $this->provider->getCalendars('principals/users/admin');

        $this->assertCount(1, $calendars);
        $this->assertInstanceOf(RegisterCalendar::class, $calendars[0]);
    }

    public function testGetCalendarsWithUriFilterReturnsOnlyMatchingCalendars(): void
    {
        $schema1 = new Schema();
        $schema1->setId(1);
        $schema1->setConfiguration([
            'calendarProvider' => [
                'enabled'       => true,
                'dtstart'       => 'datum',
                'titleTemplate' => '{naam}',
            ],
        ]);

        $schema2 = new Schema();
        $schema2->setId(2);
        $schema2->setConfiguration([
            'calendarProvider' => [
                'enabled'       => true,
                'dtstart'       => 'datum',
                'titleTemplate' => '{naam}',
            ],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);

        $calendars = $this->provider->getCalendars(
            'principals/users/admin',
            ['openregister-schema-2']
        );

        $this->assertCount(1, $calendars);
        $this->assertSame('openregister-schema-2', $calendars[0]->getKey());
    }

    public function testGetCalendarsReturnsEmptyWhenNoSchemasEnabled(): void
    {
        $schema = new Schema();
        // No configuration set, so getCalendarProviderConfig() returns null.

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $calendars = $this->provider->getCalendars('principals/users/admin');

        $this->assertCount(0, $calendars);
    }

    public function testGetCalendarsReturnsEmptyForAnonymousPrincipal(): void
    {
        $calendars = $this->provider->getCalendars('principals/groups/everyone');

        $this->assertCount(0, $calendars);
    }

    public function testGetCalendarsHandlesExceptionGracefully(): void
    {
        $this->schemaMapper->method('findAll')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())
            ->method('warning');

        $calendars = $this->provider->getCalendars('principals/users/admin');

        $this->assertCount(0, $calendars);
    }

    public function testGetCalendarsCachesEnabledSchemas(): void
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setConfiguration([
            'calendarProvider' => [
                'enabled'       => true,
                'dtstart'       => 'datum',
                'titleTemplate' => '{t}',
            ],
        ]);

        // findAll should be called only once due to caching.
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$schema]);

        $this->provider->getCalendars('principals/users/admin');
        $this->provider->getCalendars('principals/users/admin');
    }
}
