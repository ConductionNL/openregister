<?php

/**
 * A schedule whose store failed is reported without the error's text.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRelease;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PDOException;
use RuntimeException;

/**
 * The report of a failed schedule store.
 */
class ScheduleRefusalsFailureTest extends TestCase {

	/**
	 * The report carries a neutral reason; the log carries the error.
	 *
	 * @return void
	 */
	public function testAFailedStoreIsReportedWithoutItsText(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$refusals = new ScheduleRefusals(logger: $logger);
		$application = new ObjectEntity();
		$application->setUuid('app-uuid');

		$refusals->refuseFailure(
			release: new ScheduleRelease(application: $application, manifest: [], publisher: 'admin'),
			failure: new RuntimeException(
				message: 'Store failed: SQLSTATE[23505] in oc_openregister_flows',
				previous: new PDOException(message: 'SQLSTATE[23505]')
			)
		);

		$this->assertSame(expected: ['A schedule could not be stored.'], actual: $refusals->all());
	}//end testAFailedStoreIsReportedWithoutItsText()

	/**
	 * A failure that is not a database error keeps its own words.
	 *
	 * @return void
	 */
	public function testAnotherFailureKeepsItsReason(): void {
		$refusals = new ScheduleRefusals(logger: $this->createMock(originalClassName: LoggerInterface::class));
		$application = new ObjectEntity();
		$application->setUuid('app-uuid');

		$refusals->refuseFailure(
			release: new ScheduleRelease(application: $application, manifest: [], publisher: 'admin'),
			failure: new RuntimeException(message: 'audit down')
		);

		$this->assertSame(expected: ['audit down'], actual: $refusals->all());
	}//end testAnotherFailureKeepsItsReason()
}//end class
