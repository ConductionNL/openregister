<?php

/**
 * The BPMN mapping, declared once and read by both directions.
 *
 * 🔑 ONE TABLE, TWO READERS. The tasks ask for the extension namespace to be
 * declared "in one place both exporter and importer read", and the same
 * argument applies to the mapping itself: an exporter and an importer holding
 * separate tables drift, and the drift shows up as a file that does not
 * round-trip through its own product — the one property the acceptance
 * criteria name first.
 *
 * 🔴 AND A NODE TYPE WITH NO ENTRY IS NOT A `serviceTask` BY DEFAULT. It is
 * `serviceTask` because {@see self::FALLBACK} says so, which is a decision
 * somebody made and can change, rather than a hole that happens to behave. The
 * difference matters the moment a node type is added: with a declared
 * fallback it exports as a task carrying its own `type` in an extension
 * element, and round-trips; with an accidental one, nobody notices until a
 * file comes back missing a step.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * The closed mapping between flow node types and BPMN elements.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnVocabulary {

	/**
	 * The namespace our extension elements live in.
	 *
	 * @var string
	 */
	public const EXTENSION_NS = 'https://openregister.app/schema/bpmn/1.0';

	/**
	 * The namespace prefix used in emitted documents.
	 *
	 * @var string
	 */
	public const EXTENSION_PREFIX = 'openregister';

	/**
	 * The extension element carrying a node's engine type.
	 *
	 * @var string
	 */
	public const ELEMENT_TYPE = 'type';

	/**
	 * The extension element carrying a node's configuration.
	 *
	 * @var string
	 */
	public const ELEMENT_CONFIG = 'config';

	/**
	 * What any node type with no declared mapping exports as.
	 *
	 * @var string
	 */
	public const FALLBACK = 'serviceTask';

	/**
	 * Flow node type to the BPMN element it exports as.
	 *
	 * Only the rows the design's table names. Everything else is
	 * {@see self::FALLBACK}, which round-trips because the node's own `type`
	 * travels in an extension element.
	 *
	 * @var array<string, string>
	 */
	public const EXPORT = [
		'openregister.trigger-manual' => 'startEvent',
		'openregister.trigger-schedule' => 'timerStartEvent',
		'openregister.trigger-object' => 'conditionalStartEvent',
		'openregister.switch' => 'exclusiveGateway',
		'openregister.route' => 'exclusiveGateway',
		'openregister.await-signal' => 'intermediateCatchEvent:message',
		'openregister.wait' => 'intermediateCatchEvent:timer',
		'openregister.sub-flow' => 'callActivity',
		'openregister.end' => 'endEvent',
	];

	/**
	 * BPMN element to the node type it imports as, read in reverse.
	 *
	 * 🔴 NOT COMPUTED BY FLIPPING {@see self::EXPORT}. Two rows export to the
	 * same element — `switch` and `route` are both an exclusive gateway — so a
	 * flip would silently pick whichever came last and turn every imported
	 * `route` into a `switch`, or the other way round, depending on array
	 * order. The reverse direction is its own declaration, and the tolerated
	 * widenings below only exist here.
	 *
	 * @var array<string, string>
	 */
	public const IMPORT = [
		'startEvent' => 'openregister.trigger-manual',
		'timerStartEvent' => 'openregister.trigger-schedule',
		'conditionalStartEvent' => 'openregister.trigger-object',
		'exclusiveGateway' => 'openregister.switch',
		'intermediateCatchEvent:message' => 'openregister.await-signal',
		'intermediateCatchEvent:timer' => 'openregister.wait',
		'callActivity' => 'openregister.sub-flow',
		'endEvent' => 'openregister.end',
	];

	/**
	 * Constructs imported as something close but not identical, with what is lost.
	 *
	 * Each entry is the sentence the report carries, so the reading is
	 * declared rather than decided in the importer's control flow.
	 *
	 * @var array<string, array{type: string, lost: string}>
	 */
	public const APPROXIMATED = [
		'userTask' => [
			'type' => 'openregister.await-signal',
			'lost' => 'a user task becomes a signal the flow waits for; the form and the assignee are not imported.',
		],
		'inclusiveGateway' => [
			'type' => 'openregister.route',
			'lost' => 'an inclusive gateway becomes a route, which takes ONE branch; a file expecting several to run needs splitting by hand.',
		],
		'terminateEndEvent' => [
			'type' => 'openregister.end',
			'lost' => 'a terminate end ends this path only; it does not cancel work already running elsewhere in the flow.',
		],
		'boundaryEvent' => [
			'type' => '',
			'lost' => 'the engine has no boundary events; this one is recorded as a note on the node it was attached to and does nothing.',
		],
	];

	/**
	 * Constructs with no honest mapping, and why each is refused.
	 *
	 * 🔑 THE REASON IS PART OF THE VOCABULARY, not a string the importer
	 * invents at the point of refusal. A refusal an author cannot act on is a
	 * refusal they will read as a bug in the importer.
	 *
	 * @var array<string, string>
	 */
	public const REFUSED = [
		'subProcess:event' => 'an event sub-process has no equivalent: the engine has no way to start work from inside a running flow.',
		'compensation' => 'compensation has no equivalent: the engine does not undo completed steps.',
		'transaction' => 'a transaction boundary has no equivalent: the engine commits each step as it completes.',
		'collaboration' => 'collaboration and choreography describe several participants; a flow is one process.',
		'process:multiple' => 'the file declares more than one process; import one process per file so it is clear which became the flow.',
	];

	/**
	 * Whether the vocabulary knows how to export a node type directly.
	 *
	 * @param string $nodeType The node type.
	 *
	 * @return bool True when a row names it.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function exportsDirectly(string $nodeType): bool {
		return array_key_exists($nodeType, self::EXPORT);
	}//end exportsDirectly()

	/**
	 * The BPMN element a node type exports as.
	 *
	 * @param string $nodeType The node type.
	 *
	 * @return string The element.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function elementFor(string $nodeType): string {
		return (self::EXPORT[$nodeType] ?? self::FALLBACK);
	}//end elementFor()

	/**
	 * The verdict and node type for one BPMN element.
	 *
	 * @param string $element The BPMN element kind.
	 *
	 * @return array{verdict: string, type: string, note: string} The reading.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function readingFor(string $element): array {
		if (array_key_exists($element, self::REFUSED) === true) {
			return [
				'verdict' => BpmnMappingReport::REFUSED,
				'type' => '',
				'note' => self::REFUSED[$element],
			];
		}

		if (array_key_exists($element, self::IMPORT) === true) {
			return ['verdict' => BpmnMappingReport::MAPPED, 'type' => self::IMPORT[$element], 'note' => ''];
		}

		if (array_key_exists($element, self::APPROXIMATED) === true) {
			return [
				'verdict' => BpmnMappingReport::APPROXIMATED,
				'type' => self::APPROXIMATED[$element]['type'],
				'note' => self::APPROXIMATED[$element]['lost'],
			];
		}

		if ($element === self::FALLBACK) {
			// 🔴 A `serviceTask` WITHOUT our extension elements imports TYPELESS
			// and is listed as needing one. The importer must never guess a type
			// from the task's NAME: a flow that runs something because a box was
			// labelled "send email" is a flow nobody authorised.
			return [
				'verdict' => BpmnMappingReport::APPROXIMATED,
				'type' => '',
				'note' => 'this task carries no openregister type, so it is imported without one and the flow will refuse to run until you assign it.',
			];
		}

		return [
			'verdict' => BpmnMappingReport::REFUSED,
			'type' => '',
			'note' => sprintf('"%s" is not a construct this importer reads; it was dropped.', $element),
		];
	}//end readingFor()
}//end class
