<?php

/**
 * OpenRegister - property authorization of one bulk row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRowPropertyAuthorization;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;

/**
 * A refused property is named; an omitted protected value is carried forward on an update only.
 */
class BulkRowPropertyAuthorizationTest extends TestCase {

	/**
	 * The properties the caller may not write are named, and the rules see the row without `@self`.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheUnauthorizedProperties(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->expects($this->once())->method('getUnauthorizedProperties')
			->with($this->callback(static fn (PropertyWriteCheck $write): bool => $write->request->object === [] && $write->incoming === ['title' => 'x'] && $write->request->isCreate === true))
			->willReturn(['secret', 'pin']);

		$check = new BulkRowPropertyAuthorization(propertyRbac: $rbac, schema: new Schema(), existing: null);

		$this->assertSame('You are not authorized to modify: secret, pin', $check->refusal(object: ['@self' => ['id' => 'a'], 'title' => 'x'], isCreate: true));
	}//end testTheRefusalNamesTheUnauthorizedProperties()

	/**
	 * A row the rules allow is not refused.
	 *
	 * @return void
	 */
	public function testAnAuthorizedRowIsNotRefused(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('getUnauthorizedProperties')->willReturn([]);

		$check = new BulkRowPropertyAuthorization(propertyRbac: $rbac, schema: new Schema(), existing: null);

		$this->assertNull($check->refusal(object: ['title' => 'x'], isCreate: true));
	}//end testAnAuthorizedRowIsNotRefused()

	/**
	 * A create carries nothing forward and does not ask the rules.
	 *
	 * @return void
	 */
	public function testACreateIsLeftAsItIs(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->expects($this->never())->method('collectOmittedProtectedProperties');

		$check = new BulkRowPropertyAuthorization(propertyRbac: $rbac, schema: new Schema(), existing: null);

		$this->assertSame(['title' => 'x'], $check->carriedForward(object: ['title' => 'x']));
	}//end testACreateIsLeftAsItIs()

	/**
	 * On an update the omitted protected values come back from the stored row.
	 *
	 * @return void
	 */
	public function testAnUpdateCarriesTheOmittedProtectedValuesForward(): void {
		$stored = new ObjectEntity();
		$stored->setObject(['title' => 'old', 'secret' => 's3cret']);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('collectOmittedProtectedProperties')->willReturn(['secret']);
		$rbac->expects($this->once())->method('restoreWriteOnlyValues')
			->with(['title' => 'new'], $stored->getObject(), ['secret'])
			->willReturn(['title' => 'new', 'secret' => 's3cret']);

		$check = new BulkRowPropertyAuthorization(propertyRbac: $rbac, schema: new Schema(), existing: $stored);

		$this->assertSame(['title' => 'new', 'secret' => 's3cret'], $check->carriedForward(object: ['title' => 'new']));
	}//end testAnUpdateCarriesTheOmittedProtectedValuesForward()

	/**
	 * An update that omits nothing protected is left as it is.
	 *
	 * @return void
	 */
	public function testAnUpdateOmittingNothingProtectedIsLeftAsItIs(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('collectOmittedProtectedProperties')->willReturn([]);
		$rbac->expects($this->never())->method('restoreWriteOnlyValues');

		$check = new BulkRowPropertyAuthorization(propertyRbac: $rbac, schema: new Schema(), existing: new ObjectEntity());

		$this->assertSame(['title' => 'new'], $check->carriedForward(object: ['title' => 'new']));
	}//end testAnUpdateOmittingNothingProtectedIsLeftAsItIs()
}//end class
