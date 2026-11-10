<?php

/**
 * TriggerLookup: the enabled flows wired to one trigger, for one subject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Flow;

/**
 * A trigger and the subject it fired for. A null or empty register, schema or
 * organisation does not restrict.
 *
 * @spec openspec/specs/flow-storage/spec.md
 */
final class TriggerLookup {

	/**
	 * Constructor.
	 *
	 * @param string      $trigger      The catalog trigger id.
	 * @param string|null $register     The subject's register slug, when object-scoped.
	 * @param string|null $schema       The subject's schema slug, when object-scoped.
	 * @param string|null $organisation Restrict to one organisation uuid.
	 */
	public function __construct(
		public readonly string $trigger,
		public readonly ?string $register = null,
		public readonly ?string $schema = null,
		public readonly ?string $organisation = null,
	) {
	}//end __construct()

	/**
	 * The given subject restrictions, keyed by the flow column that holds them.
	 *
	 * @return array<string, string> Column to value.
	 *
	 * @spec openspec/specs/flow-storage/spec.md
	 */
	public function subjectColumns(): array {
		$values = ['trigger_register' => $this->register, 'trigger_schema' => $this->schema];

		return array_filter($values, static fn (?string $value): bool => $value !== null && $value !== '');
	}//end subjectColumns()
}//end class
