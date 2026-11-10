<?php

/**
 * ObjectReadNode: the save-time refusals, the fan-out and list shapes, the
 * limit clamp, the failure of the read itself, and how the register and the
 * schema are resolved by id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeSources;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueRenderer
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeSources
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectReadQuery
 * @uses \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 */
class ObjectReadNodeBehaviourTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private Register $register;

	private Schema $schema;

	private ObjectReadNode $node;

	/**
	 * The query configs findAll() was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	protected function setUp(): void {
		parent::setUp();
		$this->register = new Register();
		$this->register->setId(1);
		$this->register->setSlug('cases');
		$this->register->setSchemas([2]);
		$this->schema = new Schema();
		$this->schema->setId(2);
		$this->schema->setSlug('case');

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'alice' ? $alice : null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->node = new ObjectReadNode(
			sources: new ObjectNodeSources(objects: $this->objects, registers: $this->registers, schemas: $this->schemas),
			userManager: $users,
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class))
		);
	}//end setUp()

	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * findAll() answers with these rows and records its query.
	 *
	 * @param array<int, mixed> $rows
	 */
	private function finds(array $rows): void {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($rows): array {
				$this->queries[] = $config;

				return $rows;
			}
		);
	}//end finds()

	private function resolvable(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->method('findBySlugInIds')->willReturn($this->schema);
	}//end resolvable()

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(['register' => 'cases', 'schema' => 'case'], $overrides);
	}//end config()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no register' => [['register' => ' ', 'schema' => 'case'], 'An object-read step needs a register.'],
			'no schema' => [['register' => 'cases', 'schema' => ''], 'An object-read step needs a schema.'],
			'filters not an object' => [['register' => 'cases', 'schema' => 'case', 'filters' => 'status=open'], "An object-read step's filters must be an object."],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testAnUnusableConfigurationIsRefused(array $config, string $message): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		$this->node->validateConfig($config);
	}//end testAnUnusableConfigurationIsRefused()

	public function testAnEmptyBranchReadsNothingAndNeedsNoOwner(): void {
		$this->objects->expects(self::never())->method('findAll');

		self::assertSame([], $this->node->execute(new FlowNodeCall(items: [], config: ['register' => '', 'schema' => ''], context: [])));
	}//end testAnEmptyBranchReadsNothingAndNeedsNoOwner()

	public function testFanOutEmitsOneItemPerObjectAndNoneForNoMatch(): void {
		$this->resolvable();
		$this->objects->method('findAll')->willReturnOnConsecutiveCalls(
			[$this->entity('u-1', ['status' => 'stale']), 'not-an-entity', $this->entity('u-2', ['status' => 'stale'])],
			[]
		);

		$out = $this->node->execute(new FlowNodeCall(
			items: [FlowItems::item(json: ['batch' => 1], binary: ['f' => ['name' => 'x']]), FlowItems::item(json: ['batch' => 2])],
			config: $this->config(['fanOut' => true]),
			context: ['runAs' => 'alice']
		));

		self::assertCount(2, $out, 'one item per object of the first input; the second matched nothing and ends');
		self::assertSame(['batch' => 1, 'id' => 'u-1', 'status' => 'stale', 'uuid' => 'u-1'], $out[0][FlowItems::JSON]);
		self::assertSame('u-2', $out[1][FlowItems::JSON]['uuid']);
		self::assertSame(['f' => ['name' => 'x']], $out[1][FlowItems::BINARY]);
	}//end testFanOutEmitsOneItemPerObjectAndNoneForNoMatch()

	public function testTheListShapeUsesTheDefaultKeyAndClampsTheLimit(): void {
		$this->resolvable();
		$this->finds([$this->entity('u-1', ['a' => 1])]);

		$out = $this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['output' => ' ', 'limit' => 0]), context: ['runAs' => 'alice']));
		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['limit' => 5000]), context: ['runAs' => 'alice']));
		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['limit' => 7]), context: ['runAs' => 'alice']));

		self::assertSame([['id' => 'u-1', 'a' => 1, 'uuid' => 'u-1']], $out[0][FlowItems::JSON]['objects']);
		self::assertSame([100, 1000, 7], array_column($this->queries, 'limit'));
	}//end testTheListShapeUsesTheDefaultKeyAndClampsTheLimit()

	public function testAFailedReadIsReportedWithItsCause(): void {
		$this->resolvable();
		$cause = new \RuntimeException('database gone');
		$this->objects->method('findAll')->willThrowException($cause);

		try {
			$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(), context: ['runAs' => 'alice']));
			self::fail('A failed read must fail the step.');
		} catch (RuntimeException $e) {
			self::assertSame('The object-read step could not read from the register: database gone', $e->getMessage());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedReadIsReportedWithItsCause()

	public function testAnUnresolvableRegisterIsNamed(): void {
		$this->registers->method('find')->with('cases', false, false)->willThrowException(new DoesNotExistException('none'));
		$this->objects->expects(self::never())->method('findAll');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The register "cases" could not be resolved.');

		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(), context: ['runAs' => 'alice']));
	}//end testAnUnresolvableRegisterIsNamed()

	public function testANumericSchemaIsReadById(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->expects(self::never())->method('findBySlugInIds');
		$this->schemas->expects(self::once())->method('find')->with('2', self::anything(), false, false)->willReturn($this->schema);
		$this->objects->expects(self::once())->method('setSchema')->with($this->schema)->willReturnSelf();
		$this->finds([]);

		$out = $this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['schema' => '2']), context: ['runAs' => 'alice']));

		self::assertSame([], $out[0][FlowItems::JSON]['objects']);
	}//end testANumericSchemaIsReadById()

	public function testAnUnknownNumericSchemaIsNamed(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->objects->expects(self::never())->method('findAll');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The schema "9" could not be resolved.');

		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['schema' => '9']), context: ['runAs' => 'alice']));
	}//end testAnUnknownNumericSchemaIsNamed()
	public function testAFilterRenderingToNothingIsRefusedBeforeTheRead(): void {
		$this->resolvable();
		$this->objects->expects(self::never())->method('findAll');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The object-read step's filter could not be rendered: missing resolved to nothing on this item.");

		$this->node->execute(new FlowNodeCall(
			items: [FlowItems::item(json: ['status' => 'open'])],
			config: $this->config(['filters' => ['status' => '{{ status }}', 'owner' => '{{ missing }}']]),
			context: ['runAs' => 'alice']
		));
	}//end testAFilterRenderingToNothingIsRefusedBeforeTheRead()

	public function testRenderedFiltersReachTheQuery(): void {
		$this->resolvable();
		$this->finds([]);

		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: ['status' => 'open'])], config: $this->config(['filters' => ['status' => '{{ status }}']]), context: ['runAs' => 'alice']));

		self::assertSame(['status' => 'open'], $this->queries[0]['filters']);
	}//end testRenderedFiltersReachTheQuery()

	public function testASchemaSlugOutsideTheRegisterIsRefusedNotResolvedGlobally(): void {
		$this->registers->method('find')->willReturn($this->register);
		$this->schemas->expects(self::once())->method('findBySlugInIds')->with('foreign', [2])->willReturn(null);
		$this->schemas->expects(self::once())->method('countBySlug')->with('foreign')->willReturn(2);
		$this->schemas->expects(self::never())->method('find');
		$this->objects->expects(self::never())->method('findAll');

		$this->expectException(SchemaNotInRegisterException::class);

		$this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: [])], config: $this->config(['schema' => 'foreign']), context: ['runAs' => 'alice']));
	}//end testASchemaSlugOutsideTheRegisterIsRefusedNotResolvedGlobally()
}//end class
