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
use OCA\OpenRegister\BackgroundJob\Support\StateHistoryRebuildSources;
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

/**
 * The refusal branches of StateHistoryRebuildJob's per-object context and its
 * batch-failure handling.
 */
class StateHistoryRebuildJobBehaviourTest extends TestCase {

	private StateHistoryRebuildInterface&MockObject $rebuild;

	private AuditTrailMapperInterface&MockObject $audit;

	private SchemaMapperInterface&MockObject $schemas;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->rebuild = $this->createMock(StateHistoryRebuildInterface::class);
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueBool')->willReturn(true);
		$this->appConfig->method('getValueString')->willReturn('');
	}

	private function runJob(): void {
		$job = new StateHistoryRebuildJob(
			new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $this->appConfig, $this->logger),
			new StateHistoryRebuildSources($this->rebuild, $this->audit, $this->schemas)
		);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private function oneObjectWithSchema(): void {
		$entry = new AuditTrail();
		$entry->setSchema(7);
		$entry->setRegisterUuid('reg-1');
		$this->audit->method('findObjectUuidsAfter')->willReturn(['o-1']);
		$this->audit->method('findForObjectByAction')->willReturn([$entry]);
	}

	public function testBatchFailureLeavesTheCursorAndWarns(): void {
		$this->audit->method('findObjectUuidsAfter')->willThrowException(new \RuntimeException('db down'));
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('warning')->with(
			'[StateHistoryRebuildJob] Batch failed, the cursor stands: {error}',
			$this->callback(static fn (array $context): bool => $context['error'] === 'db down')
		);

		$this->runJob();
	}

	public function testUnfindableSchemaSkipsTheObjectButMovesTheCursor(): void {
		$this->oneObjectWithSchema();
		$this->schemas->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->rebuild->expects($this->never())->method('rebuild');
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', StateHistoryRebuildJob::CURSOR, 'o-1');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}

	public function testSchemaWithoutLifecycleAnnotationIsSkipped(): void {
		$this->oneObjectWithSchema();
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-lifecycle' => 'status']);
		$this->schemas->method('find')->willReturn($schema);
		$this->rebuild->expects($this->never())->method('rebuild');
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', StateHistoryRebuildJob::CURSOR, 'o-1');

		$this->runJob();
	}

	public function testAnnotationWithoutFieldIsSkipped(): void {
		$this->oneObjectWithSchema();
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-lifecycle' => ['states' => ['a', 'b']]]);
		$this->schemas->method('find')->willReturn($schema);
		$this->rebuild->expects($this->never())->method('rebuild');

		$this->runJob();
	}

	public function testAnnotationPropertyKeyIsAcceptedAsField(): void {
		$this->oneObjectWithSchema();
		$schema = new Schema();
		$schema->setSlug('cases');
		$schema->setConfiguration(['x-openregister-lifecycle' => ['property' => 'phase']]);
		$this->schemas->method('find')->willReturn($schema);
		$this->rebuild->expects($this->once())->method('rebuild')
			->with(new ObjectRef(register: 'reg-1', schema: 'cases', id: 'o-1'), 'phase')->willReturn(1);

		$this->runJob();
	}
}
