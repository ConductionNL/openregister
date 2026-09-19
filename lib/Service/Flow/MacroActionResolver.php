<?php

/**
 * Which flow a schema binds to a declared action, and where it leaves you.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Resolves a macro action against the schema's own declarations.
 *
 * 🔴 READ FROM THE DECLARATIONS, NEVER FROM THE REQUEST. A caller naming an
 * action the schema does not bind gets null here and a refusal from the
 * endpoint, not a flow of their choosing. Keeping that lookup in one object
 * is what stops a second, laxer one appearing beside it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) `MacroActionBinding::parse()` and
 * `FlowNextHint::declared()` are the two named readers phpmd.xml already
 * excepts by name: both are stateless declaration readers with no
 * collaborators, and several call paths must reach the same answer.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
class MacroActionResolver {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemas Loads a schema by id or slug.
	 * @param FlowService  $flows   Reads the bound flow, for its `next` hint.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly FlowService $flows,
	) {
	}//end __construct()

	/**
	 * The macro binding a schema declares for this action.
	 *
	 * Read from the DECLARATIONS, never from what the request asked for: a
	 * caller naming an action the schema does not bind gets a refusal, not a
	 * flow of their choosing.
	 *
	 * @param Schema $schema The subject's schema.
	 * @param string $action The action.
	 *
	 * @return MacroActionBinding|null The binding.
	 */
	public function bindingFor(Schema $schema, string $action): ?MacroActionBinding {
		foreach (MacroActionBinding::parse(configuration: ($schema->getConfiguration() ?? [])) as $binding) {
			if ($binding->action === $action) {
				return $binding;
			}
		}

		return null;
	}//end bindingFor()

	/**
	 * The `next` hint the flow declares.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return string One of FlowNextHint::HINTS.
	 */
	public function nextFor(string $flowUuid): string {
		try {
			return FlowNextHint::declared(nodes: ($this->flows->find(uuid: $flowUuid)->getNodes() ?? []));
		} catch (\Throwable) {
			// A hint nobody can read is `stay`, which is what happened before
			// hints existed and is the only answer that cannot move somebody
			// somewhere they did not ask to go.
			return FlowNextHint::STAY;
		}
	}//end nextFor()

	/**
	 * Load a schema by id or slug.
	 *
	 * @param string $schema The schema identifier.
	 *
	 * @return Schema|null The schema.
	 */
	public function loadSchema(string $schema): ?Schema {
		try {
			return $this->schemas->find($schema, _multitenancy: false, _rbac: false);
		} catch (\Throwable) {
			return null;
		}
	}//end loadSchema()
}//end class
