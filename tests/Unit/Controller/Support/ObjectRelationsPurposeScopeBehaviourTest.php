<?php

/**
 * Behaviour test for ObjectRelationsPurposeScope: purpose binding on relation graphs.
 *
 * Asserts when the root object is refused (unknown schema or register, purpose
 * refusal) and which related nodes are cut from a graph: refused nodes and
 * everything only reachable through them, while external or unresolved nodes
 * are never judged and each register/schema pair is decided once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ObjectRelationsPurposeScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Object\ObjectReadAccessInterface;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ObjectRelationsPurposeScopeBehaviourTest extends TestCase {

	/** @var ObjectReadAccessInterface&MockObject The read-access helper. */
	private ObjectReadAccessInterface $access;

	/** @var PurposeGuardInterface&MockObject The purpose guard. */
	private PurposeGuardInterface $guard;

	/** @var RegisterMapperInterface&MockObject The register lookup. */
	private RegisterMapperInterface $registers;

	protected function setUp(): void {
		$this->access = $this->createMock(ObjectReadAccessInterface::class);
		$this->guard = $this->createMock(PurposeGuardInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
	}

	private function scope(): ObjectRelationsPurposeScope {
		return new ObjectRelationsPurposeScope($this->access, $this->guard, $this->registers);
	}

	private static function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}

	private function object(?string $register): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister($register);
		return $object;
	}

	private static function refusal(): PurposeRefusedException {
		return new PurposeRefusedException('purpose-mismatch', 'Not for this purpose', 'care');
	}

	public function testTheRootIsUnreadableWithoutASchema(): void {
		$unreadable = new JSONResponse(['error' => 'not readable'], 404);
		$this->access->method('schemaOf')->willReturn(null);
		$this->access->method('notReadable')->willReturn($unreadable);
		$this->registers->method('find')->willReturn(self::register(5));
		$this->guard->expects($this->never())->method('enforce');

		$this->assertSame($unreadable, $this->scope()->refusalFor($this->object('5')));
	}

	public function testTheRootIsUnreadableWhenItsRegisterCannotBeLoaded(): void {
		$unreadable = new JSONResponse(['error' => 'not readable'], 404);
		$this->access->method('schemaOf')->willReturn(self::schema(2));
		$this->access->method('notReadable')->willReturn($unreadable);
		$this->registers->expects($this->once())->method('find')->with('5', false, false)->willThrowException(new RuntimeException('gone'));

		$this->assertSame($unreadable, $this->scope()->refusalFor($this->object('5')));
	}

	public function testARootWithoutARegisterIsUnreadableWithoutALookup(): void {
		$this->access->method('schemaOf')->willReturn(self::schema(2));
		$this->access->method('notReadable')->willReturn(new JSONResponse([], 404));
		$this->registers->expects($this->never())->method('find');
		$this->assertSame(404, $this->scope()->refusalFor($this->object(null))?->getStatus());
	}

	public function testAPurposeRefusalOnTheRootAnswersForbidden(): void {
		$this->access->method('schemaOf')->willReturn(self::schema(2));
		$this->registers->method('find')->willReturn(self::register(5));
		$this->guard->method('enforce')->willThrowException(self::refusal());

		$response = $this->scope()->refusalFor($this->object('5'));
		$this->assertNotNull($response);
		$this->assertSame(403, $response->getStatus());
		$this->assertSame('PURPOSE_REFUSED', $response->getData()['error']);
	}

	public function testAnAdmittedRootIsNotRefused(): void {
		$schema = self::schema(2);
		$register = self::register(5);
		$this->access->method('schemaOf')->willReturn($schema);
		$this->registers->method('find')->willReturn($register);
		$this->guard->expects($this->once())->method('enforce')->with($register, $schema)->willReturn('care');

		$this->assertNull($this->scope()->refusalFor($this->object('5')));
	}

	private static function graph(): array {
		return [
			'root' => 'r',
			'nodes' => [
				['uuid' => 'r', 'register' => 1, 'schema' => 1, 'resolved' => true],
				['uuid' => 'a', 'register' => 1, 'schema' => 1, 'resolved' => true],
				['uuid' => 'b', 'register' => 2, 'schema' => 9, 'resolved' => true],
				['uuid' => 'c', 'register' => 1, 'schema' => 1, 'resolved' => true],
				['uuid' => 'x', 'register' => 2, 'schema' => 9, 'resolved' => true, 'external' => true],
				['uuid' => 'u', 'register' => 2, 'schema' => 9],
			],
			'edges' => [
				['from' => 'r', 'to' => 'a'],
				['from' => 'r', 'to' => 'b'],
				['from' => 'b', 'to' => 'c'],
				['from' => 'r', 'to' => 'x'],
				['from' => 'r', 'to' => 'u'],
			],
		];
	}

	public function testAGraphWithoutRefusalsIsReturnedUnchanged(): void {
		$this->access->method('schemaById')->willReturnCallback(static fn (int|string|null $id): Schema => self::schema((int) $id));
		$this->registers->method('find')->willReturnCallback(static fn (int|string $id): Register => self::register((int) $id));
		$this->guard->expects($this->exactly(2))->method('enforce')->willReturn(null);

		$this->assertSame(self::graph(), $this->scope()->narrow(self::graph()));
	}

	public function testARefusedNodeAndWhatHangsOnlyFromItAreCut(): void {
		$this->access->method('schemaById')->willReturnCallback(static fn (int|string|null $id): Schema => self::schema((int) $id));
		$this->registers->method('find')->willReturnCallback(static fn (int|string $id): Register => self::register((int) $id));
		$this->guard->method('enforce')->willReturnCallback(
			static function (?Register $register, ?Schema $schema): ?string {
				if ($schema?->getId() === 9) {
					throw self::refusal();
				}

				return null;
			}
		);

		$narrowed = $this->scope()->narrow(self::graph());
		$this->assertSame(['r', 'a', 'x', 'u'], array_column($narrowed['nodes'], 'uuid'));
		$this->assertSame([['from' => 'r', 'to' => 'a'], ['from' => 'r', 'to' => 'x'], ['from' => 'r', 'to' => 'u']], $narrowed['edges']);
	}

	public function testANodeWithAnUnknownSchemaOrRegisterIsRefused(): void {
		$this->access->method('schemaById')->willReturnCallback(
			static function (int|string|null $id): ?Schema {
				if ($id === 9) {
					return null;
				}

				return self::schema((int) $id);
			}
		);
		$this->registers->method('find')->willReturnCallback(static fn (int|string $id): Register => self::register((int) $id));

		$graph = ['root' => 'r', 'nodes' => [
			['uuid' => 'r', 'register' => 1, 'schema' => 1, 'resolved' => true],
			['uuid' => 'b', 'register' => 2, 'schema' => 9, 'resolved' => true],
			['uuid' => 'n', 'register' => '', 'schema' => null, 'resolved' => true],
		], 'edges' => [['from' => 'r', 'to' => 'b'], ['from' => 'r', 'to' => 'n']]];

		$this->assertSame(['r'], array_column($this->scope()->narrow($graph)['nodes'], 'uuid'));
	}

	public function testAGraphWithoutNodesIsReturnedAsIs(): void {
		$this->guard->expects($this->never())->method('enforce');
		$this->assertSame(['root' => 'r'], $this->scope()->narrow(['root' => 'r']));
	}
}
