<?php

/**
 * Unit coverage for FlowTriggerValidator — the save-time trigger check.
 *
 * This class exists because `TriggerScheduleNode::validateConfig()` was an
 * ORPHANED CAPABILITY: written, unit-tested, and never called on the save path,
 * so a schedule trigger with no cron and no identity stored with HTTP 201.
 *
 * These tests therefore assert two different things, and the second is the one
 * that matters. That the validator REFUSES a bad trigger is easy and was already
 * true. That it is REACHED — that a node's own verdict actually propagates out of
 * `validate()` to the caller — is what was missing, and every refusal case here
 * is paired with a positive control so a validator that rejects everything cannot
 * pass this file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Locks the reachability and the refusal behaviour of the trigger validator.
 */
class FlowTriggerValidatorTest extends TestCase {

	/**
	 * Node ids the registry resolves, keyed by type.
	 *
	 * @var array<string, object>
	 */
	private array $nodes = [];

	/**
	 * Build a validator whose registry resolves $this->nodes.
	 *
	 * @param boolean $registryResolves Whether the container can supply a registry.
	 *
	 * @return FlowTriggerValidator The validator under test.
	 */
	private function validator(bool $registryResolves = true): FlowTriggerValidator {
		$registry = new class($this->nodes) {
			/**
			 * @param array<string, object> $nodes The resolvable nodes.
			 */
			public function __construct(private readonly array $nodes) {
			}

			/**
			 * @param string $type The node type.
			 *
			 * @return object The node.
			 */
			public function get(string $type): object {
				if (isset($this->nodes[$type]) === false) {
					throw new UnexpectedValueException('Unknown node type: ' . $type);
				}

				return $this->nodes[$type];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry, $registryResolves): object {
				if ($registryResolves === false) {
					throw new RuntimeException('registry unavailable');
				}

				return $registry;
			}
		);

		return new FlowTriggerValidator($container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A flow carrying the given nodes.
	 *
	 * @param array $nodes The node list.
	 *
	 * @return Flow The flow.
	 */
	private function flowWith(array $nodes): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes($nodes);
		$flow->setEdges([]);

		return $flow;
	}

	/**
	 * Register a trigger node double that accepts or refuses its config.
	 *
	 * @param string       $type    The node type id.
	 * @param string|null  $refusal The message to throw, or null to accept.
	 *
	 * @return void
	 */
	private function givenTriggerNode(string $type, ?string $refusal = null): void {
		$this->nodes[$type] = new class($refusal) implements IFlowNode, IFlowTriggerNode {
			/**
			 * @param string|null $refusal The refusal message, or null to accept.
			 */
			public function __construct(private readonly ?string $refusal) {
			}

			public function getId(): string {
				return 'double';
			}

			public function getDisplayName(): string {
				return 'Double';
			}

			public function getDescription(): string {
				return 'A trigger node double.';
			}

			public function getIcon(): string {
				return '/icon.svg';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
				if ($this->refusal !== null) {
					throw new InvalidArgumentException($this->refusal);
				}
			}

			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
	}

	/**
	 * Register a NON-trigger node double that would refuse if it were asked.
	 *
	 * @param string $type The node type id.
	 *
	 * @return void
	 */
	private function givenStepNode(string $type): void {
		$this->nodes[$type] = new class implements IFlowNode {
			public function getId(): string {
				return 'step';
			}

			public function getDisplayName(): string {
				return 'Step';
			}

			public function getDescription(): string {
				return 'A step node double.';
			}

			public function getIcon(): string {
				return '/icon.svg';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
				throw new InvalidArgumentException('a step was asked, which it should not be');
			}

			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
	}

	/**
	 * POSITIVE CONTROL: an accepted trigger passes through silently.
	 *
	 * Without this, every refusal assertion below is satisfied by a validator
	 * that rejects everything — which would look like a fix and be an outage.
	 *
	 * @return void
	 */
	public function testAnAcceptedTriggerIsNotRefused(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '*/5 * * * *']]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A trigger node's own refusal REACHES the caller.
	 *
	 * This is the whole point of the class. `validateConfig()` refusing was
	 * already true and always had been; nothing called it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testATriggersRefusalPropagates(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'A schedule trigger must carry a "runAs".');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/runAs/');

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []]])
		);
	}

	/**
	 * A NON-trigger node is never asked.
	 *
	 * Connectivity and step config stay the preflight's business: `flow-engine`
	 * requires that saving a half-wired flow succeeds and warns, and asking every
	 * node here would refuse flows mid-authoring.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAStepNodeIsNotAsked(): void {
		$this->givenStepNode('openregister.set-fields');

		$this->validator()->validate(
			$this->flowWith([['id' => 'step', 'type' => 'openregister.set-fields', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * An unknown node type is SKIPPED, not refused.
	 *
	 * A leaf app's trigger is not OpenRegister's to validate. Refusing would make
	 * this instance unable to store a flow authored against a fuller one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAnUnknownNodeTypeIsSkipped(): void {
		$this->validator()->validate(
			$this->flowWith([['id' => 'x', 'type' => 'someleafapp.exotic-trigger', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A registry that will not build does not refuse the save.
	 *
	 * A resolution failure is not a validation verdict. Refusing every save
	 * because the container was unhealthy turns an infrastructure fault into data
	 * loss for whoever was editing.
	 *
	 * @return void
	 */
	public function testAnUnavailableRegistryDoesNotRefuseTheSave(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'would refuse');

		$this->validator(registryResolves: false)->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * Malformed node entries are stepped over rather than fataling.
	 *
	 * `nodes` is stored JSON. A row written by an older build, or by hand, can
	 * hold a scalar or a typeless object, and a save path that fatals on one
	 * cannot be used to repair it.
	 *
	 * @return void
	 */
	public function testMalformedNodesAreSteppedOver(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->validator()->validate(
			$this->flowWith(
				[
					'not-an-array',
					['id' => 'no-type'],
					['id' => 'blank-type', 'type' => '   '],
					['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '*/5 * * * *']],
				]
			)
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A node whose `config` is not an array is normalised, not skipped.
	 *
	 * The mid-cutover shape on this instance is `config: []`, and a scalar is the
	 * same class of malformation. Both must still reach the node — a trigger that
	 * declares nothing is exactly the case the validator exists to catch, so
	 * skipping it would be the defect.
	 *
	 * @return void
	 */
	public function testANonArrayConfigStillReachesTheNode(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'no cron');

		$this->expectException(InvalidArgumentException::class);

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => 'nonsense']])
		);
	}

	/**
	 * A flow with no nodes at all validates cleanly.
	 *
	 * @return void
	 */
	public function testAnEmptyFlowIsAccepted(): void {
		$this->validator()->validate($this->flowWith([]));

		$this->addToAssertionCount(1);
	}
}
