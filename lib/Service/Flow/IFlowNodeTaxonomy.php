<?php

/**
 * A node that can say what kind of step it is, and where it belongs.
 *
 * TWO INDEPENDENT FACTS, NOT ONE
 * ------------------------------
 * The KIND is semantic and is what an interchange needs: a BPMN export has to
 * emit `userTask`, `gateway`, `sendTask`. The CATEGORY is a grouping and is
 * what a palette of 64 entries needs so an author can find anything at all.
 *
 * They are deliberately not derived from one another. `openregister.await-signal`
 * is a `receiveTask` — it waits for a system to call back — and by kind it sits
 * with the integration steps. But it is the machine half of a pair whose other
 * half is "Ask a person", and an author deciding between the two looks in ONE
 * place. Splitting that pair across two palette groups would undo the only
 * thing that helps them choose. So it is `receiveTask` and `human`.
 *
 * THE VOCABULARY IS BPMN'S, NOT OURS
 * ----------------------------------
 * The kinds are exactly BPMN's element types and the list is closed. Its whole
 * value is that it is the vocabulary an interchange already has to speak; a
 * local synonym would have to be mapped back on export, and that mapping is the
 * part that rots. The palette categories ARE ours, because the question "where
 * would an author look for this" is ours.
 *
 * WHY NOT ON `IFlowNode` ITSELF
 * -----------------------------
 * The same reason as {@see IFlowNodeConfigKeys}, and it is load-bearing here.
 * Adding a method to `IFlowNode` is a fatal error for every class implementing
 * it that has not been updated, and on a measured instance 43 of 64 step types
 * are contributed by apps in other repositories on their own release cycles.
 *
 * Three options existed. Require the methods, and every contributed node fatals
 * on the next release. Guess on their owners' behalf from the id or the class
 * name, which is cheap and produces a WRONG BPMN element type that nobody ever
 * revisits, because it looks answered. Or default them visibly and let each
 * owner declare.
 *
 * Only the third leaves "undeclared" distinguishable from "declared". An
 * `other` in the palette is a prompt that gets fixed; a guessed `serviceTask`
 * written into a BPMN export is a lie with a long half-life.
 *
 * The defaults therefore live in {@see FlowNodeRegistry}, which applies them
 * when serving the catalogue, and NOT in this interface, which a node either
 * implements in full or does not implement at all.
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
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Optional companion to {@see IFlowNode}: what kind of step, and where to find it.
 */
interface IFlowNodeTaxonomy {

	/**
	 * A step a person does.
	 *
	 * @var string
	 */
	public const KIND_USER_TASK = 'userTask';

	/**
	 * A step that calls something.
	 *
	 * @var string
	 */
	public const KIND_SERVICE_TASK = 'serviceTask';

	/**
	 * A step that transforms data in place.
	 *
	 * @var string
	 */
	public const KIND_SCRIPT_TASK = 'scriptTask';

	/**
	 * A step that evaluates a decision.
	 *
	 * @var string
	 */
	public const KIND_BUSINESS_RULE_TASK = 'businessRuleTask';

	/**
	 * A step that sends a message out.
	 *
	 * @var string
	 */
	public const KIND_SEND_TASK = 'sendTask';

	/**
	 * A step that waits for a message in.
	 *
	 * @var string
	 */
	public const KIND_RECEIVE_TASK = 'receiveTask';

	/**
	 * A step done outside the system entirely.
	 *
	 * @var string
	 */
	public const KIND_MANUAL_TASK = 'manualTask';

	/**
	 * A branch or a join.
	 *
	 * @var string
	 */
	public const KIND_GATEWAY = 'gateway';

	/**
	 * Something that happens: a start, an end, a signal.
	 *
	 * @var string
	 */
	public const KIND_EVENT = 'event';

	/**
	 * A step that is itself a process.
	 *
	 * @var string
	 */
	public const KIND_SUB_PROCESS = 'subProcess';

	/**
	 * Every kind, for validation.
	 *
	 * 🔑 ENUMERATED SO A TYPO IS A FATAL, NOT A SILENT `other`. A node
	 * declaring `userTsak` would otherwise be served as it wrote it, and the
	 * BPMN export would carry a element type nothing recognises.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [
		self::KIND_USER_TASK,
		self::KIND_SERVICE_TASK,
		self::KIND_SCRIPT_TASK,
		self::KIND_BUSINESS_RULE_TASK,
		self::KIND_SEND_TASK,
		self::KIND_RECEIVE_TASK,
		self::KIND_MANUAL_TASK,
		self::KIND_GATEWAY,
		self::KIND_EVENT,
		self::KIND_SUB_PROCESS,
	];

	/**
	 * Ways a flow can start.
	 *
	 * @var string
	 */
	public const CATEGORY_TRIGGERS = 'triggers';

	/**
	 * Steps involving a person.
	 *
	 * @var string
	 */
	public const CATEGORY_HUMAN = 'human';

	/**
	 * Steps that read or write registered objects.
	 *
	 * @var string
	 */
	public const CATEGORY_OBJECTS = 'objects';

	/**
	 * Branching, looping, shaping data.
	 *
	 * @var string
	 */
	public const CATEGORY_LOGIC = 'logic';

	/**
	 * Steps that send something to somebody.
	 *
	 * @var string
	 */
	public const CATEGORY_MESSAGING = 'messaging';

	/**
	 * Steps that ask a model.
	 *
	 * @var string
	 */
	public const CATEGORY_AI = 'ai';

	/**
	 * Steps that talk to another system.
	 *
	 * @var string
	 */
	public const CATEGORY_INTEGRATIONS = 'integrations';

	/**
	 * Undeclared, and visibly so.
	 *
	 * @var string
	 */
	public const CATEGORY_OTHER = 'other';

	/**
	 * Every category, in the order a palette presents them.
	 *
	 * 🔴 THE ORDER LIVES HERE, NOT IN THE REGISTRY. Registration order depends
	 * on which apps are installed and in what order their listeners fire, so a
	 * palette ordered by registration reorders itself when an unrelated app is
	 * enabled. `other` is last because it is the prompt, not a home.
	 *
	 * @var array<int, string>
	 */
	public const CATEGORIES = [
		self::CATEGORY_TRIGGERS,
		self::CATEGORY_HUMAN,
		self::CATEGORY_OBJECTS,
		self::CATEGORY_LOGIC,
		self::CATEGORY_MESSAGING,
		self::CATEGORY_AI,
		self::CATEGORY_INTEGRATIONS,
		self::CATEGORY_OTHER,
	];

	/**
	 * What kind of step this is, in BPMN's vocabulary.
	 *
	 * Describes what the step IS, never what it is ABOUT. A step that calls an
	 * external system is a `serviceTask` whether it calls a payment provider or
	 * a case register; the subject belongs in the category and the description.
	 *
	 * @return string One of {@see self::KINDS}.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string;

	/**
	 * Where in the palette an author should find this step.
	 *
	 * Independent of the kind: two `sendTask`s both belong under `messaging`,
	 * and a `userTask` and a `receiveTask` can both belong under `human`.
	 *
	 * @return string One of {@see self::CATEGORIES}.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string;
}//end interface
