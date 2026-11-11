<?php

/**
 * ApprovalChainAdvanceGuardsGoldenTest
 *
 * Pins every early return of ApprovalChainAdvanceListener::handle(): the
 * schema lookup (tenancy off), and each declaration gap that leaves the
 * object where it is, plus the exact warning of a failed advance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Listener\ApprovalChainAdvanceListener;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden early returns and lookups of ApprovalChainAdvanceListener::handle().
 */
final class ApprovalChainAdvanceGuardsGoldenTest extends TestCase {

	/**
	 * Schema lookups, transitions and warnings, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The listener over a schema mapper answering with the given configuration (or failing).
	 *
	 * @param array|null $configuration The schema configuration; null makes the lookup fail.
	 * @param bool       $failAdvance   Whether the transition throws.
	 *
	 * @return ApprovalChainAdvanceListener
	 */
	private function listener(?array $configuration, bool $failAdvance = false): ApprovalChainAdvanceListener {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function ($id, ...$rest) use ($configuration): Schema {
				$this->events[] = ['find', $id, $rest];
				if ($configuration === null) {
					throw new RuntimeException('gone');
				}

				$schema = new Schema();
				$schema->setConfiguration($configuration);
				return $schema;
			}
		);
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action) use ($failAdvance): ObjectEntity {
				$this->events[] = ['transition', $objectId, $action];
				if ($failAdvance === true) {
					throw new RuntimeException('guard refused');
				}

				return $this->createMock(ObjectEntity::class);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->events[] = ['warning', $message];
			}
		);

		return new ApprovalChainAdvanceListener($schemas, $engine, $logger);
	}//end listener()

	/**
	 * A completed-sequence event.
	 *
	 * @param int|null $schemaId   The sequence's schema.
	 * @param string   $anchorUuid The anchored object.
	 *
	 * @return TaskSequenceCompletedEvent
	 */
	private function completed(?int $schemaId = 5, string $anchorUuid = 'obj-1'): TaskSequenceCompletedEvent {
		$sequence = new TaskSequence();
		$sequence->setSchemaId($schemaId);
		$sequence->setChainKey('submit-approval');
		$sequence->setAnchorObjectUuid($anchorUuid);

		return new TaskSequenceCompletedEvent(sequence: $sequence, finalTask: new Task(), decider: 'director1', statusOnApprove: 'approved');
	}//end completed()

	/**
	 * A configuration declaring one chain entry.
	 *
	 * @param mixed $entry The entry under `submit-approval`.
	 *
	 * @return array
	 */
	private static function declaring(mixed $entry): array {
		return ['x-openregister-approval-chains' => ['submit-approval' => $entry]];
	}//end declaring()

	/**
	 * Every gap returns before a transition; the schema is looked up with tenancy off.
	 *
	 * @return void
	 */
	public function testEveryGapLeavesTheObjectAlone(): void {
		$this->listener(configuration: [])->handle(new Event());
		$this->listener(configuration: [])->handle($this->completed(schemaId: null));
		$this->listener(configuration: null)->handle($this->completed());
		$this->listener(configuration: [])->handle($this->completed());
		$this->listener(configuration: ['x-openregister-approval-chains' => 'nope'])->handle($this->completed());
		$this->listener(configuration: self::declaring('nope'))->handle($this->completed());
		$this->listener(configuration: self::declaring(['transition' => 'submit']))->handle($this->completed());
		$this->listener(configuration: self::declaring(['onApprove' => 'advanceTransition']))->handle($this->completed());
		$this->listener(configuration: self::declaring(['onApprove' => 'advanceTransition', 'transition' => 'submit']))
			->handle($this->completed(anchorUuid: ''));

		$find = ['find', 5, [[], true, false]];
		self::assertSame(array_fill(0, 7, $find), $this->events);
	}//end testEveryGapLeavesTheObjectAlone()

	/**
	 * A declared advance runs the transition; a failing one logs the exact warning.
	 *
	 * @return void
	 */
	public function testAdvanceAndFailure(): void {
		$entry = self::declaring(['onApprove' => 'advanceTransition', 'transition' => 'submit']);
		$this->listener(configuration: $entry)->handle($this->completed());
		$this->listener(configuration: $entry, failAdvance: true)->handle($this->completed());

		$find = ['find', 5, [[], true, false]];
		self::assertSame(
			[
				$find,
				['transition', 'obj-1', 'submit'],
				$find,
				['transition', 'obj-1', 'submit'],
				['warning', '[ApprovalChainAdvanceListener] auto-advance "submit" for object obj-1 failed: guard refused'],
			],
			$this->events
		);
	}//end testAdvanceAndFailure()
}//end class
