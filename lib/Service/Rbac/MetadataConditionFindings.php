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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Names the rules of a stored or saved block that {@see RecordMetadataConditions} refuses.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class MetadataConditionFindings {

	/**
	 * Reads which metadata keys a record carries.
	 *
	 * @var RecordMetadataConditions
	 */
	private readonly RecordMetadataConditions $conditions;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection|null $db Reads the stored block when a save has findings; without it a save is judged as new.
	 */
	public function __construct(private readonly ?IDBConnection $db = null) {
		$this->conditions = new RecordMetadataConditions();
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

		$stored = null;
		$row = $this->storedRow(table: 'openregister_schemas', id: $schema->getId());
		if ($row !== null) {
			$stored = new Schema();
			$stored->setAuthorization($this->decoded(value: ($row['authorization'] ?? null)));
			$stored->setProperties($this->decoded(value: ($row['properties'] ?? null)));
		}

		$this->assertNone(findings: $this->schemaFindings(schema: $schema, stored: $stored));
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

		$row = $this->storedRow(table: 'openregister_registers', id: $register->getId());
		$this->assertNone(
			findings: $this->findings(
				authorization: $register->getAuthorization(),
				context: $subject,
				stored: $this->decoded(value: ($row['authorization'] ?? null))
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
		$storedProperties = ($stored?->getProperties() ?? []);

		foreach (($schema->getProperties() ?? []) as $property => $config) {
			if (is_array($config) === false || is_array(($config['authorization'] ?? null)) === false) {
				continue;
			}

			$held = ($storedProperties[$property]['authorization'] ?? null);
			if (is_array($held) === false) {
				$held = null;
			}

			$context = sprintf('property "%s" of %s', (string)$property, $name);
			$findings = array_merge($findings, $this->findings(authorization: $config['authorization'], context: $context, stored: $held));
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
	 */
	public function ruleLists(?array $authorization): array {
		$lists = [];
		foreach (($authorization ?? []) as $action => $rules) {
			if (is_array($rules) === false) {
				continue;
			}

			if ($action === DenyResolver::DENY_KEY) {
				foreach ($rules as $denied => $entries) {
					if (is_array($entries) === true) {
						$lists['deny '.(string)$denied] = $entries;
					}
				}

				continue;
			}

			if (array_is_list($rules) === true) {
				$lists[(string)$action] = $rules;
			}
		}

		return $lists;
	}//end ruleLists()

	/**
	 * Whom a rule names, for the message.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string The principal.
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

	/**
	 * The stored row, read past the mappers' caches and organisation filter.
	 *
	 * @param string   $table The table.
	 * @param int|null $id    The row's id, null for a new entity.
	 *
	 * @return array<string, mixed>|null The row, or null when there is none.
	 */
	public function storedRow(string $table, ?int $id): ?array {
		if ($id === null || $this->db === null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end storedRow()

	/**
	 * A stored JSON column as an array, or null when it holds none.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return array<int|string, mixed>|null The decoded value.
	 */
	public function decoded(mixed $value): ?array {
		if (is_string($value) === false) {
			return null;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decoded()
}//end class
