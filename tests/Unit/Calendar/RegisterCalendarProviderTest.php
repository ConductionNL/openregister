<?php

/**
 * Unit tests for RegisterCalendarProvider
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Calendar\RegisterCalendarProvider;
use OCA\OpenRegister\Calendar\RegisterCalendarSources;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterCalendarProviderTest extends TestCase {
	private RegisterCalendarProvider $provider;
	private SchemaMapper $schemaMapper;
	private RegisterMapper $registerMapper;
	private MagicMapper $magicMapper;
	private LoggerInterface $logger;
	private CalendarEventTransformer $transformer;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->transformer = $this->createMock(CalendarEventTransformer::class);

		$this->provider = new RegisterCalendarProvider(
			new RegisterCalendarSources(
				new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->magicMapper),
				$this->transformer,
				$this->logger
			)
		);
	}

	public function testGetCalendarsReturnsCalendarsForEnabledSchemas(): void {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setConfiguration([
			'calendarProvider' => [
				'enabled' => true,
				'dtstart' => 'startdatum',
				'titleTemplate' => '{naam}',
			],
		]);

		$this->schemaMapper->method('findAll')->willReturn([$schema]);

		$calendars = $this->provider->getCalendars('principals/users/admin');

		$this->assertCount(1, $calendars);
		$this->assertInstanceOf(RegisterCalendar::class, $calendars[0]);
	}

	public function testGetCalendarsWithUriFilterReturnsOnlyMatchingCalendars(): void {
		$schema1 = new Schema();
		$schema1->setId(1);
		$schema1->setConfiguration([
			'calendarProvider' => [
				'enabled' => true,
				'dtstart' => 'datum',
				'titleTemplate' => '{naam}',
			],
		]);

		$schema2 = new Schema();
		$schema2->setId(2);
		$schema2->setConfiguration([
			'calendarProvider' => [
				'enabled' => true,
				'dtstart' => 'datum',
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

	public function testGetCalendarsReturnsEmptyWhenNoSchemasEnabled(): void {
		$schema = new Schema();
		// No configuration set, so getCalendarProviderConfig() returns null.

		$this->schemaMapper->method('findAll')->willReturn([$schema]);

		$calendars = $this->provider->getCalendars('principals/users/admin');

		$this->assertCount(0, $calendars);
	}

	public function testGetCalendarsReturnsEmptyForAnonymousPrincipal(): void {
		$calendars = $this->provider->getCalendars('principals/groups/everyone');

		$this->assertCount(0, $calendars);
	}

	public function testGetCalendarsHandlesExceptionGracefully(): void {
		$this->schemaMapper->method('findAll')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->once())
			->method('warning');

		$calendars = $this->provider->getCalendars('principals/users/admin');

		$this->assertCount(0, $calendars);
	}

	public function testGetCalendarsCachesEnabledSchemas(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setConfiguration([
			'calendarProvider' => [
				'enabled' => true,
				'dtstart' => 'datum',
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
	public function testASchemaLoadFailureIsLoggedOnceAndRemembered(): void {
		$failure = new \RuntimeException('DB error');
		$this->schemaMapper->expects($this->once())->method('findAll')->willThrowException($failure);
		$this->logger->expects($this->once())->method('warning')
			->with('[RegisterCalendarProvider] Failed to load schemas: DB error', ['exception' => $failure]);

		$this->assertSame([], $this->provider->getCalendars('principals/users/admin'));
		$this->assertSame([], $this->provider->getCalendars('principals/users/admin'));
	}

	public function testOnlyConfiguredSchemasInRequestedOrderBecomeCalendars(): void {
		$schemas = [];
		foreach ([3, 4, 5] as $id) {
			$schema = new Schema();
			$schema->setId($id);
			if ($id !== 4) {
				$schema->setConfiguration(['calendarProvider' => ['enabled' => true, 'dtstart' => 'datum', 'titleTemplate' => '{naam}']]);
			}

			$schemas[] = $schema;
		}

		$this->schemaMapper->method('findAll')->willReturn($schemas);

		$all = $this->provider->getCalendars('principals/users/admin');
		$picked = $this->provider->getCalendars('principals/users/admin', ['openregister-schema-5', 'openregister-schema-4', 'nope']);

		$this->assertSame(['openregister-schema-3', 'openregister-schema-5'], array_map(static fn ($c) => $c->getKey(), $all));
		$this->assertSame(['openregister-schema-5'], array_map(static fn ($c) => $c->getKey(), $picked));
		$this->assertSame([], $this->provider->getCalendars('principals/users/admin', ['openregister-schema-4']));
	}
}
