<?php

/**
 * What a party submits when completing a portal task.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

/**
 * One completion as the party submitted it: the answer fields, the comment,
 * the uploads and the outcome to record.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
 */
final class PortalCompletion {

	/**
	 * Name the completion.
	 *
	 * @param array<string, mixed>             $answers The submitted answer fields.
	 * @param string|null                      $comment The party's comment, when any.
	 * @param array<int, array<string, mixed>> $files   The uploads, each {name, type, size, tmp_name|content}.
	 * @param string                           $outcome The outcome to record; defaults to `submitted`.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function __construct(
		public readonly array $answers=[],
		public readonly ?string $comment=null,
		public readonly array $files=[],
		public readonly string $outcome=PortalTaskServiceInterface::DEFAULT_OUTCOME,
	) {
	}//end __construct()

	/**
	 * The outcome to record: the one given, trimmed, or the default when blank.
	 *
	 * @return string The outcome.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-an-upload-completion-lands-as-a-file-attachment-on-the-case-object
	 */
	public function outcome(): string {
		$outcome = trim($this->outcome);
		if ($outcome === '') {
			return PortalTaskServiceInterface::DEFAULT_OUTCOME;
		}

		return $outcome;
	}//end outcome()
}//end class
