<?php

/**
 * The object trigger's transition and changed-field filters: each refusal, each accepted shape.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TriggerNodeBase
 * @uses \OCA\OpenRegister\Service\Rules\RuleScope
 */
final class TriggerObjectNodeTest extends TestCase {

	private TriggerObjectNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new TriggerObjectNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * A trigger config on the given event with extra keys.
	 *
	 * @param string               $event The event.
	 * @param array<string, mixed> $extra Extra keys.
	 *
	 * @return array<string, mixed>
	 */
	private function config(string $event, array $extra): array {
		return ['event' => $event, 'register' => 'zaken', 'schema' => 'aanvraag'] + $extra;
	}//end config()

	/**
	 * Assert the config is refused with a message containing every fragment.
	 *
	 * @param array<string, mixed> $config    The config.
	 * @param array<int, string>   $fragments Expected message fragments.
	 *
	 * @return void
	 */
	private function assertRefused(array $config, array $fragments): void {
		try {
			$this->node->validateConfig($config);
		} catch (InvalidArgumentException $e) {
			foreach ($fragments as $fragment) {
				$this->assertStringContainsString($fragment, $e->getMessage());
			}

			return;
		}

		$this->fail('Expected the config to be refused: ' . json_encode($config));
	}//end assertRefused()

	/**
	 * A transition trigger is accepted with no filter, an empty filter, or any subset of lists.
	 *
	 * @return void
	 */
	public function testTransitionShapesAreAccepted(): void {
		$this->node->validateConfig($this->config('object.transitioned', []));
		$this->node->validateConfig($this->config('object.transitioned', ['transition' => []]));
		$this->node->validateConfig($this->config('object.transitioned', ['transition' => ['to' => ['closed']]]));
		$this->node->validateConfig(
			$this->config('object.transitioned', ['transition' => ['actions' => ['close'], 'from' => ['open'], 'to' => ['closed']]])
		);
		$this->assertContains('object.transitioned', TriggerObjectNode::EVENTS);
	}//end testTransitionShapesAreAccepted()

	/**
	 * A transition filter on another event names the key and the event it belongs to.
	 *
	 * @return void
	 */
	public function testTransitionOnTheWrongEventIsRefused(): void {
		$this->assertRefused($this->config('object.created', ['transition' => ['to' => ['closed']]]), ['"transition"', 'object.transitioned', 'object.created']);
	}//end testTransitionOnTheWrongEventIsRefused()

	/**
	 * A malformed transition filter is refused, naming what is wrong.
	 *
	 * @return void
	 */
	public function testMalformedTransitionFiltersAreRefused(): void {
		$this->assertRefused($this->config('object.transitioned', ['transition' => 'closed']), ['object of lists']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['closed']]), ['object of lists']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['state' => ['x']]]), ['no "state" list']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['to' => []]]), ['"transition.to"', 'non-empty list']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['to' => 'closed']]), ['"transition.to"', 'non-empty list']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['from' => ['open', 3]]]), ['"transition.from"', 'non-blank names']);
		$this->assertRefused($this->config('object.transitioned', ['transition' => ['actions' => ['  ']]]), ['"transition.actions"', 'non-blank names']);
	}//end testMalformedTransitionFiltersAreRefused()

	/**
	 * A changedFields list is accepted on an update trigger.
	 *
	 * @return void
	 */
	public function testChangedFieldsOnAnUpdateIsAccepted(): void {
		$this->node->validateConfig($this->config('object.updated', ['changedFields' => ['omschrijving', 'status']]));
		$this->assertContains('changedFields', $this->node->configKeys());
		$this->assertContains('transition', $this->node->configKeys());
	}//end testChangedFieldsOnAnUpdateIsAccepted()

	/**
	 * changedFields on another event, empty, not a list, or with a non-name entry is refused.
	 *
	 * @return void
	 */
	public function testChangedFieldsRefusals(): void {
		$this->assertRefused($this->config('object.transitioned', ['changedFields' => ['a']]), ['"changedFields"', 'object.updated', 'object.transitioned']);
		$this->assertRefused($this->config('object.updated', ['changedFields' => []]), ['"changedFields"', 'non-empty list']);
		$this->assertRefused($this->config('object.updated', ['changedFields' => 'omschrijving']), ['"changedFields"', 'non-empty list']);
		$this->assertRefused($this->config('object.updated', ['changedFields' => ['a' => 'omschrijving']]), ['"changedFields"', 'non-empty list']);
		$this->assertRefused($this->config('object.updated', ['changedFields' => ['omschrijving', null]]), ['"changedFields"', 'non-blank names']);
	}//end testChangedFieldsRefusals()
}//end class
