<?php

/**
 * Golden: how the shared loops of an example set's engine rows check, write, refresh, keep and let go of the rows
 * of one fragment, and what the date and object checks refuse, pinned on the exact report and record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 */
class ExampleSetEngineRowsGoldenTest extends TestCase {
	/**
	 * A kind of row for the test: refuses `bad`, fails a write of `broken`, fails a revert of `stuck`, and records
	 * every write, update and revert.
	 *
	 * @param string $part     The part, which decides whether a refresh writes it again.
	 * @param bool   $inPlace  Whether a refresh updates a held row where it is.
	 * @param array  $calls    Collects the calls.
	 *
	 * @return ExampleSetEngineRows
	 */
	private function rows(string $part, bool $inPlace, array &$calls): ExampleSetEngineRows {
		return new class($part, $inPlace, $calls) extends ExampleSetEngineRows {
			/**
			 * The test kind.
			 *
			 * @param string $kindPart  The part.
			 * @param bool   $inPlace   Whether a refresh updates in place.
			 * @param array  $calls     Collects the calls.
			 */
			public function __construct(private string $kindPart, private bool $inPlace, private array &$calls) {
			}

			public function part(): string {
				return $this->kindPart;
			}

			public function noun(): string {
				return 'thing';
			}

			public function refusal(array $data, array $users): string {
				if (($data['name'] ?? '') === 'bad') {
					return 'bad is refused for '.implode(',', $users);
				}

				return '';
			}

			public function write(string $app, string $key, array $data): array {
				$this->calls[] = 'write '.$app.'/'.$key.' '.json_encode($data);
				if (($data['name'] ?? '') === 'broken') {
					return ['error' => 'it broke', 'entry' => null];
				}

				return ['error' => '', 'entry' => ['uuid' => 'u-'.$key]];
			}

			public function lost(array $entry): bool {
				return false;
			}

			public function revert(array $entry): string {
				$this->calls[] = 'revert '.$entry['uuid'];
				return ($entry['uuid'] === 'u-stuck') ? 'failed' : 'removed';
			}

			public function updatesInPlace(): bool {
				return $this->inPlace;
			}

			public function update(array $entry, string $app, string $key, array $data): array {
				$this->calls[] = 'update '.$entry['uuid'].' '.$app.'/'.$key;
				return ['error' => '', 'entry' => ['uuid' => $entry['uuid'], 'updated' => true]];
			}

			/**
			 * The date check, opened for the test.
			 *
			 * @param mixed                $value   The value.
			 * @param array<string, mixed> $context The context.
			 * @param bool                 $isEnd   Whether the date is an end.
			 *
			 * @return string
			 */
			public function date(mixed $value, array $context, bool $isEnd): string {
				return $this->dateRefusal(field: 'until', value: $value, context: $context, isEnd: $isEnd);
			}

			/**
			 * The object check, opened for the test.
			 *
			 * @param mixed                $uuid    The value.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return bool
			 */
			public function ofSet(mixed $uuid, array $context): bool {
				return $this->isObjectOfSet(uuid: $uuid, context: $context);
			}
		};
	}//end rows()

	/**
	 * A fragment with these rows of the part.
	 *
	 * @param string                           $part The part.
	 * @param array<int, array<string, mixed>> $rows The rows, `{key, data}`.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragment(string $part, array $rows): array {
		return ['app' => 'demo', 'file' => 'f.json', $part => $rows];
	}//end fragment()

	/**
	 * The check names each refused row and each unresolved placeholder, a gap winning over a refusal.
	 *
	 * @return void
	 */
	public function testCheckFragment(): void {
		$calls    = [];
		$rows     = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$fragment = self::fragment(
			part: 'notes',
			rows: [
				['key' => 'ok', 'data' => ['name' => 'fine']],
				['key' => 'refused', 'data' => ['name' => 'bad']],
				['key' => 'gap', 'data' => ['name' => 'bad', 'who' => '{{cast:nobody.uid}}', 'too' => '{{cast:nobody.uid}}']],
			]
		);
		$this->assertSame(
			[
				'f.json: thing "refused": bad is refused for alice,bob',
				'f.json: thing "gap": cannot resolve {{cast:nobody.uid}}',
			],
			$rows->checkFragment(fragment: $fragment, context: ['cast' => []], users: ['alice', 'bob'], values: new ExampleSetValues())
		);
		$this->assertSame([], $rows->checkFragment(fragment: ['app' => 'demo', 'file' => 'f.json'], context: [], users: [], values: new ExampleSetValues()));
		$this->assertSame([], $calls);
	}//end testCheckFragment()

	/**
	 * A first install writes each row, drops a refused, unresolved or failed one, and lets go of a held row the
	 * fragment no longer declares.
	 *
	 * @return void
	 */
	public function testFirstInstall(): void {
		$calls    = [];
		$rows     = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$fragment = self::fragment(
			part: 'notes',
			rows: [
				['key' => 'a', 'data' => ['name' => 'fine']],
				['key' => 'b', 'data' => ['name' => 'bad']],
				['key' => 'c', 'data' => ['name' => 'broken']],
				['key' => 'd', 'data' => ['who' => '{{cast:nobody.uid}}']],
			]
		);
		$report   = [];
		$record   = ['users' => ['alice' => 'created'], 'notes' => ['demo/old' => ['uuid' => 'u-old'], 'other/x' => ['uuid' => 'u-x']]];
		$changed  = $rows->installFragment(fragment: $fragment, context: ['cast' => []], report: $report, record: $record, values: new ExampleSetValues());
		$this->assertTrue($changed);
		$this->assertSame(
			[
				'notes'   => ['demo/a' => 'created', 'demo/b' => 'failed', 'demo/c' => 'failed', 'demo/d' => 'failed'],
				'dropped' => [
					'f.json: thing "b": bad is refused for alice',
					'f.json: thing "c": it broke',
					'f.json: thing "d": cannot resolve {{cast:nobody.uid}}',
				],
				'left'    => ['thing demo/old: the set no longer declares it'],
			],
			$report
		);
		$this->assertSame(['users' => ['alice' => 'created'], 'notes' => ['other/x' => ['uuid' => 'u-x'], 'demo/a' => ['uuid' => 'u-a']]], $record);
		$this->assertSame(['write demo/a {"name":"fine"}', 'write demo/c {"name":"broken"}'], $calls);
	}//end testFirstInstall()

	/**
	 * Without a refresh a held row is kept as it is, and nothing changes.
	 *
	 * @return void
	 */
	public function testHeldRowIsKept(): void {
		$calls   = [];
		$rows    = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$report  = [];
		$record  = ['users' => [], 'notes' => ['demo/a' => ['uuid' => 'u-a']]];
		$changed = $rows->installFragment(
			fragment: self::fragment(part: 'notes', rows: [['key' => 'a', 'data' => ['name' => 'fine']]]),
			context: ['refresh' => false],
			report: $report,
			record: $record,
			values: new ExampleSetValues()
		);
		$this->assertFalse($changed);
		$this->assertSame(['notes' => ['demo/a' => 'kept']], $report);
		$this->assertSame([], $calls);
	}//end testHeldRowIsKept()

	/**
	 * A refresh takes a held row away and writes it again; one that cannot be taken away stays and is named.
	 *
	 * @return void
	 */
	public function testRefreshWritesAgain(): void {
		$calls   = [];
		$rows    = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$report  = [];
		$record  = ['users' => [], 'notes' => ['demo/a' => ['uuid' => 'u-a'], 'demo/stuck' => ['uuid' => 'u-stuck']]];
		$changed = $rows->installFragment(
			fragment: self::fragment(part: 'notes', rows: [['key' => 'a', 'data' => ['name' => 'fine']], ['key' => 'stuck', 'data' => ['name' => 'fine']]]),
			context: ['refresh' => true],
			report: $report,
			record: $record,
			values: new ExampleSetValues()
		);
		$this->assertTrue($changed);
		$this->assertSame(
			[
				'notes'   => ['demo/a' => 'refreshed', 'demo/stuck' => 'kept'],
				'dropped' => ['f.json: thing "stuck": not refreshed, the earlier one could not be taken away'],
			],
			$report
		);
		$this->assertSame(['revert u-a', 'write demo/a {"name":"fine"}', 'revert u-stuck'], $calls);
	}//end testRefreshWritesAgain()

	/**
	 * A refresh of a kind that updates in place updates the held row; a part a refresh does not write again keeps it.
	 *
	 * @return void
	 */
	public function testRefreshUpdatesInPlaceAndSkipsUnrefreshedParts(): void {
		$calls   = [];
		$report  = [];
		$record  = ['users' => [], 'views' => ['demo/a' => ['uuid' => 'u-a']], 'watchers' => ['demo/a' => ['uuid' => 'u-a']]];
		$rows    = $this->rows(part: 'views', inPlace: true, calls: $calls);
		$changed = $rows->installFragment(
			fragment: self::fragment(part: 'views', rows: [['key' => 'a', 'data' => ['name' => 'fine']]]),
			context: ['refresh' => true],
			report: $report,
			record: $record,
			values: new ExampleSetValues()
		);
		$this->assertTrue($changed);
		$this->assertSame(['uuid' => 'u-a', 'updated' => true], $record['views']['demo/a']);

		$watchers = $this->rows(part: 'watchers', inPlace: true, calls: $calls);
		$this->assertFalse(
			$watchers->installFragment(
				fragment: self::fragment(part: 'watchers', rows: [['key' => 'a', 'data' => ['name' => 'fine']]]),
				context: ['refresh' => true],
				report: $report,
				record: $record,
				values: new ExampleSetValues()
			)
		);
		$this->assertSame(['views' => ['demo/a' => 'refreshed'], 'watchers' => ['demo/a' => 'kept']], $report);
		$this->assertSame(['update u-a demo/a'], $calls);
	}//end testRefreshUpdatesInPlaceAndSkipsUnrefreshedParts()

	/**
	 * The date check refuses what is no date, and an end before the install day; a start may lie before it.
	 *
	 * @return void
	 */
	public function testDateRefusal(): void {
		$calls   = [];
		$rows    = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$context = ['today' => new DateTimeImmutable('2026-03-10 00:30', new DateTimeZone('Europe/Amsterdam'))];
		$this->assertSame('until is not a date', $rows->date(value: 5, context: $context, isEnd: true));
		$this->assertSame('until is not a date', $rows->date(value: '  ', context: $context, isEnd: true));
		$this->assertSame('until "soon" is not a date', $rows->date(value: 'soon', context: $context, isEnd: true));
		$this->assertSame('until "2026-03-09T22:00:00Z" lies before the install day', $rows->date(value: '2026-03-09T22:00:00Z', context: $context, isEnd: true));
		$this->assertSame('', $rows->date(value: '2026-03-09T23:30:00Z', context: $context, isEnd: true));
		$this->assertSame('', $rows->date(value: '2026-03-01', context: $context, isEnd: false));
		$this->assertSame('', $rows->date(value: '2026-03-01', context: [], isEnd: true));
	}//end testDateRefusal()

	/**
	 * A uuid is of the set when one of its objects carries it as id or uuid.
	 *
	 * @return void
	 */
	public function testIsObjectOfSet(): void {
		$calls   = [];
		$rows    = $this->rows(part: 'notes', inPlace: false, calls: $calls);
		$context = ['objects' => ['a' => ['id' => 'i-1'], 'b' => ['uuid' => 'u-2'], 'c' => 'junk']];
		$this->assertTrue($rows->ofSet(uuid: 'i-1', context: $context));
		$this->assertTrue($rows->ofSet(uuid: 'u-2', context: $context));
		$this->assertFalse($rows->ofSet(uuid: 'u-3', context: $context));
		$this->assertFalse($rows->ofSet(uuid: '', context: $context));
		$this->assertFalse($rows->ofSet(uuid: 7, context: $context));
		$this->assertFalse($rows->ofSet(uuid: 'i-1', context: []));
	}//end testIsObjectOfSet()
}//end class
