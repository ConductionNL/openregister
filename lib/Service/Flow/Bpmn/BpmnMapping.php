<?php

/**
 * The part of BPMN 2.0 this app reads and writes, named in one place.
 *
 * 🔴 THIS IS A SUBSET AND SAYS SO. BPMN 2.0 is an enormous standard —
 * choreographies, conversations, compensation, transactions, event
 * sub-processes — and this engine can express a fraction of it. Claiming "BPMN
 * support" and quietly dropping the rest is how an exported diagram comes back
 * from another tool meaning something else. So the subset is data, here, and
 * anything outside it is a refusal by construction rather than by omission.
 *
 * 🔑 ONE TABLE, BOTH DIRECTIONS. The exporter and the importer read the same
 * map. Two tables would agree until the day somebody added a node type to one
 * of them, and the disagreement would look like a file that "almost" works.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * The declared BPMN subset.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnMapping {

	/**
	 * The BPMN 2.0 model namespace.
	 *
	 * @var string
	 */
	public const NS_BPMN = 'http://www.omg.org/spec/BPMN/20100524/MODEL';

	/**
	 * The BPMN diagram interchange namespaces.
	 *
	 * @var string
	 */
	public const NS_BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';

	/**
	 * The DI diagram-common namespace.
	 *
	 * @var string
	 */
	public const NS_DC = 'http://www.omg.org/spec/DD/20100524/DC';

	/**
	 * Our own extension namespace.
	 *
	 * BPMN cannot natively carry a node's configuration. The standard's own
	 * answer is `extensionElements` in a foreign namespace, which a conformant
	 * tool must PRESERVE and may ignore — which is exactly the contract that
	 * makes our own round-trip exact and somebody else's lossy-but-honest.
	 *
	 * @var string
	 */
	public const NS_OPENREGISTER = 'https://openregister.app/schema/bpmn';

	/**
	 * The two extension elements, and the only two.
	 *
	 * @var string
	 */
	public const EXT_TYPE = 'type';

	/**
	 * The extension element carrying a node's config as JSON.
	 *
	 * @var string
	 */
	public const EXT_CONFIG = 'config';

	/**
	 * Flow node type => the BPMN element it exports as.
	 *
	 * A type absent from this map exports as `serviceTask`, which is the
	 * honest default: it is a step the process takes that BPMN has no opinion
	 * about, and the extension elements carry what it really is.
	 *
	 * @var array<string, string>
	 */
	public const EXPORT = [
		'openregister.trigger-manual' => 'startEvent',
		'openregister.trigger-schedule' => 'timerStartEvent',
		'openregister.trigger-object' => 'conditionalStartEvent',
		'openregister.switch' => 'exclusiveGateway',
		'openregister.route' => 'exclusiveGateway',
		'openregister.await-signal' => 'intermediateCatchEventMessage',
		'openregister.wait' => 'intermediateCatchEventTimer',
		'openregister.sub-flow' => 'callActivity',
		'openregister.end' => 'endEvent',
	];

	/**
	 * What a step with no entry above becomes.
	 *
	 * @var string
	 */
	public const DEFAULT_ELEMENT = 'serviceTask';

	/**
	 * Parts of the standard this app does NOT read, and will not pretend to.
	 *
	 * Written down rather than left implicit: a reader deciding whether to
	 * export a diagram here needs to know what will be lost BEFORE they try,
	 * and an importer's refusal list is only honest if the same list exists on
	 * the way out.
	 *
	 * @var array<int, string>
	 */
	public const NOT_SUPPORTED = [
		'choreographies and conversations',
		'compensation and transactions',
		'event sub-processes',
		'boundary events',
		'lanes and pools beyond the first participant',
		'more than one process per file',
		'data objects, data stores and item definitions',
		'multi-instance and loop markers',
	];

	/**
	 * The BPMN element a flow node exports as.
	 *
	 * @param string $nodeType The flow node type.
	 *
	 * @return string The element key.
	 */
	public static function elementFor(string $nodeType): string {
		return (self::EXPORT[$nodeType] ?? self::DEFAULT_ELEMENT);
	}//end elementFor()

	/**
	 * Whether a node type maps to a named BPMN element rather than the default.
	 *
	 * @param string $nodeType The flow node type.
	 *
	 * @return bool True when the standard has a word for it.
	 */
	public static function isNamed(string $nodeType): bool {
		return array_key_exists($nodeType, self::EXPORT);
	}//end isNamed()
}//end class
