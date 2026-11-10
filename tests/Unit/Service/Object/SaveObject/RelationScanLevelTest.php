<?php

/**
 * OpenRegister - the level a relation scan is on, shared by the single-object and bulk scans.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel;
use OCA\OpenRegister\Service\Object\SaveObject\RelationScanRun;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationRun;
use PHPUnit\Framework\TestCase;

/**
 * The key and dot path of a scan, with and without a prefix, for both scans.
 */
class RelationScanLevelTest extends TestCase {

	/**
	 * At the top level the path is the key.
	 *
	 * @return void
	 */
	public function testAtTheTopThePathIsTheKey(): void {
		$scan = new RelationScanRun();
		$scan->moveTo(key: 'owner');

		$this->assertSame('owner', $scan->key);
		$this->assertSame('owner', $scan->path);
		$this->assertNull($scan->schema);
	}//end testAtTheTopThePathIsTheKey()

	/**
	 * Below the top the path is the prefix and the key.
	 *
	 * @return void
	 */
	public function testBelowTheTopThePathCarriesThePrefix(): void {
		$schema = new Schema();
		$scan   = new BulkRelationRun(prefix: 'items.0', schema: $schema);
		$scan->moveTo(key: 'ref');

		$this->assertInstanceOf(RelationScanLevel::class, $scan);
		$this->assertSame('ref', $scan->key);
		$this->assertSame('items.0.ref', $scan->path);
		$this->assertSame($schema, $scan->schema);
		$this->assertSame([], $scan->relations);
		$this->assertSame('', $scan->itemPath);
		$this->assertFalse($scan->itemsAreObjects);
	}//end testBelowTheTopThePathCarriesThePrefix()
}//end class
