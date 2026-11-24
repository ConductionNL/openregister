<?php

/**
 * AnonymisationCopyPortsBindingTest: the Kernel ports anonymisation reaches Core and Files through resolve to their stores.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
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

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectPropertyHistoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The derived-copy targets of an anonymisation type on Kernel ports; Core and Files bind them to the same stores as before.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */
final class AnonymisationCopyPortsBindingTest extends TestCase {

	public function testThePropertyHistoryPortResolvesToTheStateHistoryMapper(): void {
		$this->assertSame(StateHistoryMapper::class, DbRegistrar::BINDINGS[ObjectPropertyHistoryInterface::class] ?? null);
		$this->assertSame(StateHistoryMapper::class, DbRegistrar::BINDINGS[StateHistoryMapperInterface::class] ?? null);
		$this->assertTrue(is_subclass_of(StateHistoryMapperInterface::class, ObjectPropertyHistoryInterface::class));
	}

	public function testTheChunkPortResolvesToTheChunkMapperWhichCanDeleteBySource(): void {
		$this->assertSame(ChunkMapper::class, FilesRegistrar::BINDINGS[ChunkMapperInterface::class] ?? null);
		$this->assertTrue(method_exists(ChunkMapperInterface::class, 'deleteBySource'));
		$this->assertTrue(is_subclass_of(ChunkMapper::class, ChunkMapperInterface::class));
	}
}
