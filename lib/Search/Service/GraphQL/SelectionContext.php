<?php

/**
 * Where a selection set sits while a query's complexity is analysed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\GraphQL;

/**
 * The nesting depth of a selection set and the query variables it can read.
 */
final class SelectionContext {
	/**
	 * Constructor.
	 *
	 * @param int                       $depth     Nesting depth of the selection set.
	 * @param array<string, mixed>|null $variables Query variables.
	 */
	public function __construct(
		public readonly int $depth,
		public readonly ?array $variables,
	) {
	}//end __construct()

	/**
	 * The context one level deeper.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function deeper(): self {
		return new self(depth: ($this->depth + 1), variables: $this->variables);
	}//end deeper()
}//end class
