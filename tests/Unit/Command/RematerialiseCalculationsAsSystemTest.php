<?php

/**
 * The rematerialise command writes as the system, not as "Anonymous".
 *
 * 🔴 LIVE DEFECT (live pass, 5 Oct, O7): `occ openregister:rematerialise-calculations
 * learniq session` reported "Touched 1385, unchanged 0, failed 1385", every line
 * "User 'Anonymous' does not have permission to 'update' objects in schema
 * 'Session'". occ has no user session, and the command saved with the defaults
 * (_rbac on, _multitenancy on), so any schema with an authorization block refused
 * every row. The reads already ran without RBAC, which is why "touched" counted
 * every row; a dry run never saves, so it could not show it.
 *
 * The save double below asks the REAL PermissionHandler, with the flags and the
 * system context the command actually used, the question saveObject() asks
 * before it writes (ObjectService::checkSavePermissions(), action 'update').
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-the-rematerialise-command-writes-as-the-system
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RematerialiseCalculationsCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RematerialiseCalculationsAsSystemTest extends TestCase {

	/**
	 * The real permission handler over doubles: no session user, as under occ.
	 *
	 * @return PermissionHandler
	 */
	private function permissions(): PermissionHandler {
		$args = [];
		foreach ((new ReflectionClass(PermissionHandler::class))->getConstructor()->getParameters() as $param) {
			if ($param->isOptional() === true) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			$type = $param->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$args[] = $this->createMock($type->getName());
		}

		return new PermissionHandler(...$args);
	}//end permissions()

	/**
	 * Run the command over rows of a schema with an authorization block.
	 *
	 * @param int                 $rows  How many rows the table holds.
	 * @param array<int, string>  $lines Collects the output.
	 *
	 * @return int The exit code.
	 */
	private function runCommand(int $rows, ?string &$lines = null): int {
		$register = new Register();
		$register->setId(5);
		$register->setSlug('learniq');

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('session');
		$schema->setTitle('Session');
		$schema->setAuthorization(['read' => ['instructors'], 'update' => ['instructors']]);
		$schema->setConfiguration(
			['x-openregister-calculations' => ['isPast' => ['expression' => 'true', 'materialise' => true]]]
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$entities = [];
		for ($i = 0; $i < $rows; $i++) {
			$entity = new ObjectEntity();
			$entity->setUuid('session-' . $i);
			$entity->setRegister('5');
			$entity->setSchema('7');
			$entity->setObject(['title' => 'Session ' . $i]);
			$entities[] = $entity;
		}

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturn($entities);

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturn(false);
		$evaluator->method('evaluate')->willReturn(true);

		$permissions = $this->permissions();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => \OCA\OpenRegister\Service\SystemOperationContext::run($operation)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schemaRef = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use ($permissions, $schema): ObjectEntity {
				// ObjectService::checkSavePermissions() for an existing row.
				$permissions->checkPermission(schema: $schema, action: 'update', _rbac: $_rbac);
				// The save path materialises (CalculationOnSaveListener), so the saved row carries the value.
				$saved = new ObjectEntity();
				$saved->setObject(array_merge($object, ['isPast' => true]));
				return $saved;
			}
		);

		$command = new RematerialiseCalculationsCommand(
			$registers,
			$schemas,
			$magic,
			$objects,
			$evaluator,
			$this->createMock(ReferenceResolver::class),
			$this->createMock(AggregateReferenceResolver::class)
		);

		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput(['register' => 'learniq', 'schema' => 'session']), $output);
		$lines = $output->fetch();

		return $code;
	}//end runCommand()

	/**
	 * 🔴 The live case: every row of a schema with an authorization block is saved.
	 *
	 * @return void
	 */
	public function testEveryRowOfAnAuthorizedSchemaIsSaved(): void {
		$code = $this->runCommand(3, $out);

		$this->assertStringNotContainsString('does not have permission', (string) $out);
		$this->assertStringContainsString('Touched 3, unchanged 0, failed 0', (string) $out);
		$this->assertSame(0, $code);
	}//end testEveryRowOfAnAuthorizedSchemaIsSaved()

	/**
	 * Control: the same question asked as "Anonymous" with RBAC on is refused,
	 * so the test above passes only because the command writes as the system.
	 *
	 * @return void
	 */
	public function testTheRealHandlerRefusesAnonymousWithRbacOn(): void {
		$schema = new Schema();
		$schema->setTitle('Session');
		$schema->setAuthorization(['read' => ['instructors'], 'update' => ['instructors']]);

		$this->expectExceptionMessage("User 'Anonymous' does not have permission to 'update' objects in schema 'Session'");
		$this->permissions()->checkPermission(schema: $schema, action: 'update', _rbac: true);
	}//end testTheRealHandlerRefusesAnonymousWithRbacOn()
}//end class
