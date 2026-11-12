<?php

/**
 * Behaviour tests for DecisionTableBulkGuard: which rows of a bulk save are
 * decision tables, and what refuses them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Dmn
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;


use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\DecisionTableValidationListenerInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the bulk-save guard for decision tables.
 */
class DecisionTableBulkGuardBehaviourTest extends TestCase {

	/** @var DecisionTableValidationListenerInterface&MockObject */
	private DecisionTableValidationListenerInterface $listener;

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	protected function setUp(): void {
		$this->listener  = $this->createMock(DecisionTableValidationListenerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with(DecisionTableValidationListenerInterface::class)->willReturn($this->listener);
	}//end setUp()

	private function guard(): DecisionTableBulkGuard {
		return new DecisionTableBulkGuard($this->container);
	}//end guard()

	public function testRowsOfAnotherSchemaAreNotValidated(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(false);
		$this->listener->expects($this->never())->method('problemsOf');

		$this->assertSame([], $this->guard()->refusals([['key' => 'a'], ['key' => 'a']], 'reg', 'other'));
	}

	public function testTheTableCheckIsAskedOncePerSchemaAndRegisterPair(): void {
		$this->container->expects($this->once())->method('get');
		$this->listener->expects($this->exactly(2))->method('isDecisionTableRef')->willReturnMap(
			[
				['5', '1', false],
				['9', '1', false],
			]
		);

		$rows = [['x' => 1], ['x' => 2], ['@self' => ['schema' => 9]], ['@self' => ['schema' => '9']]];

		$this->assertSame([], $this->guard()->refusals($rows, 1, 5));
	}

	public function testSchemaAndRegisterEntitiesAreReducedToTheirIds(): void {
		$schema = new Schema();
		$schema->setId(5);
		$register = new Register();
		$register->setId(1);
		$this->listener->expects($this->once())->method('isDecisionTableRef')->with('5', '1')->willReturn(false);

		$this->guard()->refusals([['x' => 1]], $register, $schema);
	}

	public function testEmptyReferencesBecomeNullAndRowSelfOverridesTheDefault(): void {
		$seen = [];
		$this->listener->method('isDecisionTableRef')->willReturnCallback(
			static function (?string $schema, ?string $register) use (&$seen): bool {
				$seen[] = [$schema, $register];
				return false;
			}
		);

		$this->guard()->refusals(
			[['x' => 1], ['@self' => ['schema' => '0', 'register' => '']], ['@self' => ['schema' => 7, 'register' => 3]], ['@self' => 'junk']],
			0,
			null
		);

		$this->assertSame([[null, null], ['7', '3']], $seen);
	}

	public function testProblemsOfATableRowRefuseItWithoutItsSelfBlock(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$this->listener->expects($this->exactly(2))->method('problemsOf')->willReturnCallback(
			static function (ObjectRef $row, array $data): ?array {
				$uuid = $row->id;
				if ($uuid === 'u-1') {
					return ['no rules', 'no inputs'];
				}

				return null;
			}
		);

		$refusals = $this->guard()->refusals(
			[
				3 => ['@self' => ['uuid' => 'u-1'], 'key' => 'k1'],
				4 => ['id' => 'u-2', 'key' => 'k2'],
			],
			'r',
			's'
		);

		$this->assertSame([3 => 'The decision table cannot be saved: no rules; no inputs.'], $refusals);
	}

	public function testDataPassedOnHasNoSelfBlockAndTheUuidComesFromSelfIdThenRowId(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$calls = [];
		$this->listener->method('problemsOf')->willReturnCallback(
			static function (ObjectRef $row, array $data) use (&$calls): array {
				$uuid = $row->id;
				$calls[] = [$data, $uuid];
				return [];
			}
		);

		$this->guard()->refusals(
			[['@self' => ['id' => 'self-id'], 'key' => ''], ['id' => 'row-id'], ['id' => 42]],
			'r',
			's'
		);

		$this->assertSame([[['key' => ''], 'self-id'], [['id' => 'row-id'], 'row-id'], [['id' => 42], '']], $calls);
	}

	public function testTwoRowsClaimingOneKeyRefuseTheSecond(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$this->listener->method('problemsOf')->willReturn([]);

		$refusals = $this->guard()->refusals(
			[['id' => 'a', 'key' => 'tarief'], ['id' => 'b', 'key' => ' tarief '], ['id' => 'c', 'key' => 'ander']],
			'r',
			's'
		);

		$this->assertSame([1 => 'The decision table cannot be saved: the key "tarief" is claimed by another row of the same save.'], $refusals);
	}

	public function testTheSameRowRepeatedWithItsUuidMayKeepItsKey(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$this->listener->method('problemsOf')->willReturn([]);

		$this->assertSame([], $this->guard()->refusals([['id' => 'a', 'key' => 'k'], ['id' => 'a', 'key' => 'k']], 'r', 's'));
	}

	public function testTwoNewRowsWithoutUuidClaimingOneKeyClash(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$this->listener->method('problemsOf')->willReturn([]);

		$refusals = $this->guard()->refusals([['key' => 'k'], ['key' => 'k']], 'r', 's');

		$this->assertSame([1 => 'The decision table cannot be saved: the key "k" is claimed by another row of the same save.'], $refusals);
	}

	public function testAClashIsAddedToTheRowsOwnProblems(): void {
		$this->listener->method('isDecisionTableRef')->willReturn(true);
		$this->listener->method('problemsOf')->willReturnOnConsecutiveCalls([], ['no rules']);

		$refusals = $this->guard()->refusals([['id' => 'a', 'key' => 'k'], ['id' => 'b', 'key' => 'k']], 'r', 's');

		$this->assertSame(
			[1 => 'The decision table cannot be saved: no rules; the key "k" is claimed by another row of the same save.'],
			$refusals
		);
	}

	public function testNoRowsNeedNoListener(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertSame([], $this->guard()->refusals([], 'r', 's'));
	}
}//end class
