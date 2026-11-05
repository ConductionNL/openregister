<?php

/**
 * Golden test for the DashboardService statistics and failure reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Search\Service\DashboardService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what the dashboard reads return and log, success and failure, against a recorded golden.
 *
 * @covers \OCA\OpenRegister\Search\Service\DashboardService
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class DashboardServiceReadsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/dashboard-service-reads.json';

	/**
	 * Messages the logger received, in order.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * A service whose mappers answer with distinct numbers, or throw when $fail is true.
	 *
	 * @param bool $fail Whether every mapper read throws.
	 *
	 * @return DashboardService
	 */
	private function service(bool $fail): DashboardService {
		$objects = $this->createMock(MagicMapper::class);
		$audit = $this->createMock(AuditTrailMapper::class);
		$webhooks = $this->createMock(WebhookLogMapper::class);
		$registers = $this->createMock(RegisterMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = $message . ' file=' . basename((string)($context['file'] ?? ''));
		});

		$register = new Register();
		$register->setId(7);
		$register->setTitle('Reg');
		$schema = new Schema();
		$schema->setId(70);
		$schema->setTitle('Sch');
		$registers->method('findAll')->willReturn([$register]);
		$registers->method('getSchemasByRegisterId')->willReturn([$schema]);

		if ($fail === true) {
			$boom = new Exception('boom');
			foreach (['getStatistics', 'getRegisterChartData', 'getSchemaChartData', 'getSizeDistributionChartData'] as $m) {
				$objects->method($m)->willThrowException($boom);
			}

			foreach (['getStatistics', 'getActionChartData', 'getDetailedStatistics', 'getActionDistribution', 'getMostActiveObjects'] as $m) {
				$audit->method($m)->willThrowException($boom);
			}
		} else {
			$objects->method('getStatistics')->willReturnCallback(function (...$args): array {
				$this->logged[] = 'objects.getStatistics ' . json_encode($args);
				return ['total' => 11, 'size' => 12, 'invalid' => 13, 'deleted' => 14, 'locked' => 15, 'published' => 16];
			});
			$audit->method('getStatistics')->willReturnCallback(function (...$args): array {
				$this->logged[] = 'audit.getStatistics ' . json_encode($args);
				return ['total' => 21, 'size' => 22];
			});
			$webhooks->method('getStatistics')->willReturn(['total' => 31]);
		}

		return new DashboardService($objects, $audit, $webhooks, $registers, $this->createMock(SchemaMapper::class), $logger);
	}

	/**
	 * The id and stats of one dashboard entry and of its schemas.
	 *
	 * @param array<string, mixed> $entry A register (or totals/orphaned) entry.
	 *
	 * @return array<string, mixed>
	 */
	private function statsOnly(array $entry): array {
		$schemas = [];
		foreach (($entry['schemas'] ?? []) as $schema) {
			$schemas[] = ['id' => $schema['id'] ?? null, 'stats' => $schema['stats'] ?? null];
		}

		return ['id' => $entry['id'] ?? null, 'stats' => $entry['stats'] ?? null, 'schemas' => $schemas];
	}

	/**
	 * Everything the dashboard reads return, followed by what was logged.
	 *
	 * @param bool $fail Whether every mapper read throws.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(bool $fail): array {
		$this->logged = [];
		$service = $this->service(fail: $fail);
		$out = ['registersWithSchemas' => array_map([$this, 'statsOnly'], $service->getRegistersWithSchemas())];
		if ($fail === true) {
			$out['actionChart'] = $service->getAuditTrailActionChartData();
			$out['byRegister'] = $service->getObjectsByRegisterChartData();
			$out['bySchema'] = $service->getObjectsBySchemaChartData();
			$out['bySize'] = $service->getObjectsBySizeChartData();
			$out['auditStats'] = $service->getAuditTrailStatistics();
			$out['distribution'] = $service->getAuditTrailActionDistribution();
			$out['mostActive'] = $service->getMostActiveObjects();
		}

		$out['logged'] = $this->logged;
		return $out;
	}

	/**
	 * The reads and logs match the golden recorded on the code before the helpers were extracted.
	 *
	 * @return void
	 */
	public function testReadsMatchTheGolden(): void {
		$actual = json_encode(['ok' => $this->snapshot(fail: false), 'failed' => $this->snapshot(fail: true)], JSON_PRETTY_PRINT);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $actual . "\n");
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $actual . "\n");
	}
}
