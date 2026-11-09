<?php

/**
 * Golden: what an example set's record reads back from what is stored, malformed entries included, and which
 * held entries a fragment lets go, pinned on the exact record.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetRecordGoldenTest extends TestCase {
	/**
	 * What is stored, by case: a record with a malformed entry in every part, nothing, text that is no JSON, JSON
	 * that is no record, and a record of one part.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$stored = [
		  'users' => ['alice' => 'created', 'bob' => 'adopted', 'eve' => 'stolen', 7 => 'created'],
		  'groups' => ['g1', 2, 'g2', null],
		  'groupNames' => ['g1' => ['name' => 'G'], 'g2' => 'x'],
		  'memberships' => [['g1', 'alice'], ['g1'], 'x', ['g2', 3], ['g2', 'bob', 'extra']],
		  'subAdmins' => [['g1', 'bob'], [1, 'x']],
		  'objects' => ['demo/a' => ['register' => 'r', 'schema' => 's', 'id' => 'i1', 'refs' => ['f' => 1], 'junk' => 1], 'demo/b' => ['id' => ''], 'demo/c' => ['id' => 5], 'demo/d' => ['id' => 'i4', 'refs' => []], 'demo/e' => 'x', 9 => ['id' => 'i9', 'register' => 3]],
		  'changes' => ['demo/c1' => ['set' => []], 'demo/c2' => 'x'],
		  'registers' => ['slug' => [['appId' => 'a', 'jobId' => 'j']], 'other' => 'x', 'empty' => []],
		  'organisations' => ['u1', ['x'], 'u2'],
		  'tasks' => ['demo/t' => ['uuid' => 'u'], 'demo/u' => 1],
		  'archival' => ['k' => ['v' => 1]],
		  'grants' => 'notalist',
		  'unknown' => ['demo/x' => ['y' => 1]],
		];
		return [
			'full'     => (string) json_encode($stored),
			'empty'    => '',
			'not json' => '{',
			'scalar'   => '"x"',
			'minimal'  => (string) json_encode(['users' => ['a' => 'adopted']]),
		];
	}//end stored()

	/**
	 * The record each stored text reads back as, as JSON.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		$expected = [
			'full' => '{"users":{"alice":"created","bob":"adopted","7":"created"},"groups":["g1","g2"],"memberships":[["g1","alice"],["g2","bob"]],"subAdmins":[["g1","bob"]],"groupNames":{"g1":{"name":"G"}},"objects":{"demo/a":{"register":"r","schema":"s","id":"i1","refs":{"f":1}},"demo/d":{"register":"","schema":"","id":"i4"},"9":{"register":"3","schema":"","id":"i9"}},"changes":{"demo/c1":{"set":[]}},"registers":{"slug":[{"appId":"a","jobId":"j"}],"empty":[]},"files":[],"appConfig":[],"userPreferences":[],"organisations":["u1","u2"],"tasks":{"demo/t":{"uuid":"u"}},"views":[],"watchers":[],"parties":[],"notes":[],"readStates":[],"flows":[],"exportRuns":[],"deckCards":[],"appointments":[],"casePlans":[],"collectivesPages":[],"talkConversations":[],"auditEntries":[],"timelineEntries":[],"emailLinks":[],"accessLinks":[],"grants":[],"archival":{"k":{"v":1}}}',
			'empty' => '{"users":[],"groups":[],"memberships":[],"subAdmins":[],"groupNames":[],"objects":[],"changes":[],"registers":[],"files":[],"appConfig":[],"userPreferences":[],"organisations":[],"tasks":[],"views":[],"watchers":[],"parties":[],"notes":[],"readStates":[],"flows":[],"exportRuns":[],"deckCards":[],"appointments":[],"casePlans":[],"collectivesPages":[],"talkConversations":[],"auditEntries":[],"timelineEntries":[],"emailLinks":[],"accessLinks":[],"grants":[],"archival":[]}',
			'not json' => '{"users":[],"groups":[],"memberships":[],"subAdmins":[],"groupNames":[],"objects":[],"changes":[],"registers":[],"files":[],"appConfig":[],"userPreferences":[],"organisations":[],"tasks":[],"views":[],"watchers":[],"parties":[],"notes":[],"readStates":[],"flows":[],"exportRuns":[],"deckCards":[],"appointments":[],"casePlans":[],"collectivesPages":[],"talkConversations":[],"auditEntries":[],"timelineEntries":[],"emailLinks":[],"accessLinks":[],"grants":[],"archival":[]}',
			'scalar' => '{"users":[],"groups":[],"memberships":[],"subAdmins":[],"groupNames":[],"objects":[],"changes":[],"registers":[],"files":[],"appConfig":[],"userPreferences":[],"organisations":[],"tasks":[],"views":[],"watchers":[],"parties":[],"notes":[],"readStates":[],"flows":[],"exportRuns":[],"deckCards":[],"appointments":[],"casePlans":[],"collectivesPages":[],"talkConversations":[],"auditEntries":[],"timelineEntries":[],"emailLinks":[],"accessLinks":[],"grants":[],"archival":[]}',
			'minimal' => '{"users":{"a":"adopted"},"groups":[],"memberships":[],"subAdmins":[],"groupNames":[],"objects":[],"changes":[],"registers":[],"files":[],"appConfig":[],"userPreferences":[],"organisations":[],"tasks":[],"views":[],"watchers":[],"parties":[],"notes":[],"readStates":[],"flows":[],"exportRuns":[],"deckCards":[],"appointments":[],"casePlans":[],"collectivesPages":[],"talkConversations":[],"auditEntries":[],"timelineEntries":[],"emailLinks":[],"accessLinks":[],"grants":[],"archival":[]}',
		];
		$cases    = [];
		foreach (self::stored() as $name => $json) {
			$cases[$name] = [$json, $expected[$name]];
		}

		return $cases;
	}//end cases()

	/**
	 * A stored text reads back as exactly this record, its parts in the blank record's order.
	 *
	 * @param string $json     What is stored (lazily).
	 * @param string $expected The record read back, as JSON.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRead(string $json, string $expected): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default, bool $lazy): string => ($lazy === true) ? $json : ''
		);
		$record = (new ExampleSetRecord(appConfig: $config))->read(set: 'demo');
		$this->assertSame($expected, json_encode($record, JSON_UNESCAPED_SLASHES));
	}//end testRead()

	/**
	 * A fragment lets go of its app's held entries it no longer declares; another app's entries and entries keyed
	 * by an object stay.
	 *
	 * @return void
	 */
	public function testLetGoUndeclared(): void {
		$fragment = ['app' => 'demo', 'notes' => [['key' => 'kept'], ['nokey' => true]]];
		$record   = [
			'notes' => [
				'demo/kept'         => ['id' => 1],
				'demo/gone'         => ['id' => 2],
				'demo/'             => ['id' => 3],
				'other/gone'        => ['id' => 4],
				'demo/o#references' => ['id' => 5],
				'demo/also-gone'    => ['id' => 6],
			],
		];
		$report   = [];
		$this->assertTrue(ExampleSetRecord::letGoUndeclared(fragment: $fragment, part: 'notes', noun: 'note', report: $report, record: $record));
		$this->assertSame(['demo/kept', 'demo/', 'other/gone', 'demo/o#references'], array_keys(array: $record['notes']));
		$this->assertSame(['left' => ['note demo/gone: the set no longer declares it', 'note demo/also-gone: the set no longer declares it']], $report);
		$this->assertFalse(ExampleSetRecord::letGoUndeclared(fragment: $fragment, part: 'notes', noun: 'note', report: $report, record: $record));
		$empty = [];
		$this->assertFalse(ExampleSetRecord::letGoUndeclared(fragment: ['app' => 'demo'], part: 'tasks', noun: 'task', report: $report, record: $empty));
	}//end testLetGoUndeclared()
}//end class
