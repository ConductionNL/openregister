<?php

/**
 * The register import's pack lookup answers with the stored pack's definition,
 * and lets an unknown slug's DoesNotExistException through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Kernel\Contracts\Core\MigrationPackDefinitionsInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackDefinitions;
use OCA\OpenRegister\Service\MigrationPackServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

final class MigrationPackDefinitionsTest extends TestCase {

	public function testTheDefinitionIsTheStoredPacksDefinition(): void {
		$pack = new MigrationPack();
		$pack->setDefinition(json_encode(['slug' => 'zgw-zaken', 'mappings' => []]));
		$packs = $this->createMock(MigrationPackServiceInterface::class);
		$packs->expects($this->once())->method('findByPackSlug')->with('zgw-zaken')->willReturn($pack);

		$definitions = new MigrationPackDefinitions(packs: $packs);

		$this->assertSame(['slug' => 'zgw-zaken', 'mappings' => []], $definitions->definitionBySlug(packSlug: 'zgw-zaken'));
	}//end testTheDefinitionIsTheStoredPacksDefinition()

	public function testAnUnknownSlugThrowsDoesNotExist(): void {
		$packs = $this->createMock(MigrationPackServiceInterface::class);
		$packs->method('findByPackSlug')->willThrowException(new DoesNotExistException('none'));

		$this->expectException(DoesNotExistException::class);
		(new MigrationPackDefinitions(packs: $packs))->definitionBySlug(packSlug: 'missing');
	}//end testAnUnknownSlugThrowsDoesNotExist()

	public function testThePortIsBoundToTheAdapterInConfigurationsBindings(): void {
		$this->assertSame(MigrationPackDefinitions::class, ContractBindings::BINDINGS[MigrationPackDefinitionsInterface::class]);
	}//end testThePortIsBoundToTheAdapterInConfigurationsBindings()
}//end class
