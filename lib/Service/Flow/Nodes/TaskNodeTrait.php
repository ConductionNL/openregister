<?php

/**
 * The parts the user-task and portal-task nodes share.
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
 * The acting identity and the timeout form fields of a node that waits on a task.
 *
 * The host provides `$this->l10n` (an IL10N) for the shared field labels.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
trait TaskNodeTrait {
	use FormFieldsTrait;

	/**
	 * The run's acting identity: who the task is requested by.
	 *
	 * The run's owner (`runAs`) first, the person who triggered it second.
	 * Null is passed through and refused by the task service by name, which
	 * is the loud failure an unattributed run should produce.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return string|null The uid, or null when the run has none.
	 */
	private function actingIdentity(array $context): ?string {
		foreach (['runAs', 'triggeredBy'] as $key) {
			$uid = trim((string)($context[$key] ?? ''));
			if ($uid !== '') {
				return $uid;
			}
		}

		return null;
	}//end actingIdentity()

	/**
	 * What a deadline or a rejection does, and how often the run re-checks.
	 *
	 * @param string $onTimeoutHelp The translated help of the `onTimeout` field, naming what expires.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	private function timeoutFieldsWith(string $onTimeoutHelp): array {
		return [
			$this->field(field: new NodeFormField(key: 'onTimeout', label: $this->l10n->t('On timeout'), type: 'text', help: $onTimeoutHelp)),
			$this->field(field: new NodeFormField(key: 'onReject', label: $this->l10n->t('On rejection'), type: 'text', help: $this->l10n->t(
				'Only dead_letter changes the record; skip and error are read by whatever resumes the flow.'
			))),
			$this->field(field: new NodeFormField(
				key: 'heartbeatMinutes', label: $this->l10n->t('Re-check every (minutes)'), type: 'number', help: $this->l10n->t(
				'Safety net for a wake that never arrives. Lower is not faster: a completed task wakes the run immediately either way.'
			))),
		];
	}//end timeoutFieldsWith()
}//end trait
