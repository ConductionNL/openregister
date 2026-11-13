<?php

/**
 * Where an entity is mentioned: a chunk and a character span, read from a
 * detector match.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Files\Db\EntityMention;
use PHPUnit\Framework\TestCase;

/**
 * EntityMention.
 *
 * @covers \OCA\OpenRegister\Files\Db\EntityMention
 */
class EntityMentionTest extends TestCase {

	public function testAMentionIsReadFromAMatch(): void {
		$mention = EntityMention::atMatch(9, ['chunkId' => 4, 'positionStart' => 120, 'positionEnd' => 134, 'value' => 'ignored']);

		$this->assertSame([9, 4, 120, 134], [$mention->entityId, $mention->chunkId, $mention->positionStart, $mention->positionEnd]);
	}//end testAMentionIsReadFromAMatch()

	public function testAMentionKeepsItsSpan(): void {
		$mention = new EntityMention(entityId: 1, chunkId: 2, positionStart: 0, positionEnd: 5);

		$this->assertSame(0, $mention->positionStart);
		$this->assertSame(5, $mention->positionEnd);
	}//end testAMentionKeepsItsSpan()
}//end class
