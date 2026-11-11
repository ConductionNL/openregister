<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Repair\ImportDecisionTableRegister;
use OCA\OpenRegister\Repair\ImportDismissedPairRegister;
use OCA\OpenRegister\Repair\ImportDsarRegisters;
use OCA\OpenRegister\Repair\ImportDestructionListRegister;
use OCA\OpenRegister\Repair\ImportEdepotTransferRegister;
use OCA\OpenRegister\Repair\ImportMergeOperationRegister;
use OCA\OpenRegister\Repair\ImportTrustConfigurationRegister;
use OCA\OpenRegister\Repair\SeedVocabularyRegister;
use OCA\OpenRegister\Service\Configuration\DescriptorConfigIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Every descriptor of OpenRegister that imports under an id of its own, and the repair step that imports it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\DescriptorConfigIds
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
class DescriptorConfigIdsTest extends TestCase {

	/**
	 * The nine descriptors with their id and the repair step that imports each.
	 *
	 * @return array<string, array{0: string, 1: string, 2: class-string}>
	 */
	public static function descriptors(): array {
		return [
			'decision tables' => ['decision_table_register.json', 'openregister.decision-tables', ImportDecisionTableRegister::class],
			'dismissed pair' => ['dismissed_pair_register.json', 'openregister.dismissed-pair', ImportDismissedPairRegister::class],
			'edepot transfers' => ['edepot_transfer_register.json', 'openregister.edepot-transfers', ImportEdepotTransferRegister::class],
			'destruction lists' => ['destruction_list_register.json', 'openregister.destruction-lists', ImportDestructionListRegister::class],
			'merge operation' => ['merge_operation_register.json', 'openregister.merge-operation', ImportMergeOperationRegister::class],
			'trust configuration' => ['trust_configuration_register.json', 'openregister.trust-configuration', ImportTrustConfigurationRegister::class],
			'vocabulary' => ['vocabulary_register.json', 'openregister.vocabulary', SeedVocabularyRegister::class],
			'dsar cases' => ['data_subject_request_register.json', 'openregister.dsar-cases', ImportDsarRegisters::class],
			'dsar policy packs' => ['dsar_policy_pack_register.json', 'openregister.dsar-policy-packs', ImportDsarRegisters::class],
		];
	}//end descriptors()

	/**
	 * The file maps to its id, only for OpenRegister's own descriptors, and the descriptor exists.
	 *
	 * @param string $file The descriptor file.
	 * @param string $id   The expected configuration id.
	 *
	 * @return void
	 */
	#[DataProvider('descriptors')]
	public function testTheFileMapsToItsOwnId(string $file, string $id): void {
		$this->assertSame($id, DescriptorConfigIds::forFile(appId: 'openregister', file: $file));
		$this->assertSame($id, DescriptorConfigIds::BY_FILE[$file]);
		$this->assertSame('fixtureapp', DescriptorConfigIds::forFile(appId: 'fixtureapp', file: $file));
		$this->assertFileExists(dirname(__DIR__, 4) . '/lib/Settings/' . $file);
	}//end testTheFileMapsToItsOwnId()

	/**
	 * The map holds exactly these nine, so a tenth descriptor cannot be added without a test row.
	 *
	 * @return void
	 */
	public function testTheMapHoldsExactlyTheNineDescriptors(): void {
		$files = array_map(static fn (array $row): string => $row[0], array_values(self::descriptors()));
		$this->assertEqualsCanonicalizing($files, array_keys(DescriptorConfigIds::BY_FILE));
		$this->assertCount(9, array_unique(DescriptorConfigIds::BY_FILE), 'no two descriptors share an id');
		$this->assertSame('openregister', DescriptorConfigIds::forFile(appId: 'openregister', file: 'flow_register.json'));
	}//end testTheMapHoldsExactlyTheNineDescriptors()

	/**
	 * Every repair step imports its descriptor under the id the map gives that file (never the shared one).
	 *
	 * @param string $file  The descriptor file.
	 * @param string $id    The expected configuration id.
	 * @param string $class The repair step.
	 *
	 * @return void
	 */
	#[DataProvider('descriptors')]
	public function testEveryRepairStepReadsTheMap(string $file, string $id, string $class): void {
		if ($class === ImportDsarRegisters::class) {
			$registers = (new ReflectionClassConstant($class, 'REGISTERS'))->getValue();
			$this->assertSame($id, $registers['/lib/Settings/' . $file]['appId']);

			return;
		}

		$this->assertSame('/lib/Settings/' . $file, (new ReflectionClassConstant($class, 'REGISTER_PATH'))->getValue());
		$this->assertSame($id, (new ReflectionClassConstant($class, 'CONFIG_APP_ID'))->getValue());
		$this->assertNotSame(DescriptorConfigIds::SHARED, $id);
	}//end testEveryRepairStepReadsTheMap()
}//end class
