<?php

/**
 * Golden: what the engine kinds of an example set refuse, write, prove and delete, and in which order,
 * pinned on exact messages.
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

use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetParties;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 */
class ExampleSetEngineKindsGoldenTest extends TestCase {
	/**
	 * Each part, in the order the kinds run, with its writer class and its noun.
	 */
	private const PARTS = [
		'tasks'             => [ExampleSetTasks::class, 'task'],
		'views'             => [ExampleSetViews::class, 'view'],
		'watchers'          => [ExampleSetWatchers::class, 'watcher'],
		'parties'           => [ExampleSetParties::class, 'party'],
		'notes'             => [ExampleSetNotes::class, 'note'],
		'readStates'        => [ExampleSetReadStates::class, 'read state'],
		'flows'             => [ExampleSetFlows::class, 'flow'],
		'exportRuns'        => [ExampleSetExportRuns::class, 'export'],
		'archival'          => [ExampleSetArchival::class, 'archival setting'],
		'deckCards'         => [ExampleSetDeckCards::class, 'deck card'],
		'appointments'      => [ExampleSetAppointments::class, 'appointment'],
		'casePlans'         => [ExampleSetCasePlans::class, 'case plan'],
		'collectivesPages'  => [ExampleSetCollectivesPages::class, 'collectives page'],
		'talkConversations' => [ExampleSetTalkConversations::class, 'talk conversation'],
		'auditEntries'      => [ExampleSetAuditEntries::class, 'audit entry'],
		'timelineEntries'   => [ExampleSetTimelineEntries::class, 'timeline entry'],
		'emailLinks'        => [ExampleSetEmailLinks::class, 'email link'],
		'accessLinks'       => [ExampleSetAccessLinks::class, 'access link'],
		'grants'            => [ExampleSetGrants::class, 'grant'],
	];

	/**
	 * Calls made on the writer doubles, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The engine kinds over the given writers, by part; a part left out has no writer.
	 *
	 * @param array<string, ExampleSetEngineRows> $writers The writers by part.
	 *
	 * @return ExampleSetEngineKinds
	 */
	private function kinds(array $writers): ExampleSetEngineKinds {
		return new ExampleSetEngineKinds(...$writers);
	}//end kinds()

	/**
	 * A writer double for one part that records each call and answers as told.
	 *
	 * @param string $part    The part.
	 * @param bool   $changes Whether installFragment() reports a change.
	 * @param string $revert  What revert() answers.
	 *
	 * @return ExampleSetEngineRows
	 */
	private function writer(string $part, bool $changes, string $revert): ExampleSetEngineRows {
		$writer = $this->createMock(self::PARTS[$part][0]);
		$writer->method('checkFragment')->willReturnCallback(function () use ($part): array {
			$this->calls[] = 'check '.$part;
			return [$part.' refused'];
		});
		$writer->method('installFragment')->willReturnCallback(function () use ($part, $changes): bool {
			$this->calls[] = 'install '.$part;
			return $changes;
		});
		$writer->method('lost')->willReturnCallback(function (array $entry) use ($part): bool {
			$this->calls[] = 'lost '.$part;
			return ($entry['gone'] ?? false) === true;
		});
		$writer->method('revert')->willReturnCallback(function (array $entry) use ($part, $revert): string {
			$this->calls[] = 'revert '.$part.' '.$entry['n'];
			return $revert;
		});
		return $writer;
	}//end writer()

	/**
	 * A fragment declaring one row of every part.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragment(): array {
		$fragment = ['app' => 'demo', 'file' => 'f.json', 'dir' => '/x'];
		foreach (array_keys(self::PARTS) as $part) {
			$fragment[$part] = [['key' => 'k-'.$part, 'data' => []]];
		}

		return $fragment;
	}//end fragment()

	/**
	 * With no writers, every declared row is refused with its part's noun, in the kinds' order.
	 *
	 * @return void
	 */
	public function testCheckWithoutWritersRefusesEveryPartInOrder(): void {
		$expected = [];
		foreach (self::PARTS as $part => [, $noun]) {
			$expected[] = 'f.json: '.$noun.' "k-'.$part.'": this OpenRegister cannot write '.$part;
		}

		$this->assertSame($expected, $this->kinds(writers: [])->check(self::fragment(), [], [], new ExampleSetValues()));
	}//end testCheckWithoutWritersRefusesEveryPartInOrder()

	/**
	 * An access link address quoted outside a change's set field is refused before anything else.
	 *
	 * @return void
	 */
	public function testCheckRefusesAQuotedAddressOutsideAChangeSetField(): void {
		$fragment = [
			'app'     => 'demo',
			'file'    => 'f.json',
			'dir'     => '/x',
			'notes'   => [['key' => 'n', 'data' => ['text' => '{{ accessLink:a.url }}']]],
			'changes' => [
				['key' => 'c1', 'set' => ['title' => '{{accessLink:a.url}}', 'list' => ['{{accessLink:a.url}}']]],
				['key' => 'c2', 'set' => ['title' => 'plain'], 'where' => '{{accessLink:a.url}}'],
			],
		];
		$refusals = $this->kinds(writers: [])->check($fragment, [], [], new ExampleSetValues());
		$this->assertSame(
			[
				'f.json: notes quotes an access link address ({{accessLink:key.url}}); only a change may, as the text of a set field',
				'f.json: changes quotes an access link address ({{accessLink:key.url}}); only a change may, as the text of a set field',
				'f.json: note "n": this OpenRegister cannot write notes',
			],
			$refusals
		);
	}//end testCheckRefusesAQuotedAddressOutsideAChangeSetField()

	/**
	 * A quoted address only in a change's set field text is not refused.
	 *
	 * @return void
	 */
	public function testCheckAllowsAQuotedAddressAsSetFieldText(): void {
		$fragment = [
			'app'     => 'demo',
			'file'    => 'f.json',
			'changes' => [['key' => 'c1', 'set' => ['title' => '{{accessLink:a.url}}']]],
		];
		$this->assertSame([], $this->kinds(writers: [])->check($fragment, [], [], new ExampleSetValues()));
	}//end testCheckAllowsAQuotedAddressAsSetFieldText()

	/**
	 * Every writer is asked to check, in the kinds' order, and its refusals are kept.
	 *
	 * @return void
	 */
	public function testCheckAsksEveryWriterInOrder(): void {
		$writers = [];
		foreach (array_keys(self::PARTS) as $part) {
			$writers[$part] = $this->writer(part: $part, changes: false, revert: 'removed');
		}

		$refusals = $this->kinds(writers: $writers)->check(self::fragment(), [], [], new ExampleSetValues());
		$parts    = array_keys(self::PARTS);
		$this->assertSame(array_map(fn (string $p): string => $p.' refused', $parts), $refusals);
		$this->assertSame(array_map(fn (string $p): string => 'check '.$p, $parts), $this->calls);
	}//end testCheckAsksEveryWriterInOrder()

	/**
	 * Install writes every part but flows, access links and grants, in order; those three go before the changes.
	 *
	 * @return void
	 */
	public function testInstallOrderAndBeforeChanges(): void {
		$writers = [];
		foreach (array_keys(self::PARTS) as $part) {
			$writers[$part] = $this->writer(part: $part, changes: ($part === 'grants'), revert: 'removed');
		}

		$kinds  = $this->kinds(writers: $writers);
		$report = [];
		$record = ['users' => []];
		$this->assertFalse($kinds->install(self::fragment(), [], $report, $record, new ExampleSetValues()));
		$after = array_values(array_diff(array_keys(self::PARTS), ExampleSetEngineKinds::BEFORE_CHANGES));
		$this->assertSame(array_map(fn (string $p): string => 'install '.$p, $after), $this->calls);

		$this->calls = [];
		$this->assertTrue($kinds->installBeforeChanges(self::fragment(), [], $report, $record, new ExampleSetValues()));
		$this->assertSame(['install flows', 'install accessLinks', 'install grants'], $this->calls);
		$this->assertSame(['flows', 'accessLinks', 'grants'], ExampleSetEngineKinds::BEFORE_CHANGES);
	}//end testInstallOrderAndBeforeChanges()

	/**
	 * Without writers nothing is written, before or after the changes, and no access links are known.
	 *
	 * @return void
	 */
	public function testInstallWithoutWritersChangesNothing(): void {
		$kinds  = $this->kinds(writers: []);
		$report = [];
		$record = ['users' => []];
		$this->assertFalse($kinds->install(self::fragment(), [], $report, $record, new ExampleSetValues()));
		$this->assertFalse($kinds->installBeforeChanges(self::fragment(), [], $report, $record, new ExampleSetValues()));
		$this->assertSame([], $report);
		$this->assertSame([], $kinds->accessLinksOf(self::fragment(), null));
	}//end testInstallWithoutWritersChangesNothing()

	/**
	 * Prove names each lost row with its noun; a part without a writer is not proved.
	 *
	 * @return void
	 */
	public function testProve(): void {
		$kinds  = $this->kinds(writers: ['notes' => $this->writer(part: 'notes', changes: false, revert: 'removed')]);
		$record = [
			'notes' => ['demo/a' => ['n' => 1, 'gone' => true], 'demo/b' => ['n' => 2]],
			'tasks' => ['demo/t' => ['n' => 3, 'gone' => true]],
		];
		$this->assertSame(['x' => 1, 'missing' => ['note demo/a']], $kinds->prove(['x' => 1], $record));
		$this->assertSame(['lost notes', 'lost notes'], $this->calls);
	}//end testProve()

	/**
	 * Delete goes part by part, newest row first, keeps what failed and writes the record after each removal.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$kinds   = $this->kinds(
			writers: [
				'notes'  => $this->writer(part: 'notes', changes: false, revert: 'removed'),
				'grants' => $this->writer(part: 'grants', changes: false, revert: 'failed'),
			]
		);
		$record  = [
			'grants' => ['demo/g' => ['n' => 4]],
			'notes'  => ['demo/a' => ['n' => 1], 'demo/b' => ['n' => 2]],
			'tasks'  => ['demo/t' => ['n' => 3]],
		];
		$report  = [];
		$written = [];
		$kinds->delete($record, $report, function (array $kept) use (&$written): void {
			$written[] = array_keys($kept['notes']);
		});
		$this->assertSame(['revert notes 2', 'revert notes 1', 'revert grants 4'], $this->calls);
		$this->assertSame(
			[
				'failed'  => ['task demo/t: this OpenRegister cannot delete tasks', 'grant demo/g'],
				'removed' => ['notes' => 2],
			],
			$report
		);
		$this->assertSame([['demo/a'], []], $written);
		$this->assertSame(['grants' => ['demo/g' => ['n' => 4]], 'notes' => [], 'tasks' => ['demo/t' => ['n' => 3]]], $record);
	}//end testDelete()
}//end class
