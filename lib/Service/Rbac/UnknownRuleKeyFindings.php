<?php

/**
 * Rules whose `match` keys name no column.
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
 * Names the rules that {@see RuleColumns} refuses, and refuses a save that adds or changes one.
 *
 * A condition the stored block already holds is not refused, whatever its key,
 * so a schema can drop a property a rule names and a bad rule can be removed or
 * edited around. Such a condition still admits no row.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class UnknownRuleKeyFindings implements UnknownRuleKeyFindingsInterface {

	/**
	 * Reads rule lists, principals and stored blocks.
	 *
	 * @var MetadataConditionFindings
	 */
	private readonly MetadataConditionFindings $blocks;

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
		$this->blocks = new MetadataConditionFindings(db: $db);
		$this->stored = new StoredRuleBlocks(db: $db);
	}//end __construct()

	/**
	 * Refuse a schema save that adds or changes a condition on an unknown or malformed key.
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

		$this->blocks->assertNone(findings: $this->schemaFindings(schema: $schema, stored: $this->stored->schema(id: $schema->getId())));
	}//end assertSchemaSavable()

	/**
	 * Refuse a register save that adds or changes a condition on a malformed key.
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
		if ($this->registerFindings(authorization: $register->getAuthorization(), context: $subject) === []) {
			return;
		}

		$this->blocks->assertNone(
			findings: $this->findingsIn(
				block: self::registerBlock(authorization: $register->getAuthorization(), context: $subject),
				stored: $this->stored->registerAuthorization(id: $register->getId())
			)
		);
	}//end assertRegisterSavable()

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
		$columns = new RuleColumns(schema: $schema);
		$findings = $this->findingsIn(
			block: ['authorization' => $schema->getAuthorization(), 'context' => $name, 'columns' => $columns],
			stored: $stored?->getAuthorization()
		);
		foreach ($this->stored->propertyBlocks(save: new SchemaSave(schema: $schema, stored: $stored), name: $name) as $block) {
			$block['columns'] = $columns;
			$findings = array_merge($findings, $this->findingsIn(block: $block, stored: $block['stored']));
		}

		return $findings;
	}//end schemaFindings()

	/**
	 * The findings of a register's block, where only a key's form can be judged.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 *
	 * @return array<int, string> The findings.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function registerFindings(?array $authorization, string $context): array {
		return $this->findingsIn(block: self::registerBlock(authorization: $authorization, context: $context), stored: null);
	}//end registerFindings()

	/**
	 * A register's block, judged by the columns every register shares.
	 *
	 * @param array|null $authorization The block.
	 * @param string     $context       What the block belongs to, for the message.
	 *
	 * @return array{authorization: ?array, context: string, columns: RuleColumns} The block and how to judge it.
	 */
	private static function registerBlock(?array $authorization, string $context): array {
		return ['authorization' => $authorization, 'context' => $context, 'columns' => new RuleColumns()];
	}//end registerBlock()

	/**
	 * The finding for a stored condition on a key that names nothing.
	 *
	 * @param array{label: string, principal: string, key: string} $condition The condition.
	 * @param string                                                $context   Where the block sits.
	 *
	 * @return string The finding.
	 */
	private static function unknownKeyFinding(array $condition, string $context): string {
		return sprintf(
			'The %s rule for %s in %s has a condition on "%s", which names no property or record metadata, so it admits no row.',
			$condition['label'],
			$condition['principal'],
			$context,
			$condition['key']
		);
	}//end unknownKeyFinding()

	/**
	 * One sentence per condition of a block that its columns refuse and the stored block does not hold.
	 *
	 * @param array{authorization: ?array, context: string, columns: RuleColumns} $block  The block and how to judge it.
	 * @param array|null                                                          $stored The block as stored.
	 *
	 * @return array<int, string> The findings.
	 */
	private function findingsIn(array $block, ?array $stored): array {
		$held = array_column($this->conditions(authorization: $stored), 'fingerprint');

		$findings = [];
		foreach ($this->conditions(authorization: $block['authorization']) as $condition) {
			if ($block['columns']->refusesAtSave(key: $condition['key']) === false
				|| in_array($condition['fingerprint'], $held, true) === true
			) {
				continue;
			}

			$findings[] = self::unknownKeyFinding(condition: $condition, context: $block['context']);
		}

		return $findings;
	}//end findingsIn()

	/**
	 * Every condition of a block, with what identifies it across a save.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return array<int, array{label: string, principal: string, key: string, fingerprint: string}> The conditions.
	 */
	private function conditions(?array $authorization): array {
		$conditions = [];
		foreach ($this->blocks->matchRules(authorization: $authorization) as $rule) {
			foreach ($rule['match'] as $key => $value) {
				$conditions[] = [
					'label'       => (string)$rule['label'],
					'principal'   => $rule['principal'],
					'key'         => (string)$key,
					'fingerprint' => $rule['label'].'|'.$rule['principal'].'|'.$key.'|'.json_encode($value),
				];
			}
		}

		return $conditions;
	}//end conditions()
}//end class
