<?php

/**
 * A schema rename warns about every published update trigger that still watches the old property name.
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
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraphInterface;
use OCA\OpenRegister\Service\Flow\FlowTriggerRenameCheck;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerRenameCheck
 * @uses \OCA\OpenRegister\Service\Schema\SchemaChangeSet
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\Flow\FlowSelection
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class FlowTriggerRenameCheckTest extends TestCase {

	/**
	 * Schema 7 `melding`.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('melding');
		$schema->setUuid('11111111-2222-3333-4444-555555555555');

		return $schema;
	}//end schema()

	/**
	 * A change set renaming `omschrijving` to `beschrijving`, plus an unrelated added property.
	 *
	 * @return SchemaChangeSet
	 */
	private function renaming(): SchemaChangeSet {
		return new SchemaChangeSet(
			[
				['property' => 'omschrijving', 'kind' => 'renamed', 'old' => 'omschrijving', 'new' => 'beschrijving'],
				['property' => 'prioriteit', 'kind' => 'added'],
			],
			'breaking',
			'major'
		);
	}//end renaming()

	/**
	 * An object trigger node.
	 *
	 * @param string             $id     The step id.
	 * @param string             $schema The schema reference.
	 * @param array<int, string> $fields The watched fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function trigger(string $id, string $schema, array $fields): array {
		return [
			'id' => $id,
			'type' => 'openregister.trigger-object',
			'config' => ['event' => 'object.updated', 'register' => 'zaken', 'schema' => $schema, 'changedFields' => $fields],
		];
	}//end trigger()

	/**
	 * A check over these flows and their published graphs (null: never published).
	 *
	 * @param array<string, array<string, mixed>|null> $graphs  Published graph per flow uuid.
	 * @param LoggerInterface|null                     $logger  The logger.
	 * @param FlowMapperInterface|null                 $mapper  A mapper to use instead.
	 *
	 * @return FlowTriggerRenameCheck
	 */
	private function check(array $graphs, ?LoggerInterface $logger=null, ?FlowMapperInterface $mapper=null): FlowTriggerRenameCheck {
		if ($mapper === null) {
			$mapper = $this->createMock(FlowMapperInterface::class);
			$mapper->method('findAllFlows')->willReturnCallback(
				static function () use ($graphs): array {
					return array_map(
						static function (string $uuid): Flow {
							$flow = new Flow();
							$flow->setUuid($uuid);

							return $flow;
						},
						array_keys($graphs)
					);
				}
			);
		}

		$published = $this->createMock(FlowPublishedGraphInterface::class);
		$published->method('graphOf')->willReturnCallback(static fn (string $flowId): ?array => $graphs[$flowId] ?? null);

		return new FlowTriggerRenameCheck($mapper, $published, $logger ?? $this->createMock(LoggerInterface::class));
	}//end check()

	/**
	 * A trigger on the renamed schema that watches the old name is named, by slug, id or uuid.
	 *
	 * @return void
	 */
	public function testAWatchedFieldThatIsRenamedIsWarnedAbout(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$warnings = $this->check(
			[
				'flow-a' => ['nodes' => [self::trigger('start', 'melding', ['omschrijving', 'status'])]],
				'flow-b' => ['nodes' => [self::trigger('begin', '7', ['omschrijving'])]],
			],
			$logger
		)->warningsFor($this->schema(), $this->renaming());

		$this->assertSame(
			[
				[
					'flow' => 'flow-a',
					'step' => 'start',
					'field' => 'omschrijving',
					'renamedTo' => 'beschrijving',
					'message' => 'Flow "flow-a" step "start" watches "omschrijving", which this change renames to "beschrijving"; the trigger will not start until it watches the new name.',
				],
				[
					'flow' => 'flow-b',
					'step' => 'begin',
					'field' => 'omschrijving',
					'renamedTo' => 'beschrijving',
					'message' => 'Flow "flow-b" step "begin" watches "omschrijving", which this change renames to "beschrijving"; the trigger will not start until it watches the new name.',
				],
			],
			$warnings
		);
	}//end testAWatchedFieldThatIsRenamedIsWarnedAbout()

	/**
	 * Triggers on another schema, triggers that watch other fields, unpublished flows and other node types stay quiet.
	 *
	 * @return void
	 */
	public function testOnlyTriggersOnThisSchemaWatchingTheOldNameAreNamed(): void {
		$warnings = $this->check(
			[
				'other-schema' => ['nodes' => [self::trigger('start', 'aanvraag', ['omschrijving'])]],
				'other-field' => ['nodes' => [self::trigger('start', 'melding', ['status'])]],
				'never-published' => null,
				'no-filter' => ['nodes' => [['id' => 'start', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'schema' => 'melding']]]],
				'not-a-trigger' => ['nodes' => [['id' => 'mail', 'type' => 'openregister.send-email', 'config' => ['schema' => 'melding', 'changedFields' => ['omschrijving']]]]],
				'by-uuid' => ['nodes' => [self::trigger('start', '11111111-2222-3333-4444-555555555555', ['omschrijving'])]],
			]
		)->warningsFor($this->schema(), $this->renaming());

		$this->assertSame(['by-uuid'], array_column($warnings, 'flow'));
	}//end testOnlyTriggersOnThisSchemaWatchingTheOldNameAreNamed()

	/**
	 * A change set without a rename does not even list the flows.
	 *
	 * @return void
	 */
	public function testAChangeWithoutARenameReadsNoFlows(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects($this->never())->method('findAllFlows');

		$warnings = $this->check([], null, $mapper)->warningsFor(
			$this->schema(),
			new SchemaChangeSet([['property' => 'prioriteit', 'kind' => 'added']], 'additive', 'minor')
		);

		$this->assertSame([], $warnings);
	}//end testAChangeWithoutARenameReadsNoFlows()

	/**
	 * Flows that cannot be listed leave the rename unchecked and logged, never refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableFlowListIsLoggedAndWarnsNothing(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[FlowTriggerRenameCheck] Could not list the flows; the rename was not checked against them',
			['error' => 'database gone']
		);

		$this->assertSame([], $this->check([], $logger, $mapper)->warningsFor($this->schema(), $this->renaming()));
	}//end testAnUnreadableFlowListIsLoggedAndWarnsNothing()

	/**
	 * The flows are read a page at a time until a short page.
	 *
	 * @return void
	 */
	public function testTheFlowsAreReadPageByPage(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$offsets = [];
		$mapper->method('findAllFlows')->willReturnCallback(
			static function ($selection, $query) use (&$offsets): array {
				$offsets[] = $query->offset;
				$count = ($query->offset === 0) ? 100 : 1;

				return array_map(
					static function (int $i) use ($query): Flow {
						$flow = new Flow();
						$flow->setUuid('flow-' . ($query->offset + $i));

						return $flow;
					},
					range(1, $count)
				);
			}
		);

		$this->check(['flow-101' => ['nodes' => [self::trigger('start', 'melding', ['omschrijving'])]]], null, $mapper)
			->warningsFor($this->schema(), $this->renaming());

		$this->assertSame([0, 100], $offsets);
	}//end testTheFlowsAreReadPageByPage()
}//end class
