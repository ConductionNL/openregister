<?php

/**
 * A node type that describes how its configuration should be edited.
 *
 * `IFlowNodeConfigKeys` already says WHICH keys a node reads. This says what
 * each one is: a label, a control, help text, and where its choices come from.
 * The editor renders the description; without one it falls back to a raw-JSON
 * pane, which is the honest fallback rather than a typed form over guessed
 * keys.
 *
 * WHY THIS IS AN INTERFACE AND NOT A TABLE IN THE EDITOR
 * -----------------------------------------------------
 * `RegisterFlowNodesEvent` exists so an app can contribute a node type without
 * OpenRegister knowing anything about it. A form registry that only OpenRegister
 * could extend would put the form of every contributed node straight back into
 * the engine — and every app's keys with it. The app that knows what
 * `synchronizationId` means is the app that ships the node.
 *
 * WHY IT IS DATA AND NOT A COMPONENT
 * ----------------------------------
 * A node shipping a Vue component would tie the engine's rendering to that
 * app's build, and the canvas is rendered inside whichever app the author is
 * in — hermiq's editor would be asked to mount a component out of
 * openconnector's bundle. Fields are described; the editor decides how to draw
 * them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Declares an editable form for a node type's configuration.
 */
interface IFlowNodeConfigForm {
	/**
	 * The fields this node's configuration is edited through.
	 *
	 * Each entry describes ONE key from `configKeys()`:
	 *
	 *   key         string  The config key it writes. MUST be one this node
	 *                       actually reads — a field over a key the node
	 *                       ignores looks like it works and changes nothing.
	 *   label       string  Translated, because it is shown to an operator.
	 *   type        string  `text`, `textarea`, `number`, `boolean`, `select`.
	 *   help        string  Optional. What the value means, not what it is.
	 *   required    bool    Optional. Whether the node needs it to run.
	 *   optionsFrom string  Optional. A REFERENCE the providing app resolves —
	 *                       e.g. its own sources or synchronizations. A URL,
	 *                       never inline options: a catalogue is fetched once
	 *                       and changes without the node being redeployed, and
	 *                       baking today's list into the node's declaration
	 *                       would freeze it there.
	 *
	 * A key with no field is still editable through the raw-JSON pane. That is
	 * deliberate: a partial form is more useful than none, and it lets a node
	 * describe the two fields worth guiding and leave the rest.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	public function configForm(): array;
}//end interface
