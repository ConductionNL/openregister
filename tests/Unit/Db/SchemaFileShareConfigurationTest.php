<?php

/**
 * The schema-level file auto-share key survives a save; its retired name is kept only for the warning.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/remove-solr-and-publishing/specs/deprecate-published-metadata/spec.md#requirement-file-auto-share-config-key-renamed-from-autopublish-to-autoshare
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * FilePropertyHandler reads `autoShare` from the schema configuration and warns on `autoPublish`.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 */
class SchemaFileShareConfigurationTest extends TestCase {

	/**
	 * `autoShare` round-trips as a boolean.
	 */
	public function testAutoShareIsKept(): void {
		$schema = new Schema();
		$schema->setConfiguration(['autoShare' => true]);

		$this->assertSame(['autoShare' => true], $schema->getConfiguration());
	}//end testAutoShareIsKept()

	/**
	 * A non-boolean `autoShare` is dropped, the rest of the configuration is kept.
	 */
	public function testANonBooleanAutoShareIsDropped(): void {
		$schema = new Schema();
		$schema->setConfiguration(['autoShare' => 'yes', 'allowFiles' => true]);

		$this->assertSame(['allowFiles' => true], $schema->getConfiguration());
	}//end testANonBooleanAutoShareIsDropped()

	/**
	 * `autoPublish` is kept as given, without boolean validation, so the deprecation warning can still find it.
	 */
	public function testTheRetiredAutoPublishKeyIsKeptUnvalidated(): void {
		$schema = new Schema();
		$schema->setConfiguration(['autoPublish' => 'yes']);

		$this->assertSame(['autoPublish' => 'yes'], $schema->getConfiguration());
	}//end testTheRetiredAutoPublishKeyIsKeptUnvalidated()
}//end class
