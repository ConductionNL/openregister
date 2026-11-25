<?php

/**
 * Tests for ImportReportTemplatesRegister: the report-templates schema lands on upgrade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\AbstractRegisterImportStep;
use OCA\OpenRegister\Repair\ImportReportTemplatesRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ImportReportTemplatesRegister::class)]
#[CoversClass(AbstractRegisterImportStep::class)]
#[CoversClass(RegisterDescriptorImport::class)]
class ImportReportTemplatesRegisterTest extends TestCase {

	public function testTheShippedDescriptorDeclaresTheReportTemplatesSchema(): void {
		$configurationService = $this->createMock(ConfigurationService::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn((string)realpath(__DIR__ . '/../../..'));
		$output = $this->createMock(IOutput::class);
		$seen = null;
		$configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('openregister'),
				$this->callback(
					static function (array $data) use (&$seen): bool {
						$seen = $data;
						return true;
					}
				),
				$this->equalTo('1.0.0'),
				$this->equalTo(false)
			);
		$output->expects($this->once())->method('info')->with($this->stringContains('Report-templates register imported'));

		$step = new ImportReportTemplatesRegister(
			import: new RegisterDescriptorImport(
				configurationService: $configurationService,
				appManager: $appManager,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
		$step->run($output);

		$schema = $seen['components']['schemas']['report-templates'];
		$this->assertSame(['name', 'sections'], $schema['required']);
		$this->assertSame(['total', 'grouped', 'series'], $schema['properties']['sections']['items']['properties']['type']['enum']);
		$this->assertSame(['report-templates'], $seen['components']['registers']['report-templates']['schemas']);
		$this->assertStringContainsString('report-templates', $step->getName());
	}//end testTheShippedDescriptorDeclaresTheReportTemplatesSchema()
}//end class
