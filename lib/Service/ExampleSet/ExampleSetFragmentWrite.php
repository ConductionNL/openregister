<?php

/**
 * OpenRegister Example Set Fragment Write
 *
 * One fragment as its engine rows are written: its declarations, the context its placeholders draw on, and the
 * filler of those placeholders.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * A fragment, its placeholder context and the filler, as the engine rows of the fragment are written.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetFragmentWrite {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $fragment,
		public readonly array $context,
		public readonly ExampleSetValues $values,
	) {
	}//end __construct()

	/**
	 * The same write with the tasks written so far in its context, so a later part may quote one.
	 *
	 * @param array<string, mixed> $record The record, holding the tasks written.
	 *
	 * @return self
	 */
	public function withTasksOf(array $record): self {
		$context          = $this->context;
		$context['tasks'] = $this->values->tasksOf(fragment: $this->fragment, record: $record);
		return new self(fragment: $this->fragment, context: $context, values: $this->values);
	}//end withTasksOf()
}//end class
