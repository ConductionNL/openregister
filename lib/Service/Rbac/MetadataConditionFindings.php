<?php

/**
 * Save-time findings for rules conditioned on metadata no record carries.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCP\IDBConnection;

/**
 * Names the rules of a stored or saved block that {@see RecordMetadataConditions} refuses.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class MetadataConditionFindings implements MetadataConditionFindingsInterface {

	/**
	 * Reads which metadata keys a record carries.
	 *
	 * @var RecordMetadataConditions
	 */
	private readonly RecordMetadataConditions $conditions;

	/**
	 * Reads the blocks a save is judged against.
	 *
	 * @var StoredRuleBlocks
	 */
	private readonly StoredRuleBlocks $stored;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection|null $db Reads the stored block when a save has findings; without it a save is judged as new.
	 */
	public function __construct(?IDBConnection $db = null) {
		$this->conditions = new RecordMetadataConditions();
		$this->stored = new StoredRuleBlocks(db: $db);
	}//end __construct()

	/**
	 * Refuse a schema save that adds or changes a condition on unknown metadata.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the save adds or changes one.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertSchemaSavable(Schema $schema): void {
		if ($this->schemaFindings(schema: $schema) === []) {
			return;
		}

		$this->assertNone(findings: $this->schemaFindings(schema: $schema, stored: $this->stored->schema(id: $schema->getId())));
	}//end assertSchemaSavable()

	/**
	 * Refuse a register save that adds or changes a condition on unknown metadata.
	 *
	 * @param Register $register The register being saved.
	 * @param string   $subject  What the block belongs to, for the message.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the save adds or changes one.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertRegisterSavable(Register $register, string $subject): void {
		if ($this->findings(authorization: $register->getAuthorization(), context: $subject) === []) {
			return;
		}

		$this->assertNone(
			findings: $this->findings(
				authorization: $register->getAuthorization(),
				context: $subject,
				stored: $this->stored->registerAuthorization(id: $register->getId())
			)
		);
	}//end assertRegisterSavable()

	/**
	 * One sentence per rule of an authorization block that names unknown metadata.
	 *
	 * Covers the grant rules of every action and the entries of the `deny`
	 * block. Other control keys are not rule lists and are left to their own
	 * validators. A condition the stored block already holds is not a finding, so
	 * a save can remove a bad rule or edit around one; only adding or changing one
	 * is refused.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 * @param array|null $stored        The block as stored, when saving over one.
	 *
	 * @return array<int, string> The findings; empty when every condition can match.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function findings(?array $authorization, string $context, ?array $stored = null): array {
		$held = array_column($this->unknownConditions(authorization: $stored), 'fingerprint');

		$findings = [];
		foreach ($this->unknownConditions(authorization: $authorization) as $condition) {
			if (in_array($condition['fingerprint'], $held, true) === true) {
				continue;
			}

			$findings[] = sprintf(
				'The %s rule for %s in %s has a condition on "%s", which is not metadata records carry,'
				.' so it can never match. Keys starting with "_" or "@self." name record metadata: %s.',
				$condition['label'],
				$condition['principal'],
				$context,
				$condition['key'],
				implode(', ', $this->conditions->fields())
			);
		}

		return $findings;
	}//end findings()

	/**
	 * The findings of a schema's own block and of every property's rules.
	 *
	 * @param Schema      $schema The schema.
	 * @param Schema|null $stored The schema as stored, when saving over one.
	 *
	 * @return array<int, string> The findings.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function schemaFindings(Schema $schema, ?Schema $stored = null): array {
		$name = sprintf('schema "%s"', (string)($schema->getSlug() ?? $schema->getTitle() ?? $schema->getId()));
		$findings = $this->findings(authorization: $schema->getAuthorization(), context: $name, stored: $stored?->getAuthorization());
		foreach ($this->stored->propertyBlocks(schema: $schema, stored: $stored, name: $name) as $block) {
			$findings = array_merge($findings, $this->findings(authorization: $block['authorization'], context: $block['context'], stored: $block['stored']));
		}

		return $findings;
	}//end schemaFindings()

	/**
	 * Every condition of a block on unknown metadata.
	 *
	 * The fingerprint names the rule list, the principal, the key and the
	 * condition's value, so other conditions of the rule can change around it.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return array<int, array{label: string, principal: string, key: string, fingerprint: string}> The conditions.
	 */
	private function unknownConditions(?array $authorization): array {
		$conditions = [];
		foreach ($this->ruleLists(authorization: $authorization) as $label => $rules) {
			foreach ($rules as $rule) {
				if (is_array($rule) === false || is_array(($rule['match'] ?? null)) === false) {
					continue;
				}

				$principal = $this->principalOf(rule: $rule);
				foreach ($this->conditions->unknownKeys(match: $rule['match']) as $key) {
					$conditions[] = [
						'label'       => $label,
						'principal'   => $principal,
						'key'         => $key,
						'fingerprint' => $label.'|'.$principal.'|'.$key.'|'.json_encode($rule['match'][$key]),
					];
				}
			}
		}

		return $conditions;
	}//end unknownConditions()

	/**
	 * Refuse a save that carries findings.
	 *
	 * @param array<int, string> $findings The findings.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When there is at least one finding.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function assertNone(array $findings): void {
		if ($findings === []) {
			return;
		}

		throw new AuthorizationBlockException(message: implode(' ', $findings));
	}//end assertNone()

	/**
	 * The rule lists of a block, keyed by how a message names them.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return array<string, array<int|string, mixed>> The lists.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function ruleLists(?array $authorization): array {
		$lists = [];
		foreach (($authorization ?? []) as $action => $rules) {
			foreach ($this->listsOf(action: $action, rules: $rules) as $key => $list) {
				$lists[$key] = $list;
			}
		}

		return $lists;
	}//end ruleLists()

	/**
	 * The rule lists one key of a block carries: one per denied verb under `deny`, else the key's own list.
	 *
	 * @param int|string $action The block key.
	 * @param mixed      $rules  Its value.
	 *
	 * @return array<string, array> Label to rule list; empty when the value is not a rule list.
	 */
	private function listsOf(int|string $action, mixed $rules): array {
		if (is_array($rules) === false) {
			return [];
		}

		if ($action === DenyResolverInterface::DENY_KEY) {
			return $this->denyLists(rules: $rules);
		}

		if (array_is_list($rules) === true) {
			return [(string)$action => $rules];
		}

		return [];
	}//end listsOf()

	/**
	 * The entry list of each denied verb, labelled `deny <verb>`.
	 *
	 * @param array $rules The `deny` block.
	 *
	 * @return array<string, array> Label to entry list.
	 */
	private function denyLists(array $rules): array {
		$lists = [];
		foreach ($rules as $denied => $entries) {
			if (is_array($entries) === true) {
				$lists['deny '.(string)$denied] = $entries;
			}
		}

		return $lists;
	}//end denyLists()

	/**
	 * Whom a rule names, for the message.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string The principal.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function principalOf(array $rule): string {
		if (is_string(($rule['group'] ?? null)) === true) {
			return sprintf('group "%s"', $rule['group']);
		}

		if (is_string(($rule['user'] ?? null)) === true) {
			return sprintf('user "%s"', $rule['user']);
		}

		return 'an unnamed principal';
	}//end principalOf()
}//end class
