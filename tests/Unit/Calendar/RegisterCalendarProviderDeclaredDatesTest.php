<?php

/**
 * Unit tests for RegisterCalendarProvider with declared object dates and saved calendar views.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarDefinition;
use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Calendar\DeclaredDateCalendars;
use OCA\OpenRegister\Calendar\ObjectDateCalendar;
use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Calendar\RegisterCalendarProvider;
use OCA\OpenRegister\Calendar\RegisterCalendarSources;
use OCA\OpenRegister\Calendar\VeventSearchShape;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedSources;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterCalendarProviderDeclaredDatesTest extends TestCase {

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * @var CalendarEventTransformer&MockObject
	 */
	private CalendarEventTransformer $transformer;

	/**
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * @var ViewMapperInterface&MockObject
	 */
	private ViewMapperInterface $views;

	/**
	 * @var ObjectCalendarFeedService&MockObject
	 */
	private ObjectCalendarFeedService $feed;

	private RegisterCalendarSources $sources;

	private RegisterCalendarProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->magicMapper    = $this->createMock(MagicMapper::class);
		$this->transformer    = $this->createMock(CalendarEventTransformer::class);
		$this->objects        = $this->createMock(ObjectOperationsInterface::class);
		$this->views          = $this->createMock(ViewMapperInterface::class);
		$this->feed           = $this->createMock(ObjectCalendarFeedService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['alice', $user]]);

		$this->objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());

		$this->sources = new RegisterCalendarSources(
			new SchemaCatalog($this->registerMapper, $this->schemaMapper, $this->magicMapper),
			$this->transformer,
			$this->createMock(LoggerInterface::class)
		);

		$this->provider = new RegisterCalendarProvider(
			$this->sources,
			new DeclaredDateCalendars(
				$this->feed,
				new ObjectCalendarFeedSources($this->objects, $this->createMock(\OCA\OpenRegister\Db\SchemaMapperInterface::class), $this->views, $users),
				new VeventSearchShape(),
				$this->createMock(LoggerInterface::class)
			)
		);
	}

	private function schema(int $id, array $calendarProvider): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('Schema ' . $id);
		$schema->setConfiguration(['calendarProvider' => $calendarProvider]);

		return $schema;
	}

	public function testASchemaDeclaringNoDateKindsKeepsTodaysVirtualCalendar(): void {
		$config = ['enabled' => true, 'dtstart' => 'datum', 'titleTemplate' => '{{naam}}'];
		$schema = $this->schema(42, $config);
		$this->schemaMapper->method('findAll')->willReturn([$schema]);
		$this->views->method('findAll')->willReturn([]);

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([42]);
		$this->registerMapper->method('findAll')->willReturn([$register]);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject(['naam' => 'Overleg', 'datum' => '2026-03-02']);
		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([$object]);

		$event = ['id' => 'openregister-42-obj-1', 'type' => 'VEVENT', 'objects' => [['SUMMARY' => ['Overleg', []]]]];
		$this->transformer->method('transform')->willReturn($event);

		// The feed's generator and the object search path are never asked.
		$this->feed->expects($this->never())->method('eventsFor');
		$this->objects->expects($this->never())->method('searchObjects');

		$calendars = $this->provider->getCalendars('principals/users/alice');

		$this->assertCount(1, $calendars);
		$this->assertInstanceOf(RegisterCalendar::class, $calendars[0]);
		$this->assertSame('openregister-schema-42', $calendars[0]->getUri());

		$expected = new RegisterCalendar(
			new CalendarDefinition($schema, (array)$schema->getCalendarProviderConfig()),
			'principals/users/alice',
			$this->sources
		);
		$this->assertSame($expected->search(), $calendars[0]->search());
		$this->assertSame([$event], $calendars[0]->search());
		$this->assertSame($expected->getDisplayName(), $calendars[0]->getDisplayName());
		$this->assertSame($expected->getDisplayColor(), $calendars[0]->getDisplayColor());
	}

	public function testASchemaDeclaringDateKindsBecomesAGeneratedCalendarUnderTheSameUri(): void {
		$this->schemaMapper->method('findAll')->willReturn([
			$this->schema(
				9,
				[
					'enabled' => true,
					'dtstart' => 'beslistermijn',
					'titleTemplate' => '{{title}}',
					'displayName' => 'Termijnen',
					'dates' => ['beslistermijn' => ['kind' => 'deadline', 'alarmOffsetDays' => 2]],
				]
			),
		]);
		$this->views->method('findAll')->willReturn([]);
		$this->transformer->expects($this->never())->method('transform');

		$calendars = $this->provider->getCalendars('principals/users/alice');

		$this->assertCount(1, $calendars);
		$this->assertInstanceOf(ObjectDateCalendar::class, $calendars[0]);
		$this->assertSame('openregister-schema-9', $calendars[0]->getUri());
		$this->assertSame('Termijnen', $calendars[0]->getDisplayName());
		$this->assertFalse($calendars[0]->isWritable());
	}

	public function testSavedCalendarViewsAreListedAndFilteredByUri(): void {
		$this->schemaMapper->method('findAll')->willReturn([
			$this->schema(42, ['enabled' => true, 'dtstart' => 'datum', 'titleTemplate' => '{{naam}}']),
		]);

		$view = new View();
		$view->setId(5);
		$view->setName('Mijn termijnen');
		$view->setPresentation(['viewType' => 'calendar']);
		$this->views->method('findAll')->with('alice')->willReturn([$view]);

		$all = $this->provider->getCalendars('principals/users/alice');
		$this->assertSame(
			['openregister-schema-42', 'openregister-view-5'],
			array_map(static fn ($calendar): string => $calendar->getUri(), $all)
		);

		$onlyView = $this->provider->getCalendars('principals/users/alice', ['openregister-view-5']);
		$this->assertCount(1, $onlyView);
		$this->assertInstanceOf(ObjectDateCalendar::class, $onlyView[0]);
		$this->assertSame('openregister-view-5', $onlyView[0]->getUri());
	}

	public function testANonUserPrincipalGetsNoCalendarsAndNoViewsAreListed(): void {
		$this->views->expects($this->never())->method('findAll');
		$this->schemaMapper->expects($this->never())->method('findAll');

		$this->assertSame([], $this->provider->getCalendars('principals/groups/admin'));
	}
}
