<?php

/**
 * The content of a new standalone task linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The content of a new standalone task linked to an object: the object's title
 * for the LINK label, and the task data (summary, description, priority, due,
 * status, fields).
 *
 * ADR-011 Rule 2 operation object for {@see TaskVtodoDocument::composeFor()}.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class LinkedTaskDraft {
	/**
	 * Build the draft.
	 *
	 * @param string $objectTitle The object title for the LINK label.
	 * @param array  $data        Task data: summary, description, priority, due, status, fields.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly string $objectTitle,
		public readonly array $data,
	) {
	}//end __construct()
}//end class
