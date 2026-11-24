<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ApiConformanceCommand;
use OCA\OpenRegister\Service\Oas\NlGovConformanceReport;
use OCA\OpenRegister\Service\Oas\NlGovConformanceService;
use OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The occ command prints one line per rule, or the report as JSON.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
#[CoversClass(ApiConformanceCommand::class)]
#[UsesClass(NlGovConformanceReport::class)]
#[UsesClass(NlGovRuleCatalogue::class)]
#[UsesClass(OasValidationReport::class)]
class ApiConformanceCommandTest extends TestCase {

	/**
	 * A report over an empty validation report.
	 *
	 * @return array<string, mixed>
	 */
	private static function report(): array {
		return (new NlGovConformanceReport())->build(report: new OasValidationReport(), probe: null, generatedAt: '2026-11-24T12:00:00+00:00');
	}

	public function testItPrintsOneLinePerRuleAndExitsZero(): void {
		$service = $this->createMock(NlGovConformanceService::class);
		$service->expects($this->once())->method('report')->with('7')->willReturn(self::report());
		$service->expects($this->never())->method('probe');

		$tester = new CommandTester(new ApiConformanceCommand($service));
		$exit   = $tester->execute(['register' => '7']);
		$output = $tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		foreach (NlGovRuleCatalogue::rules() as $rule) {
			$this->assertSame(1, substr_count($output, '| '.$rule['id'].' '), $rule['id']);
		}

		$this->assertStringContainsString('not-probed: 3', $output);
	}

	public function testProbeRunsTheProbeInsteadOfAPlainReport(): void {
		$service = $this->createMock(NlGovConformanceService::class);
		$service->expects($this->never())->method('report');
		$service->expects($this->once())->method('probe')->with('7')->willReturn(self::report());

		$tester = new CommandTester(new ApiConformanceCommand($service));

		$this->assertSame(Command::SUCCESS, $tester->execute(['register' => '7', '--probe' => true]));
	}

	public function testJsonOutputIsTheReport(): void {
		$service = $this->createMock(NlGovConformanceService::class);
		$service->method('report')->willReturn(self::report());

		$tester = new CommandTester(new ApiConformanceCommand($service));
		$tester->execute(['register' => '7', '--output' => 'json']);

		$this->assertSame(self::report(), json_decode($tester->getDisplay(), true));
	}

	public function testAnUnknownRegisterFails(): void {
		$service = $this->createMock(NlGovConformanceService::class);
		$service->method('report')->willThrowException(new DoesNotExistException('none'));

		$tester = new CommandTester(new ApiConformanceCommand($service));

		$this->assertSame(Command::FAILURE, $tester->execute(['register' => 'nope']));
		$this->assertStringContainsString('Register "nope" not found.', $tester->getDisplay());
	}
}
