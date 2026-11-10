<?php

/**
 * Golden: how an example set keeps, rewrites and follows an object it already wrote, pinned on the exact
 * report, record and store calls.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 */
class ExampleSetRewriterGoldenTest extends TestCase {
	/**
	 * Store calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A store double that records its calls.
	 *
	 * @param array<string, mixed> $answers What writeRules, update, ownerOf, assignOwner and get answer.
	 *
	 * @return ExampleSetStore
	 */
	private function store(array $answers): ExampleSetStore {
		$store = $this->createMock(ExampleSetStore::class);
		$store->method('writeRules')->willReturnCallback(function (string $register, string $schema) use ($answers): array {
			$this->calls[] = 'writeRules '.$register.' '.$schema;
			return $answers['rules'];
		});
		$store->method('update')->willReturnCallback(function (string $register, string $schema, string $id, array $data) use ($answers): string {
			$this->calls[] = 'update '.$register.' '.$schema.' '.$id.' '.json_encode($data);
			return ($answers['update'] ?? '');
		});
		$store->method('ownerOf')->willReturnCallback(function (string $register, string $schema, string $id) use ($answers): ?string {
			$this->calls[] = 'ownerOf '.$id;
			return ($answers['ownerOf'] ?? null);
		});
		$store->method('assignOwner')->willReturnCallback(function (string $id, string $owner) use ($answers): string {
			$this->calls[] = 'assignOwner '.$id.' '.$owner;
			return ($answers['assignOwner'] ?? '');
		});
		$store->method('get')->willReturnCallback(function (string $register, string $schema, string $id) use ($answers): ?array {
			$this->calls[] = 'get '.$id;
			return ($answers['get'] ?? null);
		});
		return $store;
	}//end store()

	/**
	 * Keep one object on a refresh run and return what came back, the report and what was written.
	 *
	 * @param array<string, mixed> $answers  The store's answers.
	 * @param array<string, mixed> $row      The object as stored.
	 * @param array<string, mixed> $resolved The declaration resolved today.
	 * @param bool                 $refresh  Whether the run refreshes.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
	 */
	private function keep(array $answers, array $row, array $resolved, bool $refresh=true): array {
		$report  = ['types' => []];
		$written = [];
		$run     = [
			'store'    => $this->store(answers: $answers),
			'fragment' => ['file' => 'f.json', 'app' => 'demo'],
			'object'   => ['key' => 'o1', 'register' => 'r', 'schema' => 's'],
			'row'      => $row,
		];
		// Without a refresh the installer refreshes only the references; with one it rewrites.
		$kept = ['references' => true];
		if ($refresh === true) {
			$kept = (new ExampleSetRewriter())->rewrite(run: $run, resolved: $resolved, report: $report, written: $written);
		}

		return [$kept, $report, $written];
	}//end keep()

	/**
	 * The write rules of a plain schema, or of one with kept fields and lenses.
	 *
	 * @param bool $appendOnly Whether the schema is append-only.
	 *
	 * @return array{appendOnly: bool, kept: array<int, string>, lenses: array<int, string>}
	 */
	private static function rules(bool $appendOnly=false): array {
		return ['appendOnly' => $appendOnly, 'kept' => ['number'], 'lenses' => ['view']];
	}//end rules()

	/**
	 * Without a refresh only the references are refreshed.
	 *
	 * @return void
	 */
	public function testWithoutRefreshOnlyTheReferences(): void {
		[$kept, $report, $written] = $this->keep(answers: [], row: ['id' => '1'], resolved: [], refresh: false);
		$this->assertSame(['references' => true], $kept);
		$this->assertSame(['types' => []], $report);
		$this->assertSame([], $written);
		$this->assertSame([], $this->calls);
	}//end testWithoutRefreshOnlyTheReferences()

	/**
	 * A refused declaration is dropped and the row stays.
	 *
	 * @return void
	 */
	public function testRefusedDeclarationIsDropped(): void {
		[$kept, $report, $written] = $this->keep(answers: [], row: ['id' => '1'], resolved: ['refusal' => 'bad', 'data' => [], 'sent' => []]);
		$this->assertSame(['id' => '1'], $kept);
		$this->assertSame(['types' => [], 'dropped' => ['f.json: object "o1": not refreshed: bad']], $report);
		$this->assertSame([], $written);
		$this->assertSame([], $this->calls);
	}//end testRefusedDeclarationIsDropped()

	/**
	 * A row of an append-only schema stays as written.
	 *
	 * @return void
	 */
	public function testAppendOnlyStays(): void {
		$resolved                  = ['refusal' => '', 'data' => ['a' => 2], 'sent' => ['a' => 2]];
		[$kept, $report, $written] = $this->keep(answers: ['rules' => self::rules(appendOnly: true)], row: ['id' => '1', 'a' => 1], resolved: $resolved);
		$this->assertSame(['id' => '1', 'a' => 1], $kept);
		$this->assertSame(['types' => []], $report);
		$this->assertSame([], $written);
		$this->assertSame(['writeRules r s'], $this->calls);
	}//end testAppendOnlyStays()

	/**
	 * A row that already holds the update is not written, but is counted and recorded as written; maps compare
	 * without key order, kept fields keep their stored value and lenses are left out.
	 *
	 * @return void
	 */
	public function testUnchangedIsCountedNotWritten(): void {
		$row      = ['id' => '1', '@self' => ['id' => '1'], 'a' => ['x' => 1, 'y' => 2], 'number' => 'N-1', 'gone' => null];
		$resolved = ['refusal' => '', 'data' => ['a' => ['y' => 2, 'x' => 1], 'number' => 'N-9', 'view' => 'v'], 'sent' => ['a' => ['y' => 2, 'x' => 1], 'number' => 'N-9', 'view' => 'v']];
		[$kept, $report, $written] = $this->keep(answers: ['rules' => self::rules()], row: $row, resolved: $resolved);
		$this->assertSame($row, $kept);
		$this->assertSame(['types' => ['r s' => ['unchanged' => 1]]], $report);
		$this->assertSame(['demo/o1' => ['a' => ['y' => 2, 'x' => 1]]], $written);
		$this->assertSame(['writeRules r s'], $this->calls);
	}//end testUnchangedIsCountedNotWritten()

	/**
	 * A changed row is written and read back; a declared owner other than the stored one gets it.
	 *
	 * @return void
	 */
	public function testChangedIsWrittenHandedOverAndReadBack(): void {
		$row      = ['@self' => ['id' => '7'], 'a' => 1, 'number' => 'N-1'];
		$resolved = ['refusal' => '', 'data' => ['a' => 2], 'sent' => ['a' => 2, 'number' => 'N-2', 'view' => 'v'], 'owner' => 'bob'];
		$answers  = ['rules' => self::rules(), 'ownerOf' => 'alice', 'assignOwner' => 'no such account', 'get' => ['id' => '7', 'a' => 2]];
		[$kept, $report, $written] = $this->keep(answers: $answers, row: $row, resolved: $resolved);
		$this->assertSame(['id' => '7', 'a' => 2], $kept);
		$this->assertSame(
			['types' => ['r s' => ['refreshed' => 1]], 'dropped' => ['f.json: object "o1": not handed to its owner: no such account']],
			$report
		);
		$this->assertSame(['demo/o1' => ['a' => 2]], $written);
		$this->assertSame(
			['writeRules r s', 'update r s 7 {"@self":{"id":"7"},"a":2,"number":"N-1"}', 'ownerOf 7', 'assignOwner 7 bob', 'get 7'],
			$this->calls
		);
	}//end testChangedIsWrittenHandedOverAndReadBack()

	/**
	 * An owner that already owns the row is left alone, and a row that cannot be read back is returned as it was.
	 *
	 * @return void
	 */
	public function testSameOwnerAndNoReadBack(): void {
		$resolved = ['refusal' => '', 'data' => ['a' => 2], 'sent' => ['a' => 2], 'owner' => 'alice'];
		[$kept, $report] = $this->keep(answers: ['rules' => self::rules(), 'ownerOf' => 'alice'], row: ['id' => '7', 'a' => 1], resolved: $resolved);
		$this->assertSame(['id' => '7', 'a' => 1], $kept);
		$this->assertSame(['types' => ['r s' => ['refreshed' => 1]]], $report);
		$this->assertSame(['writeRules r s', 'update r s 7 {"id":"7","a":2}', 'ownerOf 7', 'get 7'], $this->calls);
	}//end testSameOwnerAndNoReadBack()

	/**
	 * A failed update is dropped, and nothing more is done.
	 *
	 * @return void
	 */
	public function testFailedUpdateIsDropped(): void {
		$resolved                  = ['refusal' => '', 'data' => ['a' => 2], 'sent' => ['a' => 2], 'owner' => 'bob'];
		[$kept, $report, $written] = $this->keep(answers: ['rules' => self::rules(), 'update' => 'locked'], row: ['id' => '7', 'a' => 1], resolved: $resolved);
		$this->assertSame(['id' => '7', 'a' => 1], $kept);
		$this->assertSame(['types' => [], 'dropped' => ['f.json: object "o1": not refreshed: locked']], $report);
		$this->assertSame([], $written);
		$this->assertSame(['writeRules r s', 'update r s 7 {"id":"7","a":2}'], $this->calls);
	}//end testFailedUpdateIsDropped()

	/**
	 * The record follows what a rewrite wrote: reference values and the references change; it is kept only when it
	 * changed.
	 *
	 * @return void
	 */
	public function testFollow(): void {
		$record = [
			'objects' => ['demo/o1' => ['refs' => ['owner' => 'old', 'same' => 'x', 'other' => 'y']]],
			'changes' => ['demo/o1#references' => ['set' => [['path' => 'owner', 'new' => 'old', 'had' => 'h'], ['path' => 'missing', 'new' => 'z']]]],
		];
		$saved  = [];
		$save   = function (array $kept) use (&$saved): void {
			$saved[] = $kept;
		};
		$rewriter = new ExampleSetRewriter();
		$rewriter->follow(record: $record, name: 'demo/o1', written: ['demo/o1' => ['owner' => 'new', 'same' => 'x']], save: $save);
		$this->assertSame(['owner' => 'new', 'same' => 'x', 'other' => 'y'], $record['objects']['demo/o1']['refs']);
		$this->assertSame([['path' => 'owner', 'new' => 'new', 'had' => 'h'], ['path' => 'missing', 'new' => 'z']], $record['changes']['demo/o1#references']['set']);
		$this->assertSame([$record], $saved);

		$rewriter->follow(record: $record, name: 'demo/o1', written: ['demo/o1' => ['owner' => 'new']], save: $save);
		$rewriter->follow(record: $record, name: 'demo/other', written: ['demo/o1' => ['owner' => 'newer']], save: $save);
		$this->assertCount(1, $saved);
	}//end testFollow()
}//end class
