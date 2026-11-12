<?php

/**
 * Golden test for ExampleSetCatalogue: the plan, or the refusal, that gather()
 * and sets() return for a fixed set of fragments that reach every branch of the
 * entry checks. Re-record with UPDATE_GOLDEN=1.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 */
class ExampleSetCatalogueGoldenTest extends ExampleSetTestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-catalogue-golden.json';

	public function testGatherAndSetsMatchGolden(): void {
		$base   = $this->appsRoot;
		$actual = [];
		$index  = 0;
		foreach ($this->scenarios() as $name => $scenario) {
			$this->apps     = [];
			$this->appsRoot = $base.'/'.$index++;
			mkdir($this->appsRoot);
			$actual[$name] = $this->outcome(scenario: $scenario);
		}

		$this->appsRoot = $base;

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}

	/**
	 * @param array<string, mixed> $scenario The fragments to write and the set to gather.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(array $scenario): array {
		foreach ($scenario['apps'] as $app => $fragment) {
			$this->app(app: $app, fragment: $fragment);
		}

		foreach (($scenario['raw'] ?? []) as $app => $files) {
			foreach ($files as $file => $content) {
				$this->app(app: $app);
				$path = $this->apps[$app].'/'.ExampleSetCatalogue::FOLDER.'/'.$file;
				@mkdir(dirname($path), 0777, true);
				file_put_contents($path, $content);
			}
		}

		foreach (($scenario['parts'] ?? []) as $app => $parts) {
			foreach ($parts as $file => $part) {
				$folder = $this->apps[$app].'/'.ExampleSetCatalogue::FOLDER.'/demo.d';
				@mkdir($folder, 0777, true);
				file_put_contents($folder.'/'.$file, (string) json_encode($part));
			}
		}

		$outcome = ['sets' => $this->catalogue->sets()];
		try {
			$outcome['plan'] = $this->catalogue->gather(set: (string) ($scenario['set'] ?? 'demo'));
		} catch (ExampleSetException $exception) {
			$outcome['error'] = $exception->getMessage();
		}

		return json_decode(str_replace($this->appsRoot, '<root>', (string) json_encode($outcome, JSON_UNESCAPED_SLASHES)), true);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$object = ['key' => 'one', 'register' => 'r', 'schema' => 's', 'data' => ['a' => 1]];
		$alpha  = fn (array $fragment): array => ['apps' => ['openregister' => $this->castFile(), 'alpha' => $fragment]];
		$org    = fn (array $quota): array => ['apps' => ['openregister' => ['cast' => ['openregisterOrganisations' => [
			['key' => 'o', 'name' => 'n', 'slug' => 's', 'quota' => $quota],
		]]]]];
		$change = ['key' => 'ch', 'register' => 'r', 'schema' => 's', 'where' => ['a' => 1], 'set' => ['b' => 2]];

		return [
			'full plan' => [
				'apps' => [
					'openregister' => $this->castFile(objects: [$object]) + [
						'apps'     => ['alpha', 'beta', 'ghost'],
						'requires' => ['plain', ['register' => 'mocked', 'mock' => true], ['register' => 'real', 'mock' => false]],
						'archival' => [['key' => 'arch', 'data' => ['register' => 'r']]],
						'appConfig' => [
							['key' => 'plain', 'value' => 'x'],
							['key' => 'gen', 'generate' => true],
							['key' => 'num', 'value' => '12', 'type' => 'int'],
							['key' => 'floor', 'value' => '{{now.year}}', 'type' => 'int', 'atLeast' => true],
							['key' => 'neg', 'value' => -3, 'type' => 'int', 'app' => 'openregister'],
						],
					],
					'alpha' => [
						'after'   => ['beta'],
						'objects' => [
							$object,
							['key' => 'withFiles', 'register' => 'r', 'schema' => 's', 'data' => [], 'files' => [['path' => 'a/b.pdf'], ['path' => 'c.txt']]],
						],
						'changes' => [
							$change,
							['key' => 'ch2', 'register' => 'r', 'schema' => 's', 'object' => 'one', 'append' => ['x' => [1]]],
						],
						'groups' => [['gid' => 'g1'], 'not-a-group'],
						'serviceAccounts' => [
							['key' => 'bot', 'uid' => 'svc.bot-1'],
							['key' => 'bot2', 'uid' => 'svc_2', 'displayName' => 'Bot', 'groups' => ['x', 5]],
						],
						'userPreferences' => [['user' => 'u', 'key' => 'k', 'value' => 3], ['user' => 'u', 'key' => 'k2', 'value' => true, 'app' => 'alpha']],
						'tasks'   => [['key' => 't1', 'data' => ['title' => 'T']]],
						'views'   => [['key' => 'v1', 'data' => []]],
						'watchers' => [['key' => 'w1', 'data' => ['x' => 1]]],
						'parties' => [['key' => 'p1', 'data' => []]],
						'notes'   => [['key' => 'n1', 'data' => []]],
						'readStates' => [['key' => 'rs1', 'data' => []]],
						'flows'   => [['key' => 'f1', 'data' => []]],
						'exportRuns' => [['key' => 'e1', 'data' => []]],
						'deckCards' => [['key' => 'd1', 'data' => []]],
						'appointments' => [['key' => 'ap1', 'data' => []]],
						'casePlans' => [['key' => 'cp1', 'data' => []]],
						'collectivesPages' => [['key' => 'cl1', 'data' => []]],
						'talkConversations' => [['key' => 'tc1', 'data' => []]],
						'auditEntries' => [['key' => 'au1', 'data' => []]],
						'timelineEntries' => [['key' => 'tl1', 'data' => []]],
						'emailLinks' => [['key' => 'el1', 'data' => []]],
						'accessLinks' => [['key' => 'al1', 'data' => []]],
						'grants'  => [['key' => 'gr1', 'data' => []]],
					],
					'beta'      => ['title' => 'ignored'],
					'bystander' => null,
				],
				'raw'   => ['openregister' => ['Bad_Name.json' => '{}', 'other.json' => '{}', 'notes.txt' => 'x']],
				'parts' => [
					'alpha' => [
						'20-b.json' => ['objects' => [['key' => 'later', 'register' => 'r', 'schema' => 's', 'data' => []]]],
						'10-a.json' => ['$comment' => 'c', 'set' => 'demo', '@x' => 1, 'tasks' => [['key' => 't2', 'data' => []]], 'newList' => [1, 2]],
					],
				],
			],
			'cast without contactsUid on groups, explicit uid kept' => [
				'apps' => ['openregister' => ['cast' => [
					'people' => [['key' => 'p', 'contactsUid' => 'mine'], ['key' => 'q', 'contactsUid' => 5]],
					'organisations' => [['key' => 'o']],
					'groups' => [['key' => 'g']],
				]]],
			],
			'bad set id' => ['apps' => [], 'set' => '../etc'],
			'uppercase set id' => ['apps' => [], 'set' => 'Demo'],
			'no cast file' => ['apps' => ['alpha' => ['objects' => []]]],
			'cast not an array' => ['apps' => ['openregister' => ['cast' => 'x']]],
			'invalid json' => ['apps' => ['openregister' => $this->castFile()], 'raw' => ['alpha' => ['demo.json' => '{nope']]],
			'json scalar' => ['apps' => ['openregister' => $this->castFile()], 'raw' => ['alpha' => ['demo.json' => '"x"']]],
			'cast outside openregister' => $alpha(['cast' => []]),
			'cast key missing' => ['apps' => ['openregister' => ['cast' => ['people' => [['uid' => 'x']]]]]],
			'cast key twice across parts' => ['apps' => ['openregister' => ['cast' => ['people' => [['key' => 'a']], 'groups' => [['key' => 'a']]]]]],
			'cast entry not array' => ['apps' => ['openregister' => ['cast' => ['organisations' => ['x']]]]],
			'part not a list' => $alpha([]) + ['parts' => ['alpha' => ['x.json' => ['after' => 'beta']]]],
			'part map value' => $alpha([]) + ['parts' => ['alpha' => ['x.json' => ['objects' => ['k' => 1]]]]],
			'part invalid json' => $alpha([]) + ['raw' => ['alpha' => ['demo.d/bad.json' => '[1']]],
			'object key twice' => $alpha(['objects' => [$object, $object]]),
			'object and change share key' => $alpha(['objects' => [$object], 'changes' => [['key' => 'one'] + $change]]),
			'object without key' => $alpha(['objects' => [['register' => 'r', 'schema' => 's', 'data' => []]]]),
			'object not an array' => $alpha(['objects' => ['x']]),
			'object without register' => $alpha(['objects' => [['key' => 'k', 'schema' => 's', 'data' => []]]]),
			'object without schema' => $alpha(['objects' => [['key' => 'k', 'register' => 'r', 'data' => []]]]),
			'object without data' => $alpha(['objects' => [['key' => 'k', 'register' => 'r', 'schema' => 's']]]),
			'file absolute' => $alpha(['objects' => [['files' => [['path' => '/etc/x']]] + $object]]),
			'file dotdot' => $alpha(['objects' => [['files' => [['path' => 'a/../b']]] + $object]]),
			'file dotdot end' => $alpha(['objects' => [['files' => [['path' => 'a/..']]] + $object]]),
			'file dots in name' => $alpha(['objects' => [['files' => [['path' => 'a/..b']]] + $object]]),
			'file backslash' => $alpha(['objects' => [['files' => [['path' => 'a\\b']]] + $object]]),
			'file nul' => $alpha(['objects' => [['files' => [['path' => "a\0b"]]] + $object]]),
			'file empty path' => $alpha(['objects' => [['files' => [['name' => 'x']]] + $object]]),
			'file not array' => $alpha(['objects' => [['files' => ['x.pdf']] + $object]]),
			'change without where or object' => $alpha(['changes' => [['key' => 'c', 'register' => 'r', 'schema' => 's', 'set' => []]]]),
			'change without set or append' => $alpha(['changes' => [['key' => 'c', 'register' => 'r', 'schema' => 's', 'object' => 'o']]]),
			'change object not string' => $alpha(['changes' => [['key' => 'c', 'register' => 'r', 'schema' => 's', 'object' => 1, 'set' => []]]]),
			'keyed row without key' => $alpha(['tasks' => [['data' => []]]]),
			'keyed row empty key' => $alpha(['views' => [['key' => '', 'data' => []]]]),
			'keyed row int key' => $alpha(['notes' => [['key' => 5, 'data' => []]]]),
			'keyed row without data' => $alpha(['grants' => [['key' => 'g']]]),
			'keyed row twice' => $alpha(['readStates' => [['key' => 'g', 'data' => []], ['key' => 'g', 'data' => []]]]),
			'keyed row same key in two lists' => $alpha(['tasks' => [['key' => 'g', 'data' => []]], 'views' => [['key' => 'g', 'data' => []]]]),
			'archival outside openregister' => $alpha(['archival' => [['key' => 'a', 'data' => []]]]),
			'archival bad row' => ['apps' => ['openregister' => $this->castFile() + ['archival' => [['key' => 'a']]]]],
			'service account without key' => $alpha(['serviceAccounts' => [['uid' => 'bot']]]),
			'service account bad uid' => $alpha(['serviceAccounts' => [['key' => 'b', 'uid' => 'Bot']]]),
			'service account no uid' => $alpha(['serviceAccounts' => [['key' => 'b']]]),
			'service account twice across apps' => ['apps' => [
				'openregister' => $this->castFile(),
				'alpha'        => ['serviceAccounts' => [['key' => 'b', 'uid' => 'b1']]],
				'beta'         => ['serviceAccounts' => [['key' => 'b', 'uid' => 'b2']]],
			]],
			'service account uses cast key' => $alpha(['serviceAccounts' => [['key' => 'sanne', 'uid' => 'b1']]]),
			'app config for another app' => $alpha(['appConfig' => [['app' => 'beta', 'key' => 'k', 'value' => 'v']]]),
			'app config without key' => $alpha(['appConfig' => [['value' => 'v']]]),
			'app config empty key' => $alpha(['appConfig' => [['key' => '', 'value' => 'v']]]),
			'app config without value' => $alpha(['appConfig' => [['key' => 'k']]]),
			'app config array value' => $alpha(['appConfig' => [['key' => 'k', 'value' => []]]]),
			'app config generate not true' => $alpha(['appConfig' => [['key' => 'k', 'generate' => 1]]]),
			'app config bad type' => $alpha(['appConfig' => [['key' => 'k', 'value' => 'v', 'type' => 'bool']]]),
			'app config int not int' => $alpha(['appConfig' => [['key' => 'k', 'value' => '1.5', 'type' => 'int']]]),
			'app config int generated' => $alpha(['appConfig' => [['key' => 'k', 'generate' => true, 'type' => 'int']]]),
			'app config atLeast generated' => $alpha(['appConfig' => [['key' => 'k', 'generate' => true, 'type' => 'int', 'atLeast' => true]]]),
			'app config atLeast string' => $alpha(['appConfig' => [['key' => 'k', 'value' => '1', 'atLeast' => true]]]),
			'app config atLeast not true' => $alpha(['appConfig' => [['key' => 'k', 'value' => '1', 'type' => 'int', 'atLeast' => 'yes']]]),
			'user preference for another app' => $alpha(['userPreferences' => [['app' => 'beta', 'user' => 'u', 'key' => 'k', 'value' => 'v']]]),
			'user preference without user' => $alpha(['userPreferences' => [['key' => 'k', 'value' => 'v']]]),
			'user preference empty key' => $alpha(['userPreferences' => [['user' => 'u', 'key' => '', 'value' => 'v']]]),
			'user preference null value' => $alpha(['userPreferences' => [['user' => 'u', 'key' => 'k', 'value' => null]]]),
			'requires empty' => ['apps' => ['openregister' => $this->castFile() + ['requires' => ['']]]],
			'requires mock not bool' => ['apps' => ['openregister' => $this->castFile() + ['requires' => [['register' => 'r', 'mock' => 'yes']]]]],
			'requires int' => ['apps' => ['openregister' => $this->castFile() + ['requires' => [5]]]],
			'organisations' => ['apps' => ['openregister' => [
				'cast' => [
					'people' => [['key' => 'a', 'uid' => 'ua'], ['key' => 'b']],
					'openregisterOrganisations' => [[
						'key' => 'o', 'name' => 'Org', 'slug' => 'org', 'description' => 'd', 'type' => 't', 'contactEmail' => 'e',
						'quota' => ['storage' => 1, 'requests' => 0, 'bandwidth' => 2, 'users' => 3, 'objects' => 4, 'objectsSchema' => 'sch'],
						'members' => ['a'],
					], ['key' => 'p', 'name' => 'P', 'slug' => 'p']],
				],
			]]],
			'organisation without slug' => ['apps' => ['openregister' => ['cast' => ['openregisterOrganisations' => [['key' => 'o', 'name' => 'n']]]]]],
			'organisation bad quota' => $org(['users' => -1]),
			'organisation unknown quota' => $org(['objectsSchema' => '']),
			'organisation member without account' => ['apps' => ['openregister' => ['cast' => [
				'people' => [['key' => 'b']],
				'openregisterOrganisations' => [['key' => 'o', 'name' => 'n', 'slug' => 's', 'members' => ['b']]],
			]]]],
			'order cycle' => ['apps' => ['openregister' => $this->castFile(), 'alpha' => ['after' => ['beta']], 'beta' => ['after' => ['alpha']]]],
		];
	}
}
