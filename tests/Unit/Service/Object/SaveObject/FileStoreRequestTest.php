<?php

/**
 * FileStoreRequest carries what storing one file needs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject\FileStoreRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FileStoreRequest
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class FileStoreRequestTest extends TestCase {

	/**
	 * The constructor arguments come back unchanged.
	 *
	 * @return void
	 */
	public function testCarriesItsArguments(): void {
		$entity  = new ObjectEntity();
		$request = new FileStoreRequest($entity, 'docs', ['type' => 'file'], 2);

		$this->assertSame($entity, $request->objectEntity);
		$this->assertSame('docs', $request->propertyName);
		$this->assertSame(['type' => 'file'], $request->fileConfig);
		$this->assertSame(2, $request->index);
	}//end testCarriesItsArguments()

	/**
	 * A single file has no index.
	 *
	 * @return void
	 */
	public function testASingleFileHasNoIndex(): void {
		$this->assertNull((new FileStoreRequest(new ObjectEntity(), 'doc', [], null))->index);
	}//end testASingleFileHasNoIndex()
}//end class
