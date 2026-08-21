<?php

/**
 * The `reference` field type, and what a node's FORM may and may not do to its
 * preflight.
 *
 * TWO DECLARATIONS, ONE NODE. `configKeys()` says which keys a node reads and
 * claims to be COMPLETE — the preflight refuses anything outside it.
 * `configForm()` says how some of them are edited and is explicitly allowed to
 * be PARTIAL. Everything here is about keeping the second from being read as
 * the first, in either direction:
 *
 *   - a partial form must not narrow the vocabulary, or describing two fields
 *     out of six would start rejecting the other four;
 *   - a form field the vocabulary forgot must not be refused, or the dialog
 *     writes a key the save then rejects and the operator sees two halves of
 *     the same node disagreeing with no way to tell which is wrong;
 *   - a form alone still cannot gate, because a subset is not a whitelist.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Preflight behaviour around {@see IFlowNodeConfigForm}.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
class FlowNodeConfigFormReferenceTest extends TestCase {

	/**
	 * A preflight over exactly the nodes handed in.
	 *
	 * @param array<int, IFlowNode> $nodes The nodes to register.
	 *
	 * @return FlowNodePreflight The subject.
	 */
	private function preflightOver(array $nodes): FlowNodePreflight {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($nodes): void {
				if (($event instanceof RegisterFlowNodesEvent) === false) {
					return;
				}

				foreach ($nodes as $node) {
					$event->registerNode($node);
				}
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $app): bool => ($app === 'openregister')
		);

		return new FlowNodePreflight(
			new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class)),
			$appManager,
			$this->createMock(LoggerInterface::class)
		);

	}//end preflightOver()

	/**
	 * The smallest complete document carrying one node's config.
	 *
	 * @param array $config The config under test.
	 *
	 * @return array The flow document.
	 */
	private function flowWithConfig(array $config): array {
		return [
			'name' => 'test-flow',
			'nodes' => [
				[
					'id' => 'a',
					'type' => 'test.formy',
					'exit' => true,
					'config' => $config,
				],
			],
			'edges' => [],
		];

	}//end flowWithConfig()

	/**
	 * A PARTIAL form does not narrow the vocabulary.
	 *
	 * The node reads four keys and describes one. Every one of the four must
	 * still pass: the form is a guide to editing, not a whitelist.
	 *
	 * @return void
	 */
	public function testAPartialFormDoesNotNarrowTheVocabulary(): void {
		$node = new class implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {
			use FormNodeStub;

			/**
			 * The four keys this node reads.
			 *
			 * @return array<int, string>
			 */
			public function configKeys(): array {
				return ['register', 'schema', 'mappingId', 'onMissing'];
			}

			/**
			 * One of them described.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function configForm(): array {
				return [['key' => 'mappingId', 'label' => 'Mapping', 'type' => 'text']];
			}
		};

		$report = $this->preflightOver([$node])->inspect(
			flow: $this->flowWithConfig(
				[
					'register' => 'r',
					'schema' => 's',
					'mappingId' => 'm',
					'onMissing' => 'skip',
				]
			)
		);

		$this->assertSame(
			[],
			$report['blocking'],
			'A form describing one of four keys must not turn the other three into unknown keys.'
		);

	}//end testAPartialFormDoesNotNarrowTheVocabulary()

	/**
	 * A form field the vocabulary FORGOT is accepted, not refused.
	 *
	 * This is the disagreement case. The node's own dialog offers `mappingId`
	 * and its `configKeys()` does not list it. Refusing the save would mean
	 * the editor writes a value the engine then rejects, over one node, with
	 * nothing on screen saying which half is wrong. The union resolves it in
	 * the direction that keeps the operator working.
	 *
	 * @return void
	 */
	public function testAFormFieldMissingFromTheVocabularyIsStillAccepted(): void {
		$node = new class implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {
			use FormNodeStub;

			/**
			 * The vocabulary, missing the key its own form offers.
			 *
			 * @return array<int, string>
			 */
			public function configKeys(): array {
				return ['register'];
			}

			/**
			 * A reference field over a key configKeys() forgot.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function configForm(): array {
				return [
					[
						'key' => 'mappingId',
						'label' => 'Mapping',
						'type' => 'reference',
						'reference' => ['register' => 'openconnector', 'schema' => 'mapping'],
					],
				];
			}
		};

		$report = $this->preflightOver([$node])->inspect(
			flow: $this->flowWithConfig(['register' => 'r', 'mappingId' => 'm'])
		);

		$this->assertSame(
			[],
			$report['blocking'],
			'The node offers this key in its own dialog; the save must not then refuse it.'
		);

	}//end testAFormFieldMissingFromTheVocabularyIsStillAccepted()

	/**
	 * The union WIDENS — it does not disable the check.
	 *
	 * The negative control for the two tests above. If the union were
	 * implemented as "any key passes once a form exists", every assertion here
	 * would still be green while the dialect check did nothing at all.
	 *
	 * @return void
	 */
	public function testAKeyInNeitherDeclarationIsStillRefused(): void {
		$node = new class implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {
			use FormNodeStub;

			/**
			 * The vocabulary.
			 *
			 * @return array<int, string>
			 */
			public function configKeys(): array {
				return ['register'];
			}

			/**
			 * The form.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function configForm(): array {
				return [['key' => 'mappingId', 'label' => 'Mapping', 'type' => 'text']];
			}
		};

		$report = $this->preflightOver([$node])->inspect(
			flow: $this->flowWithConfig(['register' => 'r', 'notAKeyAnywhere' => 'x'])
		);

		$this->assertCount(1, $report['blocking'], 'One step, one finding.');
		$this->assertSame(
			FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY,
			$report['blocking'][0]['reason']
		);
		$this->assertStringContainsString('notAKeyAnywhere', $report['blocking'][0]['detail']);

	}//end testAKeyInNeitherDeclarationIsStillRefused()

	/**
	 * A form WITHOUT a vocabulary still gates nothing.
	 *
	 * A node describing only a form has said how some keys are edited and
	 * nothing about which keys it reads. Treating that subset as complete
	 * would refuse keys the node handles, so it stays unchecked — the same
	 * answer every node predating the contract gets.
	 *
	 * @return void
	 */
	public function testAFormWithoutAVocabularyGatesNothing(): void {
		$node = new class implements IFlowNode, IFlowNodeConfigForm {
			use FormNodeStub;

			/**
			 * A form, and deliberately no configKeys().
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function configForm(): array {
				return [['key' => 'mappingId', 'label' => 'Mapping', 'type' => 'text']];
			}
		};

		$report = $this->preflightOver([$node])->inspect(
			flow: $this->flowWithConfig(['anything' => 'at all'])
		);

		$this->assertSame(
			[],
			$report['blocking'],
			'A partial form is not a whitelist, so it cannot be used as one.'
		);

	}//end testAFormWithoutAVocabularyGatesNothing()

	/**
	 * A form that THROWS does not make the instance unsavable.
	 *
	 * Same rule the vocabulary lookup already follows. One node with broken
	 * metadata must cost its own guidance, not everybody's save button.
	 *
	 * @return void
	 */
	public function testAThrowingFormFallsBackToTheVocabularyAlone(): void {
		$node = new class implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {
			use FormNodeStub;

			/**
			 * The vocabulary still answers.
			 *
			 * @return array<int, string>
			 */
			public function configKeys(): array {
				return ['register'];
			}

			/**
			 * Broken metadata.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function configForm(): array {
				throw new \RuntimeException('a missing translation');
			}
		};

		$preflight = $this->preflightOver([$node]);

		$this->assertSame(
			[],
			$preflight->inspect(flow: $this->flowWithConfig(['register' => 'r']))['blocking'],
			'The vocabulary still answers, so a valid config still saves.'
		);
		$this->assertCount(
			1,
			$preflight->inspect(flow: $this->flowWithConfig(['nope' => 'x']))['blocking'],
			'And the check is still running rather than silently disabled.'
		);

	}//end testAThrowingFormFallsBackToTheVocabularyAlone()
}//end class

/**
 * The parts of IFlowNode none of these tests exercise.
 *
 * Only `getId()`, `configKeys()`, `configForm()` and `validateConfig()` are
 * reached by the preflight; the rest is here so the anonymous classes above can
 * state the one thing each is about and nothing else.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
trait FormNodeStub {

	/**
	 * The node id every fixture document refers to.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'test.formy';

	}//end getId()

	/**
	 * The palette label.
	 *
	 * @return string The label.
	 */
	public function getDisplayName(): string {
		return 'Formy';

	}//end getDisplayName()

	/**
	 * Available everywhere, so no fixture is filtered out of the registry.
	 *
	 * @param int $scope The scope being asked about.
	 *
	 * @return bool Always true.
	 */
	public function isAvailableForScope(int $scope): bool {
		return true;

	}//end isAvailableForScope()

	/**
	 * The palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return 'A node that exists to be preflighted.';

	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'cog';

	}//end getIcon()

	/**
	 * Config validation, which these tests deliberately leave silent so the
	 * only finding possible is the dialect one.
	 *
	 * @param array $config The step's config.
	 *
	 * @return void
	 */
	public function validateConfig(array $config): void {

	}//end validateConfig()

	/**
	 * Never reached: nothing here runs a flow.
	 *
	 * @param array $items   The items entering the node.
	 * @param array $config  The step's config.
	 * @param array $context The execution context.
	 *
	 * @return array The result.
	 */
	public function execute(array $items, array $config, array $context): array {
		return $items;

	}//end execute()
}//end trait
