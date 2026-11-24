<?php

/**
 * The delete path's transferred spellings are RecordState's, from one home.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\CascadeRetentionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TransferredRecordStatesInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\Archival\RecordStateInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Core reads the archival values and the cascade check through its own contracts.
 */
final class TransferredRecordStatesTest extends TestCase {

	public function testTheSpellingsAreUnchanged(): void {
		$this->assertSame(['transferred', 'overgebracht'], TransferredRecordStatesInterface::TRANSFERRED_ALIASES);
		$this->assertSame(TransferredRecordStatesInterface::TRANSFERRED_ALIASES, RecordState::TRANSFERRED_ALIASES);
	}

	public function testRecordStateKeepsOneHomeOfTheValue(): void {
		$this->assertTrue(is_subclass_of(RecordStateInterface::class, TransferredRecordStatesInterface::class));
		$constant = new ReflectionClassConstant(RecordState::class, 'TRANSFERRED_ALIASES');
		$this->assertSame(TransferredRecordStatesInterface::class, $constant->getDeclaringClass()->getName());
	}

	public function testEveryTransferredSpellingIsATransferredState(): void {
		foreach (TransferredRecordStatesInterface::TRANSFERRED_ALIASES as $alias) {
			$this->assertSame(RecordState::TRANSFERRED, RecordState::CANONICAL[$alias]);
		}
	}

	public function testTheRetentionGuardAnswersTheCascadePort(): void {
		$this->assertTrue(is_subclass_of(ArchivalRetentionGuard::class, CascadeRetentionCheckInterface::class));
	}
}//end class
