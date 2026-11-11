<?php

/**
 * Golden (characterisation) matrix for TaskSubjectAccessGuard.
 *
 * Pins which payloads are ADMITTED and which are REFUSED, the exact
 * refusal message, the exact read each subject is checked with
 * (`_rbac: true`, `_multitenancy: true`), the order subjects are checked in
 * and where checking stops, so the guard can be restructured without
 * moving a decision.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Task\TaskSubjectAccessGuard;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch of assertReadable(), pinned.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskSubjectAccessGuard
 * @uses \OCA\OpenRegister\Exception\TaskSubjectNotFoundException
 */
final class TaskSubjectAccessGuardGoldenTest extends TestCase {

	private const ALLOWED = 'allowed';

	/**
	 * The reads the guard made, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * An object read path that answers per uuid: `ok` finds, `none` returns
	 * null, `deny` throws DoesNotExistException, `boom` throws a RuntimeException.
	 *
	 * @return ObjectOperationsInterface The read path.
	 */
	private function objects(): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args): ?ObjectEntity {
				[$id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit] = FindCallShape::find(args: $args);
				$this->reads[] = [
					'id' => $id,
					'files' => $files,
					'register' => $register,
					'schema' => $schema,
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
					'_render' => $_render,
					'_audit' => $_audit,
				];
				$id = (string)$id;
				if (str_starts_with($id, 'deny') === true) {
					throw new DoesNotExistException('nope');
				}

				if (str_starts_with($id, 'boom') === true) {
					throw new RuntimeException('database gone');
				}

				if (str_starts_with($id, 'none') === true) {
					return null;
				}

				return new ObjectEntity();
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Run the guard and report `allowed` or the refusal message.
	 *
	 * @param TaskSubjectAccessGuard $guard The guard.
	 * @param array<string, mixed>   $data  The payload.
	 *
	 * @return string The outcome.
	 */
	private function outcome(TaskSubjectAccessGuard $guard, array $data): string {
		try {
			$guard->assertReadable(data: $data);
		} catch (TaskSubjectNotFoundException $refused) {
			return $refused->getMessage();
		}

		return self::ALLOWED;
	}//end outcome()

	/**
	 * The payloads, their outcome and the reads they cause (id, register, schema).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<int, array{0: string, 1: int|null, 2: int|null}>}>
	 */
	public static function cases(): array {
		return [
			'standalone task' => [[], self::ALLOWED, []],
			'blank anchor' => [['objectUuid' => '  ', 'relations' => 'nope'], self::ALLOWED, []],
			'readable anchor' => [['objectUuid' => 'ok-1', 'registerId' => '4', 'schemaId' => 7], self::ALLOWED, [['ok-1', 4, 7]]],
			'trimmed anchor, non-numeric scope' => [
				['objectUuid' => ' ok-1 ', 'registerId' => 'abc', 'schemaId' => null],
				self::ALLOWED,
				[['ok-1', null, null]],
			],
			'float-like scope' => [['objectUuid' => 'ok-1', 'registerId' => '4.9', 'schemaId' => true], self::ALLOWED, [['ok-1', 4, null]]],
			'anchor not found' => [['objectUuid' => 'none-1'], 'Object with id none-1 not found', [['none-1', null, null]]],
			'anchor refused' => [['objectUuid' => 'deny-1'], 'Object with id deny-1 not found', [['deny-1', null, null]]],
			'anchor read breaks' => [['objectUuid' => 'boom-1'], 'Object with id boom-1 not found', [['boom-1', null, null]]],
			'relations checked after anchor' => [
				[
					'objectUuid' => 'ok-1',
					'relations' => [
						'skip-me',
						['objectUuid' => ''],
						['objectUuid' => 'ok-1', 'registerId' => 9],
						['objectUuid' => ' ok-2 ', 'registerId' => 3, 'schemaId' => '5'],
						['registerId' => 1],
					],
				],
				self::ALLOWED,
				[['ok-1', null, null], ['ok-2', 3, 5]],
			],
			'relation refused, checking stops' => [
				[
					'relations' => [
						['objectUuid' => 'ok-2'],
						['objectUuid' => 'deny-3'],
						['objectUuid' => 'ok-4'],
					],
				],
				'Object with id deny-3 not found',
				[['ok-2', null, null], ['deny-3', null, null]],
			],
			'refused anchor stops before relations' => [
				['objectUuid' => 'deny-1', 'relations' => [['objectUuid' => 'ok-2']]],
				'Object with id deny-1 not found',
				[['deny-1', null, null]],
			],
			'relations only, map keyed' => [
				['relations' => ['a' => ['objectUuid' => 'ok-5']]],
				self::ALLOWED,
				[['ok-5', null, null]],
			],
		];
	}//end cases()

	/**
	 * Every payload's outcome and reads are pinned.
	 *
	 * @param array<string, mixed>                                $data     The payload.
	 * @param string                                              $expected `allowed` or the refusal message.
	 * @param array<int, array{0: string, 1: int|null, 2: int|null}> $reads   The expected reads.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testOutcomeAndReads(array $data, string $expected, array $reads): void {
		$logger = $this->createMock(LoggerInterface::class);
		$guard = new TaskSubjectAccessGuard(objects: $this->objects(), logger: $logger);

		$this->assertSame($expected, $this->outcome(guard: $guard, data: $data));

		$seen = [];
		foreach ($this->reads as $read) {
			$this->assertSame(false, $read['files']);
			$this->assertSame(true, $read['_rbac']);
			$this->assertSame(true, $read['_multitenancy']);
			$this->assertSame(false, $read['_render']);
			$this->assertSame(false, $read['_audit']);
			$seen[] = [$read['id'], $read['register'], $read['schema']];
		}

		$this->assertSame($reads, $seen);
	}//end testOutcomeAndReads()

	/**
	 * A read that BREAKS (anything thrown) is logged at debug and refused.
	 *
	 * @return void
	 */
	public function testABrokenReadIsLoggedAndRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[TaskSubjectAccessGuard] Subject read did not answer: database gone',
			$this->callback(static fn (array $context): bool => $context['uuid'] === 'boom-1' && $context['exception'] instanceof RuntimeException)
		);
		$guard = new TaskSubjectAccessGuard(objects: $this->objects(), logger: $logger);

		$this->assertSame('Object with id boom-1 not found', $this->outcome(guard: $guard, data: ['objectUuid' => 'boom-1']));
	}//end testABrokenReadIsLoggedAndRefused()

	/**
	 * Without a read path, a named subject is refused; no subject is admitted.
	 *
	 * @return void
	 */
	public function testNoReadPathRefusesANamedSubject(): void {
		$guard = new TaskSubjectAccessGuard();

		$this->assertSame('Object with id x-1 not found', $this->outcome(guard: $guard, data: ['objectUuid' => 'x-1']));
		$this->assertSame('Object with id x-2 not found', $this->outcome(guard: $guard, data: ['relations' => [['objectUuid' => 'x-2']]]));
		$this->assertSame(self::ALLOWED, $this->outcome(guard: $guard, data: ['objectUuid' => '']));
	}//end testNoReadPathRefusesANamedSubject()
}//end class
