<?php

/**
 * ExpiredObjectStoreBindingTest: the sweep's store is bound by Core, through interface-typed storage.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Retention
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

namespace OCA\OpenRegister\Tests\Unit\Service\Retention;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ComplianceMapperBindings;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStore;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * The sweep resolves its store from Core's map; the store's audit and file collaborators resolve to the same classes as before.
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */
final class ExpiredObjectStoreBindingTest extends TestCase {

	public function testCoreBindsTheStoreOnce(): void {
		$this->assertSame(ExpiredObjectStore::class, CoreServiceBindings::BINDINGS[ExpiredObjectStoreInterface::class] ?? null);
		$this->assertArrayNotHasKey(ExpiredObjectStoreInterface::class, ContractBindings::BINDINGS);
		$this->assertTrue(is_subclass_of(ExpiredObjectStore::class, ExpiredObjectStoreInterface::class));
	}

	public function testTheStoresCollaboratorsResolveToTheAuditTrailAndTheFileService(): void {
		$this->assertSame(AuditTrailMapper::class, ComplianceMapperBindings::BINDINGS[AuditTrailMapperInterface::class] ?? null);
		$this->assertSame(FileService::class, ContractBindings::BINDINGS[ObjectFilesInterface::class] ?? null);
	}
}
