<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use OCA\OpenRegister\BackgroundJob\StateHistoryRebuildJob;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\History\StateHistoryRebuildInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\BackgroundJob\Support\StateHistoryRebuildSources;

/**
 * Pins the flag gate, the end of the walk and one rebuilt batch of the state history repair.
 */
class StateHistoryRebuildJobTest extends TestCase {

	/**
	 * The rebuild collaborator.
	 *
	 * @var StateHistoryRebuildInterface&MockObject
	 */
	private StateHistoryRebuildInterface&MockObject $rebuild;

	/**
	 * The audit trail the walk reads.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $audit;

	/**
	 * The schema lookup.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The app config holding flag and cursor.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rebuild = $this->createMock(StateHistoryRebuildInterface::class);
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * Build and run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$job = new StateHistoryRebuildJob(
			new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $this->appConfig, $this->createMock(LoggerInterface::class)),
			new StateHistoryRebuildSources($this->rebuild, $this->audit, $this->schemas)
		);
		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * With the flag off nothing is read.
	 *
	 * @return void
	 */
	public function testFlagOffReadsNothing(): void {
		$this->appConfig->method('getValueBool')->willReturn(false);
		$this->audit->expects($this->never())->method('findObjectUuidsAfter');
		$this->runJob();
	}//end testFlagOffReadsNothing()

	/**
	 * An empty batch clears flag and cursor.
	 *
	 * @return void
	 */
	public function testEmptyBatchEndsTheWalk(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('');
		$this->audit->method('findObjectUuidsAfter')->willReturn([]);
		$this->appConfig->expects($this->once())->method('setValueBool')
			->with('openregister', StateHistoryRebuildJob::FLAG, false);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', StateHistoryRebuildJob::CURSOR, '');
		$this->runJob();
	}//end testEmptyBatchEndsTheWalk()

	/**
	 * A batch rebuilds each lifecycle object and moves the cursor to the last uuid.
	 *
	 * @return void
	 */
	public function testBatchRebuildsAndMovesTheCursor(): void {
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('c-0');

		$entry = new AuditTrail();
		$entry->setSchema(7);
		$entry->setRegisterUuid('reg-1');
		$schema = new Schema();
		$schema->setSlug('cases');
		$schema->setConfiguration(['x-openregister-lifecycle' => ['field' => 'status']]);

		$this->audit->method('findObjectUuidsAfter')->with('c-0', StateHistoryRebuildJob::BATCH)->willReturn(['o-1', 'o-2']);
		$this->audit->method('findForObjectByAction')->willReturnCallback(
			function (string $objectUuid) use ($entry): array {
				if ($objectUuid === 'o-1') {
					return [$entry];
				}

				return [];
			}
		);
		$this->schemas->method('find')->willReturn($schema);
		$this->rebuild->expects($this->once())->method('rebuild')
			->with(new ObjectRef(register: 'reg-1', schema: 'cases', id: 'o-1'), 'status')->willReturn(2);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', StateHistoryRebuildJob::CURSOR, 'o-2');
		$this->runJob();
	}//end testBatchRebuildsAndMovesTheCursor()
}//end class
