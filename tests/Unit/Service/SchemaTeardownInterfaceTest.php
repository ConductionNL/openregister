<?php
/**
 * SchemaTeardownInterfaceTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\SchemaDeletionService;
use OCA\OpenRegister\Service\SchemaTeardownInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The whole-table deletes ObjectService asks are on the Core port, answered by SchemaDeletionService unchanged.
 */
final class SchemaTeardownInterfaceTest extends TestCase {

	public function testTheBulkTriggerIsTheOneTheDeletionServiceRecords(): void {
		$this->assertSame(SchemaDeletionService::TRIGGER_BULK_DELETE, SchemaTeardownInterface::TRIGGER_BULK_DELETE);
		$this->assertSame('bulk_schema_delete', SchemaTeardownInterface::TRIGGER_BULK_DELETE);
	}

	public function testTheDeletionServiceAnswersBothWholeTableDeletes(): void {
		$this->assertTrue(is_subclass_of(SchemaDeletionService::class, SchemaTeardownInterface::class));
		foreach (['deleteObjectsBySchema', 'deleteObjectsByRegister'] as $method) {
			$port    = new ReflectionMethod(SchemaTeardownInterface::class, $method);
			$service = new ReflectionMethod(SchemaDeletionService::class, $method);
			$this->assertSame(
				array_map(static fn ($p): string => (string) $p->getType(), $port->getParameters()),
				array_map(static fn ($p): string => (string) $p->getType(), $service->getParameters())
			);
			$this->assertTrue($port->getParameters()[1]->isOptional());
		}
	}
}//end class
