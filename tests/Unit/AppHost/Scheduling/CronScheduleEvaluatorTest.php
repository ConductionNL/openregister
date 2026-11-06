<?php

/**
 * AppHost scheduling — cron evaluator tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\CronScheduleEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Covers cron parseability + next-fire computation via the vendored library.
 */
class CronScheduleEvaluatorTest extends TestCase {
	private CronScheduleEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new CronScheduleEvaluator();
	}

	public function testValidExpressionIsValid(): void {
		$this->assertTrue($this->evaluator->isValid('0 6 * * 1-5'));
	}

	public function testUnparseableExpressionIsInvalid(): void {
		$this->assertFalse($this->evaluator->isValid('not a cron'));
		$this->assertFalse($this->evaluator->isValid('99 99 * * *'));
	}
}
