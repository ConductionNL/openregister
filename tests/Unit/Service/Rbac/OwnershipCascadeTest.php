<?php

/**
 * An ownership cascade places each child one generation below its parent.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\OwnedRecord;
use OCA\OpenRegister\Service\Rbac\OwnershipCascade;
use OCA\OpenRegister\Service\Rbac\OwnershipHandover;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\OwnershipCascade
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\OwnedRecord
 * @uses   \OCA\OpenRegister\Service\Rbac\OwnershipHandover
 */
class OwnershipCascadeTest extends TestCase {

	private function record(int $depth): OwnedRecord {
		$object = new ObjectEntity();
		$object->setOwner('alice');

		return new OwnedRecord(new Register(), new Schema(), $object, $depth);
	}

	public function testTheChildOfTheTransferredRecordIsOneDeep(): void {
		$handover = new OwnershipHandover(newOwner: 'bob', cascade: true);
		$cascade  = new OwnershipCascade($this->record(0), $handover);

		$this->assertSame(1, $cascade->childDepth());
		$this->assertSame($handover, $cascade->handover);
		$this->assertSame('alice', $cascade->parent->previousOwner);
	}

	public function testDepthKeepsCountingDownTheTree(): void {
		$cascade = new OwnershipCascade($this->record(4), new OwnershipHandover(newOwner: 'bob', cascade: true));

		$this->assertSame(5, $cascade->childDepth());
	}
}
