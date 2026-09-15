<?php

/**
 * Unit tests for ObjectCalendarFeedService.
 *
 * THE LEAST-PRIVILEGED PROBE LIVES HERE. A feed is a durable, session-less
 * read surface, so the test that matters most is not "does it render" but
 * "whose eyes does it render through". Two assertions carry that: the render
 * runs inside runAs() for the principal the token names, and the object query
 * it issues carries RBAC and multi-tenancy on. A feed that quietly dropped
 * either would look, from every other test, exactly like one that works.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTimeZone;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Calendar\IcalendarWriter;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Calendar\ObjectDateEventBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ObjectCalendarFeedServiceTest extends TestCase {

	private ObjectService&MockObject $objects;
	private SchemaMapper&MockObject $schemas;
	private ViewMapper&MockObject $views;
	private IUserManager&MockObject $userManager;
	private IDateTimeZone&MockObject $dateTimeZone;
	private ObjectDateEventBuilder&MockObject $eventBuilder;
	private ObjectCalendarFeedService $service;

	/**
	 * The arguments the last searchObjects() call carried.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $searchCall = null;

	/**
	 * The user runAs() was handed, if any.
	 *
	 * @var IUser|null
	 */
	private ?IUser $ranAs = null;

	/**
	 * What the mocked search answers with.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $objectsToReturn = [];

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->views = $this->createMock(ViewMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->dateTimeZone = $this->createMock(IDateTimeZone::class);
		$this->eventBuilder = $this->createMock(ObjectDateEventBuilder::class);

		$this->dateTimeZone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$this->objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->ranAs = $user;
				return $operation();
			}
		);

		$this->objects->method('searchObjects')->willReturnCallback(
			function (
				array $query = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
				?array $ids = null,
				?string $uses = null,
				?array $views = null,
			): array {
				$this->searchCall = [
					'query' => $query,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'views' => $views,
				];

				return $this->objectsToReturn;
			}
		);

		$this->service = new ObjectCalendarFeedService(
			objects: $this->objects,
			schemas: $this->schemas,
			views: $this->views,
			userManager: $this->userManager,
			dateTimeZone: $this->dateTimeZone,
			eventBuilder: $this->eventBuilder,
			writer: new IcalendarWriter(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}

	private function token(string $scopeType = CalendarFeedToken::SCOPE_SCHEMA, string $scopeId = '9'): CalendarFeedToken {
		$token = new CalendarFeedToken();
		$token->setToken('feed-token');
		$token->setUserId('caseworker');
		$token->setScopeType($scopeType);
		$token->setScopeId($scopeId);

		return $token;
	}

	private function calendarSchema(array $dates): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Bezwaren');
		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'beslistermijn',
					'titleTemplate' => '{{title}}',
					'dates' => $dates,
				],
			]
		);

		return $schema;
	}

	private function anObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setObject(['title' => 'Bezwaar', 'beslistermijn' => '2026-10-20']);

		return $object;
	}

	private function aUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	public function testTheFeedIsRenderedAsThePrincipalTheTokenNames(): void {
		$user = $this->aUser('caseworker');
		$this->userManager->method('get')->with('caseworker')->willReturn($user);
		$this->schemas->method('find')->willReturn($this->calendarSchema(['beslistermijn' => ['kind' => 'deadline']]));
		$this->objectsToReturn = [$this->anObject()];
		$this->eventBuilder->method('build')->willReturn(
			['lines' => ['BEGIN:VEVENT', 'UID:one', 'END:VEVENT'], 'years' => [2026, 2026]]
		);

		$body = $this->service->render(token: $this->token());

		$this->assertNotNull($body);
		$this->assertSame($user, $this->ranAs, 'The feed must render inside runAs() for the token holder.');
	}

	public function testTheObjectQueryCarriesRbacAndMultiTenancy(): void {
		$this->userManager->method('get')->willReturn($this->aUser('caseworker'));
		$this->schemas->method('find')->willReturn($this->calendarSchema(['beslistermijn' => ['kind' => 'deadline']]));
		$this->objectsToReturn = [];

		$this->service->render(token: $this->token());

		$this->assertNotNull($this->searchCall, 'The feed must go through the object search path.');
		$this->assertTrue($this->searchCall['_rbac'], 'A feed that drops RBAC lists objects its holder may not read.');
		$this->assertTrue($this->searchCall['_multitenancy']);
		$this->assertSame(['schema' => '9'], $this->searchCall['query']['@self']);
	}

	public function testAFeedOverASavedViewCarriesTheViewsFilters(): void {
		$view = new View();
		$view->setUuid('view-uuid');
		$view->setName('Mijn openstaande zaken');

		$this->userManager->method('get')->willReturn($this->aUser('caseworker'));
		$this->views->method('find')->with('view-uuid')->willReturn($view);
		$this->objectsToReturn = [];

		$body = $this->service->render(
			token: $this->token(scopeType: CalendarFeedToken::SCOPE_VIEW, scopeId: 'view-uuid')
		);

		$this->assertNotNull($body);
		$this->assertSame(['view-uuid'], $this->searchCall['views']);
		$this->assertArrayNotHasKey('@self', $this->searchCall['query']);
		$this->assertStringContainsString('X-WR-CALNAME:Mijn openstaande zaken', $body);
	}

	public function testAnUnresolvablePrincipalAnswersNothingRatherThanTheRegister(): void {
		$this->userManager->method('get')->willReturn(null);

		$this->assertNull($this->service->render(token: $this->token()));
		$this->assertNull($this->searchCall, 'No object query may run for a principal that does not resolve.');
	}

	public function testASchemaThatDeclaresNoDateKindsPublishesAnEmptyCalendar(): void {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Bezwaren');
		$schema->setConfiguration([]);

		$this->userManager->method('get')->willReturn($this->aUser('caseworker'));
		$this->schemas->method('find')->willReturn($schema);
		$this->objectsToReturn = [$this->anObject()];
		$this->eventBuilder->expects($this->never())->method('build');

		$body = $this->service->render(token: $this->token());

		$this->assertNotNull($body);
		$this->assertStringNotContainsString('BEGIN:VEVENT', $body);
		$this->assertStringContainsString('BEGIN:VCALENDAR', $body);
	}

	public function testAnArchivedObjectLeavesTheFeed(): void {
		$object = $this->anObject();
		$object->setDeleted(['deleted' => '2026-09-14T10:00:00+02:00', 'deletedBy' => 'admin']);

		$this->userManager->method('get')->willReturn($this->aUser('caseworker'));
		$this->schemas->method('find')->willReturn($this->calendarSchema(['beslistermijn' => ['kind' => 'deadline']]));
		$this->objectsToReturn = [$object];
		$this->eventBuilder->expects($this->never())->method('build');

		$body = $this->service->render(token: $this->token());

		$this->assertNotNull($body);
		$this->assertStringNotContainsString('BEGIN:VEVENT', $body);
	}

	public function testAScopeThatNoLongerExistsAnswersNothing(): void {
		$this->userManager->method('get')->willReturn($this->aUser('caseworker'));
		$this->schemas->method('find')->willThrowException(new \RuntimeException('gone'));

		$this->assertNull($this->service->render(token: $this->token()));
	}
}
