<?php

/**
 * Builders for the field descriptions a flow node's config form returns.
 *
 * Every node's configForm() returns a list of `{key, label, type, help}`
 * fields, some marked `required`. Written out as array literals, that shape
 * repeats six lines per field across every node; these two builders state
 * it once, in the key order the flow editor has always received.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * Field builders for IFlowNodeConfigForm::configForm().
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
trait FormFieldsTrait {
	/**
	 * One optional form field.
	 *
	 * @param NodeFormField $field The config key, the translated label and help, and the input type.
	 *
	 * @return array{key: string, label: string, type: string, help: string} The field.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function field(NodeFormField $field): array {
		return ['key' => $field->key, 'label' => $field->label, 'type' => $field->type, 'help' => $field->help];
	}//end field()

	/**
	 * One required form field.
	 *
	 * @param NodeFormField $field The config key, the translated label and help, and the input type.
	 *
	 * @return array{key: string, label: string, type: string, help: string, required: true} The field.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function requiredField(NodeFormField $field): array {
		return $this->field(field: $field) + ['required' => true];
	}//end requiredField()
}//end trait
