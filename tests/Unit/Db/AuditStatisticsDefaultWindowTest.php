<?php

/**
 * A null look-back reads the documented default of 24 hours.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope
 * @uses   \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditTrailStatistics
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditActionStatistics
 */
final class AuditStatisticsDefaultWindowTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);
		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			request: $this->createMock(IRequest::class),
			logger: new NullLogger()
		);
		$this->row(id: 1, age: '-1 hour');
		$this->row(id: 2, age: '-48 hours');
	}//end setUp()

	private function row(int $id, string $age): void {
		$this->database->insert(
			'openregister_audit_trails',
			[
				'id' => $id,
				'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $id),
				'object' => 7,
				'object_uuid' => '11111111-1111-4111-8111-111111111111',
				'action' => 'update',
				'user' => 'alice',
				'created' => (new DateTime($age))->format('Y-m-d H:i:s'),
				'changed' => '{}',
			]
		);
	}//end row()

	public function testActionDistributionCountsTheDefaultWindow(): void {
		$actions = $this->mapper->getActionDistribution(hours: null)['actions'];

		$this->assertSame(1, $actions[0]['count']);
	}//end testActionDistributionCountsTheDefaultWindow()

	public function testMostActiveObjectsCountsTheDefaultWindow(): void {
		$objects = $this->mapper->getMostActiveObjects(hours: null)['objects'];

		$this->assertSame(1, $objects[0]['count']);
	}//end testMostActiveObjectsCountsTheDefaultWindow()

	public function testAnExplicitWindowStillDecides(): void {
		$actions = $this->mapper->getActionDistribution(hours: 72)['actions'];

		$this->assertSame(2, $actions[0]['count']);
	}//end testAnExplicitWindowStillDecides()
}//end class
