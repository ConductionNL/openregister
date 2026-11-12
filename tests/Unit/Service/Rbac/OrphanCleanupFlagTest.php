<?php

/**
 * The orphan-cleanup flag reports a save that changes the schema's stored setting.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\OrphanCleanupFlag;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\OrphanCleanupFlag
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class OrphanCleanupFlagTest extends TestCase {

	private function schema(?array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		return $schema;
	}

	public function testTurningItOnFromTheDefaultIsAChange(): void {
		$flag = new OrphanCleanupFlag();

		$this->assertTrue($flag->changedBy($this->schema(null), ['configuration' => ['orphanCleanup' => true]]));
	}

	public function testRepeatingTheStoredValueIsNoChange(): void {
		$flag = new OrphanCleanupFlag();

		$this->assertFalse($flag->changedBy($this->schema(null), ['configuration' => ['orphanCleanup' => false]]));
		$this->assertFalse($flag->changedBy($this->schema(['orphanCleanup' => true]), ['configuration' => ['orphanCleanup' => true]]));
	}

	public function testTurningItOffIsAChange(): void {
		$flag = new OrphanCleanupFlag();

		$this->assertTrue($flag->changedBy($this->schema(['orphanCleanup' => true]), ['configuration' => ['orphanCleanup' => false]]));
	}

	public function testAJsonConfigurationIsDecoded(): void {
		$flag = new OrphanCleanupFlag();

		$this->assertTrue($flag->changedBy($this->schema(null), ['configuration' => '{"orphanCleanup":true}']));
		$this->assertFalse($flag->changedBy($this->schema(null), ['configuration' => '{"orphanCleanup":false}']));
	}

	public function testADocumentNotNamingTheFlagIsNoChange(): void {
		$flag   = new OrphanCleanupFlag();
		$schema = $this->schema(['orphanCleanup' => true]);

		$this->assertFalse($flag->changedBy($schema, []));
		$this->assertFalse($flag->changedBy($schema, ['configuration' => ['other' => 1]]));
		$this->assertFalse($flag->changedBy($schema, ['configuration' => 'not json']));
		$this->assertFalse($flag->changedBy($schema, ['configuration' => 5]));
	}
}
