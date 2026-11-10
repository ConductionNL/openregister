<?php

/**
 * Golden record of the two CalDAV-backed object sources.
 *
 * CalDavVtodoObjectSourceProvider and CalendarEventObjectSourceProvider:
 * isEnabled() over app combinations and a throwing app manager; find(),
 * findAll() and count() over default scoping, `unscoped`, `register` /
 * `schema` / `schemas` overrides, paging and status queries, rows with
 * missing keys or round-tripped fields, and a service that throws. Every
 * call to the backing service is recorded with its arguments. The recording
 * lives in fixtures/caldav-sources-golden.json; set CALDAV_SOURCES_GOLDEN_RECORD=1
 * to write it.
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
 * @spec exclude Pins the projection of the CalDAV object sources.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider;
use OCA\OpenRegister\Service\TaskService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The CalDAV object sources, recorded.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider
 * @covers \OCA\OpenRegister\Service\ObjectSource\CalendarEventObjectSourceProvider
 */
class CalDavSourcesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/caldav-sources-golden.json';

	/**
	 * Everything observed: service calls and log lines.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * A logger that records into $log.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function logger(): AbstractLogger {
		$log = &$this->log;
		return new class($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log', $level, (string)$message];
			}
		};
	}//end logger()

	/**
	 * An app manager answering from a set of installed apps, or throwing.
	 *
	 * @param list<string>|null $apps Installed apps; null throws.
	 *
	 * @return IAppManager The app manager.
	 */
	private function apps(?array $apps): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(
			static function (string $app) use ($apps): bool {
				if ($apps === null) {
					throw new RuntimeException('apps down');
				}
				return in_array($app, $apps, true);
			}
		);
		return $manager;
	}//end apps()

	/**
	 * The VTODO provider over $rows (or a throwing service when null).
	 *
	 * @param list<array<string, mixed>>|null $rows The task rows.
	 * @param list<string>|null              $apps Installed apps.
	 *
	 * @return CalDavVtodoObjectSourceProvider The provider.
	 */
	private function vtodo(?array $rows, ?array $apps = ['dav']): CalDavVtodoObjectSourceProvider {
		$service = $this->createMock(TaskService::class);
		$service->method('listUserTasks')->willReturnCallback(
			function (?string $status, ListQuery $query) use ($rows): array {
				[$limit, $offset] = [$query->limit, (int)$query->offset];
				$this->log[] = ['getAllUserTasks', $status, $limit, $offset];
				if ($rows === null) {
					throw new RuntimeException('caldav down');
				}
				return ['results' => array_slice($rows, $offset, $limit, true), 'total' => count($rows)];
			}
		);
		return new CalDavVtodoObjectSourceProvider(taskService: $service, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->apps($apps), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $this->logger()));
	}//end vtodo()

	/**
	 * The VEVENT provider over $rows (or a throwing service when null).
	 *
	 * @param list<array<string, mixed>>|null $rows The event rows.
	 * @param list<string>|null              $apps Installed apps.
	 *
	 * @return CalendarEventObjectSourceProvider The provider.
	 */
	private function vevent(?array $rows, ?array $apps = ['dav']): CalendarEventObjectSourceProvider {
		$service = $this->createMock(CalendarEventService::class);
		$service->method('getAllUserEvents')->willReturnCallback(
			function (int $limit = 200, int $offset = 0) use ($rows): array {
				$this->log[] = ['getAllUserEvents', $limit, $offset];
				if ($rows === null) {
					throw new RuntimeException('calendar down');
				}
				return ['results' => array_slice($rows, $offset, $limit, true), 'total' => count($rows)];
			}
		);
		return new CalendarEventObjectSourceProvider(calendarEventService: $service, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->apps($apps), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $this->logger()));
	}//end vevent()

	/**
	 * Task rows covering every mapped key, missing keys and round-tripped fields.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private static function tasks(): array {
		return [
			[
				'id' => 'uri-1.ics', 'uid' => 'uid-1', 'summary' => 'Minutes', 'description' => 'Follow up',
				'status' => 'needs-action', 'due' => '2026-07-01', 'completed' => null, 'priority' => 5,
				'registerId' => 3, 'schemaId' => 9,
			],
			['id' => 'uri-2.ics', 'uid' => 'uid-2', 'summary' => 'Other schema', 'registerId' => 3, 'schemaId' => 99],
			['id' => 'uri-3.ics', 'summary' => 'No uid', 'registerId' => '3', 'schemaId' => '9', 'fields' => ['assignee' => 'Ann', 'title' => 'Overridden']],
			['uid' => 'uid-4', 'registerId' => 4, 'schemaId' => 9, 'fields' => 'not-an-array'],
			['id' => 'uri-5.ics', 'uid' => 'uid-5', 'registerId' => 3, 'schemaId' => 12, 'fields' => []],
			['summary' => 'Unlinked'],
		];
	}//end tasks()

	/**
	 * Event rows covering every mapped key and missing keys.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private static function events(): array {
		return [
			['uid' => 'ev-1', 'id' => 'e1.ics', 'summary' => 'Council', 'dtstart' => '2026-01-02T10:00:00', 'dtend' => '2026-01-02T11:00:00', 'location' => 'Hall'],
			['id' => 'e2.ics', 'summary' => 42],
			['uid' => 'ev-3'],
			[],
		];
	}//end events()

	/**
	 * Flatten what a call returned.
	 *
	 * @param mixed $value An entity, a list of entities, or a scalar.
	 *
	 * @return mixed The flattened value.
	 */
	private function flat(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return [$value->getUuid(), $value->getRegister(), $value->getSchema(), $value->getObject()];
		}
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->flat($item), $value);
		}
		return $value;
	}//end flat()

	/**
	 * Run every scenario and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(9);

		$scenarios = [
			'vtodo enabled dav' => fn () => $this->vtodo([], ['dav'])->isEnabled(),
			'vtodo enabled tasks' => fn () => $this->vtodo([], ['tasks'])->isEnabled(),
			'vtodo enabled calendar' => fn () => $this->vtodo([], ['calendar'])->isEnabled(),
			'vtodo enabled none' => fn () => $this->vtodo([], [])->isEnabled(),
			'vtodo enabled throws' => fn () => $this->vtodo([], null)->isEnabled(),
			'vtodo findAll default' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema),
			'vtodo findAll unscoped' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['unscoped' => true]),
			'vtodo findAll unscoped not strictly true' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['unscoped' => 1]),
			'vtodo findAll register override' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['register' => '4']),
			'vtodo findAll schema override' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['schema' => 99]),
			'vtodo findAll schemas list' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['schemas' => ['99', 12], 'schema' => 9]),
			'vtodo findAll empty schemas falls back' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['schemas' => [], 'schema' => 12]),
			'vtodo findAll schemas not a list' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, [], ['schemas' => '99']),
			'vtodo findAll paging and status' => fn () => $this->vtodo(self::tasks())->findAll($register, $schema, ['limit' => '2', 'offset' => 1, 'filters' => ['status' => 'completed']], ['unscoped' => true]),
			'vtodo findAll service throws' => fn () => $this->vtodo(null)->findAll($register, $schema),
			'vtodo count default' => fn () => $this->vtodo(self::tasks())->count($register, $schema),
			'vtodo count unscoped' => fn () => $this->vtodo(self::tasks())->count($register, $schema, ['limit' => 3], ['unscoped' => true]),
			'vtodo find by uid' => fn () => $this->vtodo(self::tasks())->find($register, $schema, 'uid-1'),
			'vtodo find by uri' => fn () => $this->vtodo(self::tasks())->find($register, $schema, 'uri-3.ics'),
			'vtodo find out of scope' => fn () => $this->vtodo(self::tasks())->find($register, $schema, 'uid-2'),
			'vtodo find out of scope unscoped' => fn () => $this->vtodo(self::tasks())->find($register, $schema, 'uid-2', ['unscoped' => true]),
			'vtodo find empty id unscoped' => fn () => $this->vtodo(self::tasks())->find($register, $schema, '', ['unscoped' => true]),
			'vtodo find missing' => fn () => $this->vtodo(self::tasks())->find($register, $schema, 'nope'),
			'vtodo find service throws' => fn () => $this->vtodo(null)->find($register, $schema, 'uid-1'),
			'vevent enabled dav' => fn () => $this->vevent([], ['dav'])->isEnabled(),
			'vevent enabled calendar' => fn () => $this->vevent([], ['calendar'])->isEnabled(),
			'vevent enabled tasks only' => fn () => $this->vevent([], ['tasks'])->isEnabled(),
			'vevent enabled throws' => fn () => $this->vevent([], null)->isEnabled(),
			'vevent findAll' => fn () => $this->vevent(self::events())->findAll($register, $schema),
			'vevent findAll paging' => fn () => $this->vevent(self::events())->findAll($register, $schema, ['limit' => '2', 'offset' => '1'], ['unscoped' => false]),
			'vevent findAll service throws' => fn () => $this->vevent(null)->findAll($register, $schema),
			'vevent count' => fn () => $this->vevent(self::events())->count($register, $schema, ['limit' => 3]),
			'vevent find by uid' => fn () => $this->vevent(self::events())->find($register, $schema, 'ev-1'),
			'vevent find by id' => fn () => $this->vevent(self::events())->find($register, $schema, 'e1.ics'),
			'vevent find id without uid' => fn () => $this->vevent(self::events())->find($register, $schema, 'e2.ics'),
			'vevent find empty id' => fn () => $this->vevent(self::events())->find($register, $schema, ''),
			'vevent find missing' => fn () => $this->vevent(self::events())->find($register, $schema, 'nope'),
			'vevent find service throws' => fn () => $this->vevent(null)->find($register, $schema, 'ev-1'),
		];

		$recording = [];
		foreach ($scenarios as $name => $run) {
			$this->log = [];
			$result = $this->flat($run());
			$recording[$name] = ['result' => $result, 'observed' => $this->log];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every scenario matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('CALDAV_SOURCES_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
