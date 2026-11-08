<?php

/**
 * Golden test for RegisterCalendar::search().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Calendar
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use DateTimeImmutable;
use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each case runs one search against scripted registers, objects and events,
 * and records the events returned plus every table query (with its filters,
 * limit and offset) and every warning. An unresolvable principal is refused
 * with no query at all. Compared with fixtures/register-calendar-search-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Calendar\RegisterCalendar
 */
class RegisterCalendarSearchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/register-calendar-search-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$start = new DateTimeImmutable('2026-03-01T00:00:00+00:00');
		$end   = new DateTimeImmutable('2026-03-31T23:59:59+01:00');
		return [
			'user principal'             => [[]],
			'group principal refused'    => [['principal' => 'principals/groups/everyone']],
			'empty principal refused'    => [['principal' => '']],
			'bare users prefix refused'  => [['principal' => 'principals/users/']],
			'nested user id'             => [['principal' => 'principals/users/a/b']],
			'timerange datetimes'        => [['options' => ['timerange' => ['start' => $start, 'end' => $end]]]],
			'timerange strings'          => [['options' => ['timerange' => ['start' => '2026-01-01', 'end' => 20260131]]]],
			'timerange start only'       => [['options' => ['timerange' => ['start' => $start]]]],
			'timerange end only'         => [['options' => ['timerange' => ['end' => '2026-02-02']]]],
			'timerange null bounds'      => [['options' => ['timerange' => ['start' => null, 'end' => null]]]],
			'timerange empty'            => [['options' => ['timerange' => []]]],
			'timerange scalar'           => [['options' => ['timerange' => 'soon']]],
			'timerange without dtstart'  => [['config' => ['displayName' => 'X'], 'options' => ['timerange' => ['start' => '2026-01-01']]]],
			'limit and offset'           => [['limit' => 5, 'offset' => 10]],
			'no matching register'       => [['registers' => [[1, [7, 8]]]]],
			'no registers at all'        => [['registers' => []]],
			'string schema id matches'   => [['registers' => [[1, ['42']], [2, [42]], [3, ['x']]]]],
			'register lookup throws'     => [['registersThrow' => true]],
			'one register query throws'  => [['registers' => [[1, [42]], [2, [42]], [3, [42]]], 'throwOn' => 2]],
			'transformer drops objects'  => [['nullEvents' => ['b']]],
			'transformer throws midway'  => [['registers' => [[1, [42]], [2, [42]]], 'transformThrows' => 'b']],
			'pattern matches summary'    => [['pattern' => 'MEET']],
			'pattern misses'             => [['pattern' => 'nothing']],
			'pattern on eventless'       => [['pattern' => 'x', 'eventShape' => 'no-objects']],
			'pattern no summary'         => [['pattern' => 'x', 'eventShape' => 'no-summary']],
			'no pattern keeps eventless' => [['eventShape' => 'no-objects']],
		];
	}

	/**
	 * @param array<string, mixed> $scenario The scripted scenario.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSearch(array $scenario): void {
		$record = ['queries' => [], 'warnings' => [], 'transformed' => []];

		$schema = new Schema();
		$schema->setId(42);
		$schema->setTitle('Agenda');

		$registers = [];
		foreach (($scenario['registers'] ?? [[1, [42]]]) as [$id, $schemas]) {
			$register = new Register();
			$register->setId($id);
			$register->setSchemas($schemas);
			$registers[] = $register;
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			static function () use ($scenario, $registers): array {
				if (($scenario['registersThrow'] ?? false) === true) {
					throw new \RuntimeException('registers unavailable');
				}

				return $registers;
			}
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			static function (Register $register, Schema $schema, ?int $limit = null, ?int $offset = null, ?array $filters = null) use ($scenario, &$record): array {
				$record['queries'][] = ['register' => $register->getId(), 'limit' => $limit, 'offset' => $offset, 'filters' => $filters];
				if (($scenario['throwOn'] ?? null) === $register->getId()) {
					throw new \RuntimeException('table missing');
				}

				$objects = [];
				foreach (['a', 'b', 'c'] as $name) {
					$object = new ObjectEntity();
					$object->setUuid($register->getId() . '-' . $name);
					$objects[] = $object;
				}

				return $objects;
			}
		);

		$transformer = $this->createMock(CalendarEventTransformer::class);
		$transformer->method('transform')->willReturnCallback(
			static function (ObjectEntity $object, Schema $schema, array $config) use ($scenario, &$record): ?array {
				$uuid = (string) $object->getUuid();
				$record['transformed'][] = $uuid;
				$name = substr($uuid, -1);
				if (($scenario['transformThrows'] ?? null) === $name) {
					throw new \RuntimeException('bad object ' . $uuid);
				}

				if (in_array($name, ($scenario['nullEvents'] ?? []), true) === true) {
					return null;
				}

				return match ($scenario['eventShape'] ?? 'full') {
					'no-objects' => ['id' => $uuid, 'objects' => []],
					'no-summary' => ['id' => $uuid, 'objects' => [['UID' => [$uuid]]]],
					default => ['id' => $uuid, 'objects' => [['SUMMARY' => [($name === 'b') ? 'Team meeting' : 'Lunch ' . $uuid]]]],
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$record): void {
				$record['warnings'][] = $message;
			}
		);

		$calendar = $this->calendar(
			$schema,
			($scenario['config'] ?? ['dtstart' => 'startdatum', 'displayName' => 'Agenda']),
			$magicMapper,
			$registerMapper,
			$transformer,
			($scenario['principal'] ?? 'principals/users/admin'),
			$logger
		);

		$events = $calendar->search(
			($scenario['pattern'] ?? ''),
			[],
			($scenario['options'] ?? []),
			($scenario['limit'] ?? null),
			($scenario['offset'] ?? null)
		);

		$actual = json_decode((string) json_encode(['events' => $events, 'record' => $record]), true);
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * Mutation check: refused principals never reach a table query.
	 *
	 * @return void
	 */
	public function testRefusedPrincipalsNeverQuery(): void {
		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach (['group principal refused', 'empty principal refused', 'bare users prefix refused'] as $case) {
			$this->assertSame([], $golden[$case]['events'], $case);
			$this->assertSame([], $golden[$case]['record']['queries'], $case);
		}

		$this->assertNotSame([], $golden['user principal']['events']);
	}

	/**
	 * Build the calendar under test.
	 *
	 * @param Schema                   $schema         The schema.
	 * @param array<string, mixed>     $config         The calendar config.
	 * @param MagicMapper              $magicMapper    The magic mapper.
	 * @param RegisterMapper           $registerMapper The register mapper.
	 * @param CalendarEventTransformer $transformer    The transformer.
	 * @param string                   $principalUri   The principal.
	 * @param LoggerInterface          $logger         The logger.
	 *
	 * @return RegisterCalendar
	 */
	private function calendar(
		Schema $schema,
		array $config,
		MagicMapper $magicMapper,
		RegisterMapper $registerMapper,
		CalendarEventTransformer $transformer,
		string $principalUri,
		LoggerInterface $logger
	): RegisterCalendar {
		return new RegisterCalendar($schema, $config, $magicMapper, $registerMapper, $transformer, $principalUri, $logger);
	}
}
