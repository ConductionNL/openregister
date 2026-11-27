<?php

/**
 * A schema's opt-in into Nextcloud collections survives a save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-collaboration-resources/specs/platform-collaboration-resources/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Collaboration\ObjectResourceProvider;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * `collaborationResources` is the key ObjectResourceProvider reads. Off the
 * configuration allowlist it was dropped on every save, so no schema could
 * opt its objects into collections and every add answered 400.
 */
class SchemaCollaborationResourcesConfigurationTest extends TestCase {

	/**
	 * The opt-in round-trips as a boolean, under the key the provider reads.
	 *
	 * @return void
	 */
	public function testTheOptInIsKept(): void {
		$schema = new Schema();
		$schema->setConfiguration([ObjectResourceProvider::CONFIGURATION_KEY => true]);

		$this->assertSame(expected: ['collaborationResources' => true], actual: $schema->getConfiguration());
	}//end testTheOptInIsKept()

	/**
	 * Opting out is kept as well, so a schema can say so explicitly.
	 *
	 * @return void
	 */
	public function testAnExplicitOptOutIsKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(['collaborationResources' => false, 'allowFiles' => true]);

		$this->assertSame(expected: ['collaborationResources' => false, 'allowFiles' => true], actual: $schema->getConfiguration());
	}//end testAnExplicitOptOutIsKept()

	/**
	 * A non-boolean value is dropped like every other boolean key, so it never reads as an opt-in.
	 *
	 * @return void
	 */
	public function testANonBooleanOptInIsDropped(): void {
		$schema = new Schema();
		$schema->setConfiguration(['collaborationResources' => 'yes', 'allowFiles' => true]);

		$this->assertSame(expected: ['allowFiles' => true], actual: $schema->getConfiguration());
	}//end testANonBooleanOptInIsDropped()
}//end class
