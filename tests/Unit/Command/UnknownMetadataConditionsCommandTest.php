<?php

/**
 * The unknown-metadata report lists stored rules and changes nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\UnknownMetadataConditionsCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Lists every schema and register rule on unknown metadata, without writing.
 *
 * @covers \OCA\OpenRegister\Command\UnknownMetadataConditionsCommand
 * @uses   \OCA\OpenRegister\Service\Rbac\MetadataConditionFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Rbac\AuthorizationEdit
 * @uses \OCA\OpenRegister\Service\Rbac\RbacChecks
 * @uses \OCA\OpenRegister\Service\Rbac\SchemaSave
 */
class UnknownMetadataConditionsCommandTest extends TestCase {

	/**
	 * Run the command over these schemas and registers.
	 *
	 * @param Schema[]   $schemas   The stored schemas.
	 * @param Register[] $registers The stored registers.
	 *
	 * @return CommandTester The finished run.
	 */
	private function report(array $schemas, array $registers): CommandTester {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schemas): array {
				$this->assertFalse($_rbac, 'The report reads every schema past RBAC');
				$this->assertFalse($_multitenancy, 'The report reads every schema past the organisation filter');
				return $schemas;
			}
		);
		$schemaMapper->expects($this->never())->method('update');
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], bool $_rbac = true, bool $_multitenancy = true) use ($registers): array {
				$this->assertFalse($_rbac, 'The report reads every register past RBAC');
				$this->assertFalse($_multitenancy, 'The report reads every register past the organisation filter');
				return $registers;
			}
		);
		$registerMapper->expects($this->never())->method('update');

		$tester = new CommandTester(new UnknownMetadataConditionsCommand($schemaMapper, $registerMapper));
		$tester->execute([]);

		return $tester;
	}//end report()

	/**
	 * A schema rule, a property rule and a register rule are each listed, and the run exits 1.
	 *
	 * @return void
	 */
	public function testEveryStoredRuleIsListed(): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('publicatie');
		$schema->setAuthorization(['read' => [['group' => 'public', 'match' => ['_published' => ['$lte' => '$now']]]]]);
		$schema->setProperties(['note' => ['authorization' => ['deny' => ['read' => [['group' => 'g', 'match' => ['_depublished' => null]]]]]]]);

		$clean = new Schema();
		$clean->setId(13);
		$clean->setAuthorization(['read' => [['group' => 'public', 'match' => ['_organisation' => '$organisation']]]]);

		$register = new Register();
		$register->setId(3);
		$register->setSlug('woo');
		$register->setAuthorization(['read' => [['group' => 'public', 'match' => ['_published' => true]]]]);

		$tester = $this->report(schemas: [$schema, $clean], registers: [$register]);
		$display = $tester->getDisplay();

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('schema 12: The read rule for group "public" in schema "publicatie" has a condition on "_published"', $display);
		$this->assertStringContainsString('deny read rule for group "g" in property "note" of schema "publicatie"', $display);
		$this->assertStringContainsString('register 3: The read rule for group "public" in register "woo"', $display);
		$this->assertStringNotContainsString('schema 13', $display);
		$this->assertStringContainsString('3 rule condition(s)', $display);
	}//end testEveryStoredRuleIsListed()

	/**
	 * Rules on a malformed or undeclared key are listed, and the run exits 1.
	 *
	 * @return void
	 */
	public function testRulesOnUnknownKeysAreListed(): void {
		$schema = new Schema();
		$schema->setId(21);
		$schema->setSlug('zaak');
		$schema->setProperties(['status' => ['type' => 'string']]);
		$schema->setAuthorization(['read' => [['group' => 'g', 'match' => ['status' => 'a', 'secret' => 'b', 'x) or (1=1' => 'c']]]]);

		$register = new Register();
		$register->setId(4);
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => [['group' => 'h', 'match' => ['anyProperty' => 'a', 'y) or (1=1' => 'b']]]]);

		$tester = $this->report(schemas: [$schema], registers: [$register]);
		$display = $tester->getDisplay();

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('schema 21: The read rule for group "g" in schema "zaak" has a condition on "secret"', $display);
		$this->assertStringContainsString('schema 21: The read rule for group "g" in schema "zaak" has a condition on "x) or (1=1"', $display);
		$this->assertStringContainsString('register 4: The read rule for group "h" in register "zaken" has a condition on "y) or (1=1"', $display);
		$this->assertStringNotContainsString('"status"', $display);
		$this->assertStringNotContainsString('"anyProperty"', $display);
		$this->assertStringContainsString('3 rule condition(s)', $display);
	}//end testRulesOnUnknownKeysAreListed()

	/**
	 * Nothing stored on unknown metadata: the run says so and exits 0.
	 *
	 * @return void
	 */
	public function testACleanInstanceExitsZero(): void {
		$tester = $this->report(schemas: [], registers: []);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('No stored rule', $tester->getDisplay());
		$this->assertStringContainsString('Rules in an object\'s own _authorization are not scanned', $tester->getDisplay());
	}//end testACleanInstanceExitsZero()
}//end class
