<?php

/**
 * The sequential order on a key the public search filters out first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSequentialOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Support\OrderParameter
 */
class MagicSequentialOrderTest extends TestCase {
	public function testAMetadataKeyTheEntityLacksSortsAsNull(): void {
		$first = new ObjectEntity();
		$first->setUuid('a');
		$second = new ObjectEntity();
		$second->setUuid('b');

		$sorted = (new MagicSequentialOrder(query: ['_order' => ['_nothing' => 'desc']]))->sorted(results: [$second, $first]);

		$this->assertSame(['a', 'b'], array_map(static fn (ObjectEntity $entity): ?string => $entity->getUuid(), $sorted));
	}//end testAMetadataKeyTheEntityLacksSortsAsNull()
}//end class
