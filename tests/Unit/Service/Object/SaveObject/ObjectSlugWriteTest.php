<?php

/**
 * ObjectSlugWrite unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWrite;
use PHPUnit\Framework\TestCase;

/**
 * A write names its object and asks for a slug in its own scope and path.
 */
final class ObjectSlugWriteTest extends TestCase {

	public function testTheWantCarriesTheScopeTheUuidAndThePath(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$scope = new ObjectSlugScope(null, new Schema());

		$write = new ObjectSlugWrite($entity, $scope, 'sent', 'seed');
		$want = $write->want('wanted');

		$this->assertSame('u-1', $write->objectUuid());
		$this->assertSame('wanted', $want->slug);
		$this->assertSame($scope, $want->scope);
		$this->assertSame('u-1', $want->objectUuid);
		$this->assertSame('seed', $want->path);
		$this->assertSame('sent', $write->clientSlug);
	}

	public function testAnEntityWithoutUuidIsNamedEmpty(): void {
		$write = new ObjectSlugWrite(new ObjectEntity(), new ObjectSlugScope(null, new Schema()), null, 'restore');

		$this->assertSame('', $write->objectUuid());
	}
}//end class
