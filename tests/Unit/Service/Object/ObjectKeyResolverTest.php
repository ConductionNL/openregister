<?php

/**
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\ObjectKeyResolver;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * One match, none, two, and a missing value, through a real evaluator and a doubled read path.
 *
 * @covers \OCA\OpenRegister\Service\Object\ObjectKeyResolver
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class ObjectKeyResolverTest extends TestCase {

	/**
	 * The object read path.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The register.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The zaken schema, keyed on gemeentecode and zaaknummer.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->register = new Register();
		$this->register->setId(5);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setConfiguration(
			['uniqueConstraints' => [['name' => 'zaaksleutel', 'properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true]]]
		);
	}//end setUp()

	private function resolver(): ObjectKeyResolver {
		return new ObjectKeyResolver(objectService: $this->objects, evaluator: new UniqueConstraintEvaluator());
	}//end resolver()

	public function testOneMatchIsLookedUpByTheIdentityUnderTheCallersAccess(): void {
		$this->objects->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 5, 'schema' => 7, 'gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0042'], 'limit' => 2])
			->willReturn([['@self' => ['id' => 'uuid-1']]]);

		$uuids = $this->resolver()->resolve(register: $this->register, schema: $this->schema, values: ['gemeentecode' => '0363', 'zaaknummer' => 'Z-2026-0042', 'other' => 'x']);

		$this->assertSame(['uuid-1'], $uuids);
	}//end testOneMatchIsLookedUpByTheIdentityUnderTheCallersAccess()

	public function testNoMatchIsEmpty(): void {
		$this->objects->method('findAll')->willReturn([]);

		$this->assertSame([], $this->resolver()->resolve(register: $this->register, schema: $this->schema, values: ['gemeentecode' => '0363', 'zaaknummer' => 'Z-1']));
	}//end testNoMatchIsEmpty()

	public function testTwoMatchesAreBothReturned(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-2');
		$this->objects->method('findAll')->willReturn([['@self' => ['id' => 'uuid-1']], $entity]);

		$this->assertSame(['uuid-1', 'uuid-2'], $this->resolver()->resolve(register: $this->register, schema: $this->schema, values: ['gemeentecode' => '0363', 'zaaknummer' => 'Z-1']));
	}//end testTwoMatchesAreBothReturned()

	public function testAMissingValueIsNamedAndLooksNothingUp(): void {
		$this->objects->expects($this->never())->method('findAll');
		$resolver = $this->resolver();
		$identity = $resolver->identityOf(schema: $this->schema);

		$this->assertNotNull($identity);
		$this->assertSame(['zaaknummer'], $resolver->missing(identity: $identity, values: ['gemeentecode' => '0363', 'zaaknummer' => '']));
		$this->assertSame(['gemeentecode'], $resolver->missing(identity: $identity, values: ['gemeentecode' => ['0363'], 'zaaknummer' => 'Z-1']));
		$this->assertSame([], $resolver->resolve(register: $this->register, schema: $this->schema, values: ['gemeentecode' => '0363']));
	}//end testAMissingValueIsNamedAndLooksNothingUp()

	public function testASchemaWithoutAnIdentityLooksNothingUp(): void {
		$this->objects->expects($this->never())->method('findAll');
		$schema = new Schema();
		$schema->setId(8);

		$this->assertNull($this->resolver()->identityOf(schema: $schema));
		$this->assertSame([], $this->resolver()->resolve(register: $this->register, schema: $schema, values: ['gemeentecode' => '0363']));
	}//end testASchemaWithoutAnIdentityLooksNothingUp()
}//end class
