<?php

/**
 * Unit test for ExampleSetRegisters::exists(): a register is on the instance
 * when the register mapper finds its slug without access rules or tenancy,
 * and not when the mapper (or the container handing it out) throws.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * ExampleSetRegisters::exists(): whether a register slug resolves through the
 * register mapper, and that every failure to look it up reads as absent.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class ExampleSetPresentRegistersTest extends TestCase {

	/**
	 * A register the mapper finds by slug, asked unscoped, is on the instance.
	 *
	 * @return void
	 */
	public function testARegisterTheMapperFindsIsThere(): void {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with('zaken', false, false)
			->willReturn($this->createMock(Register::class));

		$this->assertTrue($this->subject(mapper: $mapper)->exists(slug: 'zaken'));
	}//end testARegisterTheMapperFindsIsThere()

	/**
	 * A slug the mapper does not find is not on the instance.
	 *
	 * @return void
	 */
	public function testARegisterTheMapperDoesNotFindIsNotThere(): void {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with('ghost', false, false)
			->willThrowException(new RuntimeException('Register not found'));

		$this->assertFalse($this->subject(mapper: $mapper)->exists(slug: 'ghost'));
	}//end testARegisterTheMapperDoesNotFindIsNotThere()

	/**
	 * A container that cannot hand out the mapper reads as no register, never as an error.
	 *
	 * @return void
	 */
	public function testAMissingMapperReadsAsNoRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(RegisterMapperInterface::class)
			->willThrowException(new RuntimeException('no mapper'));

		$this->assertFalse((new ExampleSetRegisters(container: $container))->exists(slug: 'zaken'));
	}//end testAMissingMapperReadsAsNoRegister()

	/**
	 * The class under test over a container that hands out the mapper.
	 *
	 * @param RegisterMapperInterface $mapper The register mapper.
	 *
	 * @return ExampleSetRegisters
	 */
	private function subject(RegisterMapperInterface $mapper): ExampleSetRegisters {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->with(RegisterMapperInterface::class)->willReturn($mapper);

		return new ExampleSetRegisters(container: $container);
	}//end subject()
}//end class
