<?php

/**
 * One field of a flow node's config form.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The config key a form field writes, its translated label and help, and its input type.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
final class NodeFormField {
	/**
	 * Constructor.
	 *
	 * @param string $key   The config key the field writes.
	 * @param string $label The translated label.
	 * @param string $type  The input type, such as `text`, `textarea`, `number` or `boolean`.
	 * @param string $help  The translated help text.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type,
		public readonly string $help,
	) {
	}//end __construct()
}//end class
