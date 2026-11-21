<?php

/**
 * SeedZgwZakenMigrationPack seeds the built-in pack once and never fails the upgrade.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\SeedZgwZakenMigrationPack;
use OCA\OpenRegister\Service\MigrationPackServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The present / seeded / seed-failed branches of the reference-pack seed.
 */
class SeedZgwZakenMigrationPackBehaviourTest extends TestCase {

	private MigrationPackServiceInterface&MockObject $packs;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	protected function setUp(): void {
		$this->packs = $this->createMock(MigrationPackServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}

	private function step(): SeedZgwZakenMigrationPack {
		return new SeedZgwZakenMigrationPack($this->packs, $this->logger);
	}

	public function testTheStepNameIsWhatOccPrints(): void {
		$this->assertSame('Seed OpenRegister built-in reference migration pack (zgw-zaken-json)', $this->step()->getName());
	}

	public function testAPackAlreadyPresentIsNotSeededAgain(): void {
		$this->packs->expects($this->once())->method('findByPackSlug')->with('zgw-zaken-json');
		$this->packs->expects($this->never())->method('seedBuiltin');
		$this->output->expects($this->once())->method('info')->with('Migration pack "zgw-zaken-json" already present, skipping seed');

		$this->step()->run($this->output);
	}

	public function testAMissingPackIsSeededAsABuiltinWithoutOwner(): void {
		$this->packs->method('findByPackSlug')->willThrowException(new DoesNotExistException('none'));
		$this->packs->expects($this->once())->method('seedBuiltin')
			->with(
				$this->callback(
					static fn (array $definition): bool => $definition['id'] === 'zgw-zaken-json'
						&& $definition['sourceFormat'] === 'json'
						&& count($definition['fieldMappings']) === 7
				)
			);
		$this->output->expects($this->once())->method('info')->with('Seeded built-in migration pack "zgw-zaken-json"');
		$this->output->expects($this->never())->method('warning');

		$this->step()->run($this->output);
	}

	public function testAFailingSeedIsAWarningNotAFailure(): void {
		$this->packs->method('findByPackSlug')->willThrowException(new DoesNotExistException('none'));
		$this->packs->method('seedBuiltin')->willThrowException(new RuntimeException('invalid definition'));
		$this->logger->expects($this->once())->method('warning')->with('[SeedZgwZakenMigrationPack] seed failed: invalid definition');
		$this->output->expects($this->once())->method('warning')->with('Migration pack seed skipped: invalid definition');
		$this->output->expects($this->never())->method('info');

		$this->step()->run($this->output);
	}
}
