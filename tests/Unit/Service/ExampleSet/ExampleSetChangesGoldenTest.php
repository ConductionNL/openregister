<?php

/**
 * Golden test for ExampleSetChanges: what resolve(), apply(), follow(),
 * refresh(), lost(), revert(), writtenReferences() and asSent() answer, and
 * what the store holds afterwards, for a fixed table of changes and stored
 * objects that reaches every branch. Re-record with UPDATE_GOLDEN=1.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake\FakeExampleSetStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 */
class ExampleSetChangesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-changes-golden.json';

	private const PORTAL = ['register' => 'site', 'schema' => 'portal'];

	/**
	 * @var FakeExampleSetStore The store of the running case.
	 */
	private FakeExampleSetStore $store;

	/**
	 * @var ExampleSetChanges The changes of the running case.
	 */
	private ExampleSetChanges $changes;

	public function testChangesMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->store   = new FakeExampleSetStore();
			$this->changes = new ExampleSetChanges($this->store, new ExampleSetValues());
			$this->store->offer('site', 'portal');
			$answer        = $case();
			$actual[$name] = ['answer' => $answer, 'rows' => $this->store->rows];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	/**
	 * A fixed context: no dependency on the day the test runs.
	 *
	 * @param array<string, mixed> $objects The fragment's objects by key.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $objects=[]): array {
		return [
			'cast'        => ['anna' => ['uid' => 'anna']],
			'objects'     => $objects,
			'accessLinks' => ['link' => ['url' => 'https://example.test/s/secret-anchor']],
			'today'       => new DateTimeImmutable('2026-03-02', new DateTimeZone('Europe/Amsterdam')),
		];
	}

	private function seed(array $data): string {
		return $this->store->seed('site', 'portal', $data);
	}

	private function change(array $extra): array {
		return array_merge(self::PORTAL, $extra);
	}

	private function resolveAndApply(array $change, array $context): array {
		$resolved = $this->changes->resolve($change, $context);
		if ($resolved['refusal'] !== '') {
			return ['resolved' => $resolved];
		}

		return ['resolved' => $resolved, 'applied' => $this->changes->apply($change, $resolved)];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function cases(): array {
		return array_merge(
			$this->resolveCases(),
			$this->applyCases(),
			$this->followCases(),
			$this->refreshCases(),
			$this->lostCases(),
			$this->revertCases(),
			$this->otherCases()
		);
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function resolveCases(): array {
		return [
			'resolve gap'                 => fn () => $this->changes->resolve(
				$this->change(['where' => ['slug' => '{{cast:nobody.uid}}'], 'set' => ['a' => '{{cast:ghost.uid}}']]),
				$this->context()
			),
			'resolve two rows'            => function () {
				$this->seed(['slug' => 'p']);
				$this->seed(['slug' => 'p']);
				return $this->changes->resolve($this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 1]]), $this->context());
			},
			'resolve object not earlier'  => fn () => $this->changes->resolve($this->change(['object' => 'mine', 'set' => ['a' => 1]]), $this->context()),
			'resolve no match'            => function () {
				$this->seed(['slug' => 'q']);
				return $this->changes->resolve($this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 1]]), $this->context());
			},
			'resolve empty where'         => function () {
				$this->seed(['slug' => 'q']);
				return $this->changes->resolve($this->change(['set' => ['a' => 1]]), $this->context());
			},
			'resolve object earlier'      => fn () => $this->changes->resolve(
				$this->change(['object' => 'mine', 'set' => ['b.c' => '{{cast:anna.uid}}'], 'append' => ['tags' => 'x']]),
				$this->context(['mine' => ['id' => 'm1', 'slug' => 'mine', 'tags' => ['x', 'y']]])
			),
			'resolve set append json'     => function () {
				$this->seed(['slug' => 'p', 'auth' => '{"modes":["a"]}', 'meta' => ['k' => 1], 'tags' => ['y']]);
				$this->store->textFields['site portal'] = ['meta'];
				return $this->changes->resolve(
					$this->change(
						[
							'where'      => ['slug' => 'p'],
							'jsonFields' => ['auth', 'auth', 3],
							'set'        => ['auth.mode' => 'b', 'meta.k' => 2, 'deep.er.path' => 'z'],
							'append'     => ['auth.modes' => 'b', 'tags' => 'y'],
						]
					),
					$this->context()
				);
			},
		];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function applyCases(): array {
		return [
			'apply nothing differs'  => function () {
				$this->seed(['slug' => 'p', 'a' => 1, 'tags' => ['x']]);
				return $this->resolveAndApply($this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 1], 'append' => ['tags' => 'x']]), $this->context());
			},
			'apply writes'           => function () {
				$this->seed(['slug' => 'p', 'a' => 1, 'auth' => '{"modes":["a"]}', 'tags' => ['x']]);
				return $this->resolveAndApply(
					$this->change(
						[
							'where'      => ['slug' => 'p'],
							'jsonFields' => ['auth'],
							'set'        => ['a' => 2, 'auth.mode' => 'b', 'n.e.w' => 'v'],
							'append'     => ['tags' => 'y', 'auth.modes' => 'b'],
						]
					),
					$this->context()
				);
			},
			'apply fingerprint'      => function () {
				$this->seed(['slug' => 'p']);
				$set = ['url' => '{{accessLink:link.url}}', 'label' => 'Go {{accessLink:link.url}}'];
				return $this->resolveAndApply($this->change(['where' => ['slug' => 'p'], 'set' => $set]), $this->context());
			},
			'apply update error'     => function () {
				$this->store->textFields['site portal'] = ['a'];
				$this->seed(['slug' => 'p', 'a' => 'x']);
				return $this->resolveAndApply($this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 5]]), $this->context());
			},
		];
	}

	private function heldEntry(array $change, array $row): array {
		$this->seed($row);
		$resolved = $this->changes->resolve($change, $this->context());
		return (array) $this->changes->apply($change, $resolved)['entry'];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function followCases(): array {
		$change = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 2, 'b' => 3], 'append' => ['tags' => 'y']]);
		return [
			'follow nothing'          => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				return $this->changes->follow($change, $entry, $this->context(), false);
			},
			'follow dropped path'     => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				$less  = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 2]]);
				return $this->changes->follow($less, $entry, $this->context(), false);
			},
			'follow gained path'      => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'c' => 0, 'd' => 4, 'tags' => []]);
				$more  = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 2, 'b' => 3, 'c' => 9, 'd' => 4], 'append' => ['tags' => 'y']]);
				return $this->changes->follow($more, $entry, $this->context(), false);
			},
			'follow refusal'          => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				$gone  = $this->change(['where' => ['slug' => 'nope'], 'set' => ['a' => 2, 'b' => 3, 'c' => 1]]);
				return $this->changes->follow($gone, $entry, $this->context(), false);
			},
			'follow refresh undone'   => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				$id    = (string) $entry['id'];
				$this->store->rows['site portal'][$id]['a'] = 1;
				$this->store->rows['site portal'][$id]['b'] = 7;
				return $this->changes->follow($change, $entry, $this->context(), true);
			},
			'follow refresh moved'    => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				$newer = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 5, 'b' => 3], 'append' => ['tags' => 'y']]);
				return $this->changes->follow($newer, $entry, $this->context(), true);
			},
			'follow refresh null'     => function () {
				$nulls = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 2]]);
				$entry = $this->heldEntry($nulls, ['slug' => 'p']);
				$id    = (string) $entry['id'];
				$this->store->rows['site portal'][$id]['a'] = null;
				return $this->changes->follow($nulls, $entry, $this->context(), true);
			},
			'follow refresh in step'  => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'b' => 1, 'tags' => []]);
				return $this->changes->follow($change, $entry, $this->context(), true);
			},
			'follow update error'     => function () {
				$this->store->textFields['site portal'] = ['a', 'b'];
				$text  = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 'two']]);
				$entry = $this->heldEntry($text, ['slug' => 'p', 'a' => 'one', 'b' => 'x']);
				$more  = $this->change(['where' => ['slug' => 'p'], 'set' => ['a' => 'two', 'b' => 5]]);
				return $this->changes->follow($more, $entry, $this->context(), false);
			},
			'follow fingerprint'      => function () {
				$link  = $this->change(['where' => ['slug' => 'p'], 'set' => ['url' => '{{accessLink:link.url}}']]);
				$entry = $this->heldEntry($link, ['slug' => 'p', 'url' => 'old']);
				$id    = (string) $entry['id'];
				$this->store->rows['site portal'][$id]['url'] = 'old';
				return $this->changes->follow($link, $entry, $this->context(), true);
			},
		];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function refreshCases(): array {
		return [
			'refresh mixed'     => function () {
				$id  = $this->seed(['a' => 1, 'b' => 'old', 'c' => 'edited', 'd' => '{"x":1}']);
				$row = (array) $this->store->get('site', 'portal', $id);
				return $this->changes->refresh(
					['register' => 'site', 'schema' => 'portal', 'jsonFields' => ['d']],
					$row,
					['a' => 1, 'b' => 'new', 'c' => 'mine', 'd' => ['x' => 2], 'e' => 'fresh'],
					['b' => 'old', 'c' => 'written', 'd' => ['x' => 1]]
				);
			},
			'refresh nothing'   => function () {
				$id  = $this->seed(['a' => 1]);
				$row = (array) $this->store->get('site', 'portal', $id);
				return $this->changes->refresh(['register' => 'site', 'schema' => 'portal'], $row, ['a' => 1]);
			},
			'refresh structure' => function () {
				$this->store->textFields['site portal'] = ['t'];
				$id  = $this->seed(['t' => ['k' => 1]]);
				$row = (array) $this->store->get('site', 'portal', $id);
				return $this->changes->refresh(['register' => 'site', 'schema' => 'portal'], $row, ['t' => ['k' => 2]], ['t' => ['k' => 1]]);
			},
		];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function lostCases(): array {
		$set    = ['a' => 2, 'j.k' => 1, 'url' => '{{accessLink:link.url}}'];
		$change = $this->change(['where' => ['slug' => 'p'], 'jsonFields' => ['j'], 'set' => $set, 'append' => ['tags' => 'y']]);
		return [
			'lost gone'      => fn () => $this->changes->lost(['register' => 'site', 'schema' => 'portal', 'id' => 'nope', 'set' => [], 'append' => []]),
			'lost nothing'   => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'j' => '{"k":0}', 'tags' => []]);
				return $this->changes->lost($entry);
			},
			'lost all'       => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'j' => '{"k":0}', 'tags' => []]);
				$id    = (string) $entry['id'];
				$this->store->rows['site portal'][$id] = ['id' => $id, 'slug' => 'p', 'a' => 3, 'j' => '{"k":5}', 'url' => 'other', 'tags' => []];
				return $this->changes->lost($entry);
			},
			'lost url gone'  => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'j' => '{"k":0}', 'tags' => []]);
				$id    = (string) $entry['id'];
				unset($this->store->rows['site portal'][$id]['url']);
				$this->store->rows['site portal'][$id]['a'] = '2.0';
				return $this->changes->lost($entry);
			},
		];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function revertCases(): array {
		$set    = ['a' => 2, 'n.e.w' => 'v', 'k.x' => 1, 'url' => '{{accessLink:link.url}}'];
		$change = $this->change(['where' => ['slug' => 'p'], 'set' => $set, 'append' => ['tags' => 'y']]);
		return [
			'revert unreadable' => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'k' => ['z' => 0], 'tags' => ['x']]);
				$this->store->unreadable[] = (string) $entry['id'];
				return $this->changes->revert($entry);
			},
			'revert gone'       => fn () => $this->changes->revert(['register' => 'site', 'schema' => 'portal', 'id' => 'nope', 'set' => [], 'append' => []]),
			'revert all'        => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'k' => ['z' => 0], 'tags' => ['x']]);
				return $this->changes->revert($entry);
			},
			'revert edited'     => function () use ($change) {
				$entry = $this->heldEntry($change, ['slug' => 'p', 'a' => 1, 'k' => ['z' => 0], 'tags' => ['x']]);
				$id    = (string) $entry['id'];
				$this->store->rows['site portal'][$id]['a']   = 9;
				$this->store->rows['site portal'][$id]['url'] = 'changed';
				$this->store->rows['site portal'][$id]['n']   = ['e' => ['w' => 'v'], 'o' => 1];
				$this->store->rows['site portal'][$id]['tags'] = ['y', 'x', 'y'];
				return $this->changes->revert($entry);
			},
			'revert json'       => function () {
				$json  = $this->change(['where' => ['slug' => 'p'], 'jsonFields' => ['j'], 'set' => ['j.k' => 1, 't.m' => 2]]);
				$entry = $this->heldEntry($json, ['slug' => 'p', 'j' => '{"k":0}', 't' => ['m' => 0]]);
				$this->store->textFields['site portal'] = ['t'];
				return [$entry, $this->changes->revert($entry)];
			},
		];
	}

	/**
	 * @return array<string, callable(): mixed>
	 */
	private function otherCases(): array {
		return [
			'written references' => fn () => [
				$this->changes->writtenReferences(
					[
						'objects' => ['app/k' => ['refs' => ['a' => 1, 'b' => 2]]],
						'changes' => ['app/k#references' => ['set' => [['path' => 'b', 'new' => 3], ['path' => 'c', 'new' => 4]]]],
					],
					'app/k#references',
					'app/k'
				),
				$this->changes->writtenReferences([], 'x', 'y'),
			],
			'as sent'            => fn () => $this->changes->asSent(['a' => ['b' => 1], 'c' => 'd', 'e' => ['f']], ['a', 'c', 'zz']),
		];
	}
}
