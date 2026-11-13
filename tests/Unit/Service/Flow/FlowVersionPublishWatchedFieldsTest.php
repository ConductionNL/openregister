<?php

/**
 * Publishing a flow whose update trigger watches an unknown property is refused before anything is written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md#requirement-an-update-trigger-can-watch-named-fields
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerFieldsCheck;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionGraphs;
use OCA\OpenRegister\Service\Flow\FlowVersionRows;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowVersionService
 * @uses \OCA\OpenRegister\Service\Flow\FlowTriggerFieldsCheck
 * @uses \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused
 * @uses \OCA\OpenRegister\Service\Flow\FlowVersionGraphs
 * @uses \OCA\OpenRegister\Service\Flow\FlowVersionRows
 * @uses \OCA\OpenRegister\Service\Flow\FlowDefinitionPin
 * @uses \OCA\OpenRegister\Service\Flow\FlowSemanticVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowPublishOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowPublishRun
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class FlowVersionPublishWatchedFieldsTest extends TestCase {

	/**
	 * Whether a transaction was opened.
	 *
	 * @var boolean
	 */
	private bool $began = false;

	/**
	 * A version service whose publish check knows schema `melding` with `omschrijving` only.
	 *
	 * @return FlowVersionService
	 */
	private function service(): FlowVersionService {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(
			static function (): Register {
				$register = new Register();
				$register->setSchemas([7]);
				return $register;
			}
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function (): Schema {
				$schema = new Schema();
				$schema->setId(7);
				$schema->setSlug('melding');
				$schema->setProperties(['omschrijving' => ['type' => 'string']]);
				return $schema;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void { $this->began = true; });

		return new FlowVersionService(
			new FlowVersionRows($this->createMock(FlowVersionMapper::class), $this->createMock(FlowMapper::class), $db, new NullLogger()),
			new FlowVersionGraphs(
				new FlowDefinitionPin($this->createMock(FlowDefinitionMapper::class), new NullLogger()),
				$this->createMock(FlowLifecycleGuard::class),
				$this->createMock(FlowTriggerIndex::class),
				new FlowSemanticVersion(),
				new FlowTriggerFieldsCheck($registers, $schemas)
			)
		);
	}//end service()

	/**
	 * Publishing a draft that watches `beschrijving` on `melding` is refused, naming the field, and opens no transaction.
	 *
	 * @return void
	 */
	public function testPublishingAnUnknownWatchedFieldIsRefused(): void {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
		$flow->setNodes(
			[
				['id' => 'start', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'register' => 'zaken', 'schema' => 'melding', 'changedFields' => ['beschrijving']]],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			]
		);
		$flow->setEdges([['id' => 'e1', 'from' => 'start', 'to' => 'done']]);

		try {
			$this->service()->publish($flow);
		} catch (FlowLifecycleRefused $e) {
			$this->assertSame(FlowLifecycleRefused::REASON_UNKNOWN_CHANGED_FIELD, $e->getReason());
			$this->assertStringContainsString('"beschrijving"', $e->getMessage());
			$this->assertFalse($this->began, 'the refusal comes before anything is written');
			return;
		}

		$this->fail('Expected the publish to be refused.');
	}//end testPublishingAnUnknownWatchedFieldIsRefused()
}//end class
