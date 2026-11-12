<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins getActiveOrganisationForContext(): the uuid it returns and what it logs
 * when there is an organisation, none, or the lookup fails.
 *
 * @covers \OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class PerformanceOptimizationOrganisationTest extends TestCase {

	/**
	 * @return array<string, array{mixed, ?string, list<array{string, string, array<string, mixed>}>}>
	 */
	public static function cases(): array {
		return [
			'organisation' => ['org', 'uuid-1', [['debug', '[PerformanceOptimizationHandler] Got active organisation for context', ['organisationUuid' => 'uuid-1', 'organisationName' => 'Gemeente']]]],
			'none'         => [null, null, [['debug', '[PerformanceOptimizationHandler] No active organisation for current user', []]]],
			'failure'      => ['throw', null, [['warning', '[PerformanceOptimizationHandler] Failed to get active organisation', ['error' => 'down']]]],
		];
	}//end cases()

	/**
	 * @dataProvider cases
	 */
	public function testActiveOrganisation(mixed $answer, ?string $expected, array $logs): void {
		$organisations = $this->createMock(OrganisationServiceInterface::class);
		if ($answer === 'throw') {
			$organisations->method('getActiveOrganisation')->willThrowException(new RuntimeException('down'));
		} elseif ($answer === 'org') {
			$org = new Organisation();
			$org->setUuid('uuid-1');
			$org->setName('Gemeente');
			$organisations->method('getActiveOrganisation')->willReturn($org);
		} else {
			$organisations->method('getActiveOrganisation')->willReturn(null);
		}

		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, $context = []) use (&$calls, $level) {
				unset($context['file'], $context['line']);
				$calls[] = [$level, $message, $context];
			});
		}

		$handler = new PerformanceOptimizationHandler($organisations, $logger);
		$this->assertSame($expected, $handler->getActiveOrganisationForContext());
		$this->assertSame($logs, $calls);
	}//end testActiveOrganisation()
}//end class
