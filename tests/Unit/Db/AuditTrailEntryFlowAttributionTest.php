<?php

/**
 * The audit rows written outside the CRUD builder — an archival or retention
 * entry and an MCP tool invocation — carry the flow run, node and step that
 * were executing when they were written, and none outside a run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunContextInterface;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins task 3.2 of flow-object-attribution: createAuditTrailEntry() and
 * recordToolInvocation() stamp the executing step before the row is inserted.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses   \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunContext
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunFrame
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses   \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ToolInvocation
 * @uses   \OCA\OpenRegister\Service\Audit\AuditRowAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\AuditTrailCollaborators
 * @uses   \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses   \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 */
class AuditTrailEntryFlowAttributionTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a recording connection whose container yields this run context.
	 *
	 * @param FlowRunContext $context The ambient run context.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(FlowRunContext $context): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('lastInsertId')->willReturn(42);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($context): object {
				if ($id === FlowRunContextInterface::class) {
					return $context;
				}

				throw new RuntimeException('not wired in this test: ' . $id);
			}
		);

		return new AuditTrailMapper(
			db: $db,
			services: new AuditTrailMapperServices(
				container: $container,
				logger: new NullLogger(),
				userSession: $this->createMock(IUserSession::class),
				request: $this->createMock(IRequest::class)
			)
		);
	}//end mapper()

	/**
	 * A run context executing step 7 of node `retention-sweep` in run `run-abc`.
	 *
	 * @return FlowRunContext
	 */
	private static function inARun(): FlowRunContext {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'run-abc', nodeId: 'retention-sweep', sequence: 7));

		return $context;
	}//end inARun()

	/**
	 * An object to write an entry about.
	 *
	 * @return ObjectEntity
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(5);
		$object->setUuid('0d4a0f1e-5b8c-4c1e-9a7d-3f2b6c8e9a01');
		$object->setRegister('1');
		$object->setSchema('2');

		return $object;
	}//end object()

	/**
	 * The value the INSERT bound for one column.
	 *
	 * @param string $column The column.
	 *
	 * @return string|null The logged value, or null when the column was not set.
	 */
	private function insertedValue(string $column): ?string {
		$placeholder = null;
		foreach ($this->queryLog as $line) {
			if (preg_match('/^#\d+ setValue\(' . preg_quote($column, '/') . ', (:p\d+)\)$/', $line, $match) === 1) {
				$placeholder = $match[1];
			}
		}

		if ($placeholder === null) {
			return null;
		}

		foreach ($this->queryLog as $line) {
			if (preg_match('/^#\d+ param ' . preg_quote($placeholder, '/') . ' = (.*), [^,]+$/', $line, $match) === 1) {
				return $match[1];
			}
		}

		return null;
	}//end insertedValue()

	/**
	 * A retention entry written during a run names the run, node and step, in the row and in the INSERT.
	 *
	 * @return void
	 */
	public function testARetentionEntryWrittenDuringARunIsAttributed(): void {
		$entry = $this->mapper(self::inARun())->createAuditTrailEntry(object: self::object(), action: 'archival.destroyed');

		$this->assertInstanceOf(AuditTrail::class, $entry);
		$this->assertSame('run-abc', $entry->getFlowRun());
		$this->assertSame('retention-sweep', $entry->getFlowNode());
		$this->assertSame(7, $entry->getFlowStep());
		$this->assertSame('run-abc', $this->insertedValue('flow_run'));
		$this->assertSame('retention-sweep', $this->insertedValue('flow_node'));
		$this->assertSame('7', $this->insertedValue('flow_step'));
	}//end testARetentionEntryWrittenDuringARunIsAttributed()

	/**
	 * A tool invocation made from inside a node is stamped exactly like the node's own write.
	 *
	 * @return void
	 */
	public function testAToolInvocationDuringARunIsAttributed(): void {
		$entry = $this->mapper(self::inARun())->recordToolInvocation(
			invocation: new ToolInvocation(toolId: 'keepiq.listEntries', paramsDigest: hash('sha256', '{}'), resultSummary: ['count' => 3])
		);

		$this->assertSame(['run-abc', 'retention-sweep', 7], [$entry->getFlowRun(), $entry->getFlowNode(), $entry->getFlowStep()]);
		$this->assertSame('run-abc', $this->insertedValue('flow_run'));
	}//end testAToolInvocationDuringARunIsAttributed()

	/**
	 * Outside a run neither entry point names a run.
	 *
	 * @return void
	 */
	public function testEntriesOutsideARunAreUnattributed(): void {
		$mapper = $this->mapper(new FlowRunContext());

		$entry = $mapper->createAuditTrailEntry(object: self::object(), action: 'archival.destroyed');
		$tool  = $mapper->recordToolInvocation(
			invocation: new ToolInvocation(toolId: 'keepiq.listEntries', paramsDigest: hash('sha256', '{}'), resultSummary: [])
		);

		$this->assertSame([null, null, null], [$entry->getFlowRun(), $entry->getFlowNode(), $entry->getFlowStep()]);
		$this->assertSame([null, null, null], [$tool->getFlowRun(), $tool->getFlowNode(), $tool->getFlowStep()]);
		$this->assertNull($this->insertedValue('flow_run'));
	}//end testEntriesOutsideARunAreUnattributed()
}//end class
