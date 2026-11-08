<?php

/**
 * Golden: which audit entries an example set may write and which it refuses, pinned on exact messages.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-its-objects-a-history-by-named-people
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 */
class ExampleSetAuditEntriesGoldenTest extends TestCase {
	/**
	 * A row of each kind and the exact refusal it gets ('' when it may be written).
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, string>, 3: string}>
	 */
	public static function cases(): array {
		return [
			'unknown field' => ['AuditEntries', ['colour' => 1, 'object' => 'o', 'action' => 'update', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], 'field "colour" is not an audit entry field a set may write'],
			'no object' => ['AuditEntries', ['action' => 'update', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], 'an audit entry needs the object it is on (object)'],
			'blank action' => ['AuditEntries', ['action' => ' ', 'object' => 'o', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], 'an audit entry needs an action (action)'],
			'no user' => ['AuditEntries', ['object' => 'o', 'action' => 'update', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], 'an audit entry needs the person who acted (user)'],
			'at number' => ['AuditEntries', ['at' => 5, 'object' => 'o', 'action' => 'update', 'user' => 'alice'], ['alice', 'bob'], 'an audit entry needs a moment (at)'],
			'stranger' => ['AuditEntries', ['user' => 'eve', 'object' => 'o', 'action' => 'update', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], '"eve" is not an account the set creates or adopts'],
			'changed text' => ['AuditEntries', ['changed' => 'x', 'object' => 'o', 'action' => 'update', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], 'changed is an object'],
			'changed null' => ['AuditEntries', ['changed' => NULL, 'object' => 'o', 'action' => 'update', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], ''],
			'bad at' => ['AuditEntries', ['at' => 'yesterday-ish', 'object' => 'o', 'action' => 'update', 'user' => 'alice'], ['alice', 'bob'], 'at "yesterday-ish" is not a date and time'],
			'fine' => ['AuditEntries', ['changed' => ['a' => ['old' => 1, 'new' => 2]], 'object' => 'o', 'action' => 'update', 'user' => 'alice', 'at' => '2026-01-01T10:00:00Z'], ['alice', 'bob'], ''],
		];
	}//end cases()

	/**
	 * A row gets exactly its refusal; the check reads nothing from the container.
	 *
	 * @param string               $kind     The kind, by class name after `ExampleSet`.
	 * @param array<string, mixed> $data     The filled fields.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRefusal(string $kind, array $data, array $users, string $expected): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$class = 'OCA\\OpenRegister\\Service\\ExampleSet\\ExampleSet'.$kind;
		$this->assertSame($expected, (new $class(container: $container))->refusal(data: $data, users: $users));
	}//end testRefusal()
}//end class
