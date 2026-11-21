<?php

/**
 * Where a seed object of an import preview lands: the local register and
 * schema it resolves to by slug, and whether both exist or arrive with the
 * same import.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ObjectPreviewTargets;
use PHPUnit\Framework\TestCase;

/**
 * ObjectPreviewTargets.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ObjectPreviewTargets
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ObjectPreviewTargetsTest extends TestCase {

	private Register $register;

	private Schema $schema;

	private function targets(array $planned = []): ObjectPreviewTargets {
		$this->register = new Register();
		$this->schema   = new Schema();

		return new ObjectPreviewTargets(['zaken' => $this->register], ['case' => $this->schema], $planned);
	}//end targets()

	public function testALocalTargetIsFoundCaseInsensitively(): void {
		$targets = $this->targets();

		$this->assertSame(
			['register' => $this->register, 'schema' => $this->schema],
			$targets->local(['register' => 'Zaken', 'schema' => 'CASE'])
		);
	}//end testALocalTargetIsFoundCaseInsensitively()

	public function testAMissingRegisterOrSchemaHasNoLocalTarget(): void {
		$targets = $this->targets();

		$this->assertNull($targets->local(['register' => 'other', 'schema' => 'case']));
		$this->assertNull($targets->local(['register' => 'zaken', 'schema' => 'other']));
	}//end testAMissingRegisterOrSchemaHasNoLocalTarget()

	public function testALocalTargetArrives(): void {
		$this->assertTrue($this->targets()->arrives(['register' => 'ZAKEN', 'schema' => 'Case']));
	}//end testALocalTargetArrives()

	public function testAPlannedRegisterAndSchemaArrive(): void {
		$targets = $this->targets(['registers' => ['new-reg' => true], 'schemas' => ['new-schema' => true]]);

		$this->assertTrue($targets->arrives(['register' => 'New-Reg', 'schema' => 'new-schema']));
		$this->assertTrue($targets->arrives(['register' => 'zaken', 'schema' => 'new-schema']));
		$this->assertTrue($targets->arrives(['register' => 'new-reg', 'schema' => 'case']));
		$this->assertNull($targets->local(['register' => 'new-reg', 'schema' => 'new-schema']));
	}//end testAPlannedRegisterAndSchemaArrive()

	public function testNeitherHalfMayBeMissing(): void {
		$targets = $this->targets(['registers' => ['new-reg' => true]]);

		$this->assertFalse($targets->arrives(['register' => 'new-reg', 'schema' => 'unknown']));
		$this->assertFalse($targets->arrives(['register' => 'unknown', 'schema' => 'case']));
	}//end testNeitherHalfMayBeMissing()
}//end class
