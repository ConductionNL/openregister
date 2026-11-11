<?php

/**
 * An unknown filter is an empty report, not a 500.
 *
 * Both mappers THROW when nothing matches — find() never answers null — so the
 * tolerance these reports rely on is entirely in the catch. An auditor who
 * mistypes a register slug should get an empty report back, not an error page,
 * and that behaviour was previously a private helper nobody could test.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\PermissionReportScope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PermissionReportScopeTest extends TestCase {

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;

	private PermissionReportScope $scope;

	protected function setUp(): void {
		parent::setUp();

		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->scope = new PermissionReportScope(
			registerMapper: $this->registers,
			schemaMapper: $this->schemas
		);
	}

	public function testNoFilterCoversEverything(): void {
		$all = [new Register(), new Register()];
		$this->registers->expects($this->once())->method('findAll')->willReturn($all);
		$this->registers->expects($this->never())->method('find');

		$this->assertSame($all, $this->scope->registers(null));
	}

	public function testAnEmptyFilterIsTreatedAsNoFilter(): void {
		// A query string that arrives as '' must not be read as "the register
		// whose slug is the empty string".
		$this->registers->expects($this->once())->method('findAll')->willReturn([]);
		$this->registers->expects($this->never())->method('find');

		$this->scope->registers('');
	}

	public function testAFilterNarrowsToTheOneEntity(): void {
		$one = new Register();
		$this->registers->expects($this->once())->method('find')->with('zaken')->willReturn($one);

		$this->assertSame([$one], $this->scope->registers('zaken'));
	}

	public function testAnUnknownRegisterIsAnEmptyReportRatherThanAnError(): void {
		$this->registers->method('find')->willThrowException(new RuntimeException('no such register'));

		$this->assertSame([], $this->scope->registers('typo'));
	}

	public function testTheSchemaSideBehavesTheSameWay(): void {
		$one = new Schema();
		$this->schemas->expects($this->once())->method('find')->with('besluit')->willReturn($one);

		$this->assertSame([$one], $this->scope->schemas('besluit'));
	}

	public function testAnUnknownSchemaIsAlsoEmptyRatherThanAnError(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('no such schema'));

		$this->assertSame([], $this->scope->schemas('typo'));
	}

	public function testNoSchemaFilterCoversEverySchema(): void {
		$all = [new Schema()];
		$this->schemas->expects($this->once())->method('findAll')->willReturn($all);

		$this->assertSame($all, $this->scope->schemas(null));
	}
}//end class
