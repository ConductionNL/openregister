<?php

/**
 * The object an audit trail row is about, stamped onto the row: register,
 * schema, object id and uuid, nulls included.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * AuditedObject.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper::stampTarget
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class AuditedObjectTest extends TestCase {

	public function testTheObjectIsStampedOntoTheRow(): void {
		$row = new AuditTrail();

		AuditTrailMapper::stampTarget(auditTrail: $row, target: new AuditedObject(register: 2, schema: 5, object: 81, objectUuid: 'obj-81'));

		$this->assertSame([2, 5, 81, 'obj-81'], [$row->getRegister(), $row->getSchema(), $row->getObject(), $row->getObjectUuid()]);
	}//end testTheObjectIsStampedOntoTheRow()

	public function testAnUnknownObjectClearsTheRowsFields(): void {
		$row = new AuditTrail();
		$row->setRegister(1);
		$row->setObjectUuid('stale');

		AuditTrailMapper::stampTarget(auditTrail: $row, target: new AuditedObject());

		$this->assertSame([null, null, null, null], [$row->getRegister(), $row->getSchema(), $row->getObject(), $row->getObjectUuid()]);
	}//end testAnUnknownObjectClearsTheRowsFields()

	public function testTheObjectIsPureData(): void {
		$methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(AuditedObject::class))->getMethods());

		$this->assertSame(['__construct'], $methods);
	}//end testTheObjectIsPureData()

	public function testTheSlugBackfillCapIsOwnedByThePort(): void {
		$this->assertSame(1000, AuditTrailMapperInterface::SLUG_BACKFILL_MAX_CHANGES);
		$this->assertSame(AuditTrailMapperInterface::class, (new ReflectionClassConstant(AuditTrailMapper::class, 'SLUG_BACKFILL_MAX_CHANGES'))->getDeclaringClass()->getName());
	}//end testTheSlugBackfillCapIsOwnedByThePort()
}//end class
