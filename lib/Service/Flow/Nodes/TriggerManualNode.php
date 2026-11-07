<?php

/**
 * An entry point: this flow is started by a person, on demand.
 *
 * The on-demand half of {@see TriggerObjectNode} — see that class for why a
 * trigger is a node at all, and why a flow may carry several.
 *
 * It carries no configuration. That is the whole point of it: a flow with no
 * trigger node at all and a flow with a manual trigger are DIFFERENT claims,
 * and only one of them is legible. The first says nothing about intent — it
 * reads as unfinished, and an author cannot tell whether the trigger is missing
 * or deliberate. The second says "a person starts this", on the canvas, where
 * the rest of the flow is.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use UnexpectedValueException;

/**
 * Starts the flow when someone runs it.
 */
class TriggerManualNode extends TriggerNodeBase implements IFlowNode, TriggerManualNodeInterface {
	/**
	 * The node type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getId(): string {
		return 'openregister.trigger-manual';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('When someone runs it');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDescription(): string {
		return $this->l10n->t('Start the flow on demand. Says "a person starts this" out loud, which a flow with no trigger at all cannot.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/play.svg');
	}//end getIcon()

	/**
	 * A manual trigger takes one key: where the person goes afterwards.
	 *
	 * Naming the vocabulary is not the same as saying nothing: the list lets
	 * the preflight report a key written here in another node's dialect, which
	 * would otherwise be stored, ignored, and reported as a healthy step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	public function configKeys(): array {
		return ['next'];
	}//end configKeys()

	/**
	 * Refuse a `next` outside the vocabulary.
	 *
	 * Nothing is REQUIRED — a manual trigger with no `next` means `stay`, which
	 * is what running a flow from a record did before this key existed. But a
	 * word outside the vocabulary is refused rather than defaulted: silently
	 * read as `stay`, a typed `nextItem` would author, save and behave like a
	 * setting nobody made, and the author would have no way to see it.
	 *
	 * @param array $config The node configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When `next` is not one of the three words.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
	 */
	public function validateConfig(array $config): void {
		if (array_key_exists('next', $config) === false || $config['next'] === null || $config['next'] === '') {
			return;
		}

		if (FlowNextHint::read(raw: $config['next']) === null) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'"next" must be one of "stay", "next" or "list".'
				)
			);
		}
	}//end validateConfig()
}//end class
