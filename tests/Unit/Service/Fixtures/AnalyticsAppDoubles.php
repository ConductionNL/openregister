<?php

/**
 * Test double for the optional Nextcloud Analytics app (not installed in the
 * unit-test environment). The OCA\Analytics class name only has to exist for
 * AnalyticsLinkService's class_exists() probe; it is declared empty, exactly as
 * AnalyticsLinkServiceTest declares it, so either file may load first. The
 * report service double the container hands out lives in this test namespace
 * and exposes only methods the real ReportService has.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Fixtures;

if (class_exists('OCA\\Analytics\\Service\\ReportService') === false) {
	eval('namespace OCA\\Analytics\\Service; class ReportService {}');
}

/**
 * Double of \OCA\Analytics\Service\ReportService.
 */
class AnalyticsReportServiceDouble {
	/** @var array<int, mixed> report id => report array or Throwable */
	public array $reports = [];
	public mixed $index = [];
	public mixed $createResult = 0;
	public array $created = [];

	public function read(int $reportId): mixed {
		$report = $this->reports[$reportId] ?? [];
		if ($report instanceof \Throwable) {
			throw $report;
		}
		return $report;
	}

	public function index(): mixed {
		if ($this->index instanceof \Throwable) {
			throw $this->index;
		}
		return $this->index;
	}

	public function create(...$args): mixed {
		$this->created[] = $args;
		if ($this->createResult instanceof \Throwable) {
			throw $this->createResult;
		}
		return $this->createResult;
	}
}
