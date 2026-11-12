<?php

/**
 * Golden: which grants an example set may make and which it refuses, without and with the set's objects, groups
 * and install day, pinned on the exact refusal.
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants;
use OCA\OpenRegister\Service\ExampleSet\FilledRow;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 */
class ExampleSetGrantsGoldenTest extends TestCase {
	/**
	 * A grant row, the set's accounts and the run's context, and the exact refusal without and with the context
	 * ('' when the grant may be made).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>, 3: string, 4: string}>
	 */
	public static function cases(): array {
		$ctx = ['objects' => ['a' => ['uuid' => 'obj-1'], 'b' => ['id' => 'obj-2']], 'setGroups' => ['staff', 3, 'board'], 'today' => new DateTimeImmutable('2026-03-10 09:00', new DateTimeZone('UTC'))];
		$ok = ['object' => 'obj-1', 'type' => 'user', 'shareWith' => 'bob', 'sharedBy' => 'alice'];
		$u = ['alice', 'bob'];
		$cases = [
		 'unknown field' => [['colour' => 1] + $ok, $u, $ctx],
		 'no type' => [array_diff_key($ok, ['type' => 1]), $u, $ctx],
		 'bad type' => [['type' => 'role'] + $ok, $u, $ctx],
		 'no sharer' => [array_diff_key($ok, ['sharedBy' => 1]), $u, $ctx],
		 'stranger sharer' => [['sharedBy' => 'eve'] + $ok, $u, $ctx],
		 'sharer not text' => [['sharedBy' => 5] + $ok, $u, $ctx],
		 'permissions zero' => [['permissions' => 0] + $ok, $u, $ctx],
		 'permissions 32' => [['permissions' => 32] + $ok, $u, $ctx],
		 'permissions text' => [['permissions' => '3'] + $ok, $u, $ctx],
		 'permissions 31' => [['permissions' => 31] + $ok, $u, $ctx],
		 'verb blank' => [['verbs' => ['read', ' ']] + $ok, $u, $ctx],
		 'verb number' => [['verbs' => [3]] + $ok, $u, $ctx],
		 'verbs fine' => [['verbs' => ['read', 'write']] + $ok, $u, $ctx],
		 'object not of set' => [['object' => 'obj-9'] + $ok, $u, $ctx],
		 'object by id' => [['object' => 'obj-2'] + $ok, $u, $ctx],
		 'object missing' => [array_diff_key($ok, ['object' => 1]), $u, $ctx],
		 'user stranger' => [['shareWith' => 'eve'] + $ok, $u, $ctx],
		 'user missing' => [array_diff_key($ok, ['shareWith' => 1]), $u, $ctx],
		 'group of set' => [['type' => 'group', 'shareWith' => 'board'] + $ok, $u, $ctx],
		 'group not of set' => [['type' => 'group', 'shareWith' => 'bob'] + $ok, $u, $ctx],
		 'group number' => [['type' => 'group', 'shareWith' => 3] + $ok, $u, $ctx],
		 'group no groups' => [['type' => 'group', 'shareWith' => 'staff'] + $ok, $u, ['objects' => $ctx['objects']]],
		 'shared at past' => [['sharedAt' => '2026-01-01T10:00:00Z'] + $ok, $u, $ctx],
		 'shared at bad' => [['sharedAt' => 'soon'] + $ok, $u, $ctx],
		 'shared at blank' => [['sharedAt' => ''] + $ok, $u, $ctx],
		 'expires past' => [['expiresAt' => '2026-03-09T23:00:00Z'] + $ok, $u, $ctx],
		 'expires today' => [['expiresAt' => '2026-03-10T01:00:00Z'] + $ok, $u, $ctx],
		 'expires bad' => [['expiresAt' => 'never'] + $ok, $u, $ctx],
		 'both bad shared first' => [['sharedAt' => 'x1x', 'expiresAt' => 'never'] + $ok, $u, $ctx],
		 'expires null' => [['expiresAt' => null] + $ok, $u, $ctx],
		 'fine' => [['permissions' => 3, 'verbs' => ['read'], 'sharedAt' => '2026-03-01T10:00:00Z', 'expiresAt' => '2026-04-01T10:00:00Z'] + $ok, $u, $ctx],
		];
		$expected = [
			'unknown field' => ['field "colour" is not a grant field a set may write', 'field "colour" is not a grant field a set may write'],
			'no type' => ['type null is not user or group', 'type null is not user or group'],
			'bad type' => ['type "role" is not user or group', 'type "role" is not user or group'],
			'no sharer' => ['sharedBy null is no account the set creates or adopts', 'sharedBy null is no account the set creates or adopts'],
			'stranger sharer' => ['sharedBy "eve" is no account the set creates or adopts', 'sharedBy "eve" is no account the set creates or adopts'],
			'sharer not text' => ['sharedBy 5 is no account the set creates or adopts', 'sharedBy 5 is no account the set creates or adopts'],
			'permissions zero' => ['permissions 0 is not a number from 1 to 31', 'permissions 0 is not a number from 1 to 31'],
			'permissions 32' => ['permissions 32 is not a number from 1 to 31', 'permissions 32 is not a number from 1 to 31'],
			'permissions text' => ['permissions "3" is not a number from 1 to 31', 'permissions "3" is not a number from 1 to 31'],
			'permissions 31' => ['', ''],
			'verb blank' => ['verb " " is not a word', 'verb " " is not a word'],
			'verb number' => ['verb 3 is not a word', 'verb 3 is not a word'],
			'verbs fine' => ['', ''],
			'object not of set' => ['', 'object "obj-9" is not an object the set writes'],
			'object by id' => ['', ''],
			'object missing' => ['', 'object null is not an object the set writes'],
			'user stranger' => ['', 'shareWith "eve" is no user of the set'],
			'user missing' => ['', 'shareWith null is no user of the set'],
			'group of set' => ['', ''],
			'group not of set' => ['', 'shareWith "bob" is no group of the set'],
			'group number' => ['', 'shareWith 3 is no group of the set'],
			'group no groups' => ['', 'shareWith "staff" is no group of the set'],
			'shared at past' => ['', ''],
			'shared at bad' => ['', 'sharedAt "soon" is not a date'],
			'shared at blank' => ['', 'sharedAt is not a date'],
			'expires past' => ['', 'expiresAt "2026-03-09T23:00:00Z" lies before the install day'],
			'expires today' => ['', ''],
			'expires bad' => ['', 'expiresAt "never" is not a date'],
			'both bad shared first' => ['', 'sharedAt "x1x" is not a date'],
			'expires null' => ['', ''],
			'fine' => ['', ''],
		];
		foreach ($cases as $name => $case) {
			$cases[$name] = [...$case, ...$expected[$name]];
		}

		return $cases;
	}//end cases()

	/**
	 * A grant gets exactly its refusal, and the check reads nothing from the container.
	 *
	 * @param array<string, mixed> $data      The filled grant fields.
	 * @param array<int, string>   $users     The account ids the set creates or adopts.
	 * @param array<string, mixed> $context   The context: objects, groups, install day.
	 * @param string               $refusal   What refusal() answers.
	 * @param string               $refusalIn What refusalIn() answers.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRefusal(array $data, array $users, array $context, string $refusal, string $refusalIn): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$grants = new ExampleSetGrants(container: $container);
		$this->assertSame($refusal, $grants->refusal(data: $data, users: $users));
		$this->assertSame($refusalIn, (new ReflectionMethod($grants, 'refusalIn'))->invoke($grants, new FilledRow(data: $data, users: $users), $context));
	}//end testRefusal()
}//end class
