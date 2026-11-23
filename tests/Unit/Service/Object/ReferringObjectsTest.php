<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The referring objects are read through the relations index of the named schema.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ReferringObjects;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferringObjects::class)]
#[UsesClass(ObjectEntity::class)]
class ReferringObjectsTest extends TestCase {

	public function testReadsTheSchemaTableForTheUuids(): void {
		$row = new ObjectEntity();
		$relations = $this->createMock(MagicRelationReaderInterface::class);
		$relations->expects($this->once())->method('findByRelationBatchInSchema')
			->with(['u-1'], 7, 3, 'application')
			->willReturn([$row]);

		$this->assertSame([$row], (new ReferringObjects($relations))->referringTo(['u-1'], 7, 3, 'application'));
	}
}
