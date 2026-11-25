<?php

/**
 * OpenRegister PermissionMatrixExporter
 *
 * The full authorisation matrix: every group or user, against every register,
 * schema and action of the permission catalogue, with the rule that decided it
 * (REQ-RAH-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use Generator;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;

/**
 * Streams the matrix rows and seals them with a count and a SHA-256.
 *
 * The block that decides a schema is the one enforcement reads: the schema's
 * own when it has one, else its register's, else none, and no block at all is
 * the open default. A deny on the verb for one of the subject's principals,
 * written on the schema or on its register, wins over a grant; a verb the
 * deciding block does not name
 * for the subject is not granted. The rows are generated, never collected, so
 * a large instance is read twice (once to seal, once to send) rather than held.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionMatrixExporter {

	/**
	 * The columns of a row, in order.
	 *
	 * @var array<int, string>
	 */
	public const COLUMNS = [
		'subjectType',
		'subject',
		'groups',
		'register',
		'schema',
		'object',
		'action',
		'granted',
		'rule',
		'level',
		'role',
		'conditional',
	];

	public const RULE_ADMINISTRATOR = 'administrator';

	public const RULE_DEFAULT = 'default';

	public const RULE_SCHEMA = 'schema-rule';

	public const RULE_REGISTER = 'register-default';

	public const RULE_ROLE = 'role';

	public const RULE_DENY = 'deny';

	public const RULE_DENY_STAGED = 'deny-staged';

	public const RULE_OBJECT = 'object-grant';

	public const RULE_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param PermissionCatalogueInterface $catalogue The grantable verbs, one row each.
	 * @param ObjectPermissionsResolver    $resolver  Reads the holders and the denies out of a block.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		private readonly PermissionCatalogueInterface $catalogue,
		private readonly ObjectPermissionsResolver $resolver = new ObjectPermissionsResolver(),
	) {
	}//end __construct()

	/**
	 * Seal the matrix: count and hash the rows, and hand back an export that streams them.
	 *
	 * @param PermissionMatrixRequest    $request    What the export covers.
	 * @param PermissionMatrixProvenance $provenance Who, when, where and how.
	 *
	 * @return PermissionMatrixExport The sealed export.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function export(PermissionMatrixRequest $request, PermissionMatrixProvenance $provenance): PermissionMatrixExport {
		$context = hash_init('sha256');
		$count = 0;
		foreach ($this->rows(request: $request) as $row) {
			hash_update($context, self::csvLine(row: $row));
			$count++;
		}

		return new PermissionMatrixExport(
			provenance: $provenance,
			seal: ['rowCount' => $count, 'sha256' => hash_final($context), 'objectGrantsListed' => ($request->objectGrants !== null)],
			rows: fn (): Generator => $this->rows(request: $request)
		);
	}//end export()

	/**
	 * Every row of the matrix, register by register, schema by schema, subject by subject.
	 *
	 * @param PermissionMatrixRequest $request What the export covers.
	 *
	 * @return Generator<int, array<string, string>> The rows, keyed by column.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function rows(PermissionMatrixRequest $request): Generator {
		$verbs = $this->catalogue->verbs();
		foreach ($request->registers as $register) {
			foreach ($this->schemasOf(register: $register, schemas: $request->schemas) as $schema) {
				$rules = $this->decidingRules(register: $register, schema: $schema);
				foreach ($request->subjects as $subject) {
					foreach ($verbs as $verb) {
						yield $this->row(
							subject: $subject,
							place: ['register' => (string)$register->getSlug(), 'schema' => (string)$schema->getSlug(), 'object' => ''],
							verb: $verb,
							decision: $this->decide(rules: $rules, subject: $subject, verb: $verb, enforcing: $request->denyEnforcing)
						);
					}
				}
			}
		}

		foreach (($request->objectGrants ?? []) as $grant) {
			yield $this->objectGrantRow(grant: $grant);
		}
	}//end rows()

	/**
	 * One row as a CSV line, newline included.
	 *
	 * @param array<string, string> $row The row, keyed by column.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public static function csvLine(array $row): string {
		$cells = [];
		foreach (self::COLUMNS as $column) {
			$cells[] = self::csvCell(value: (string)($row[$column] ?? ''));
		}

		return implode(',', $cells)."\n";
	}//end csvLine()

	/**
	 * One CSV cell, quoted when it holds a separator, a quote or a line break.
	 *
	 * A cell starting with a formula character is prefixed with a quote, so a
	 * spreadsheet opening the matrix shows the name rather than running it.
	 *
	 * @param string $value The value.
	 *
	 * @return string The cell.
	 */
	private static function csvCell(string $value): string {
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) === true) {
			$value = "'".$value;
		}

		if (strpbrk($value, ",\"\r\n") === false) {
			return $value;
		}

		return '"'.str_replace('"', '""', $value).'"';
	}//end csvCell()

	/**
	 * The schemas the register holds, in the order given.
	 *
	 * @param Register           $register The register.
	 * @param array<int, Schema> $schemas  The schemas to consider.
	 *
	 * @return array<int, Schema> The schemas of this register.
	 */
	private function schemasOf(Register $register, array $schemas): array {
		$ids = ($register->getSchemas() ?? []);

		return array_values(
			array_filter(
				$schemas,
				static fn (Schema $schema): bool => in_array($schema->getId(), $ids, false) === true
			)
		);
	}//end schemasOf()

	/**
	 * The grants and denies of the block that decides this schema, or null when no block does.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array{grants: array<int, array<string, mixed>>, denied: array<int, array<string, mixed>>}|null The rules.
	 */
	private function decidingRules(Register $register, Schema $schema): ?array {
		$level = $this->decidingLevel(register: $register, schema: $schema);
		if ($level === null) {
			return null;
		}

		$block = $schema->getAuthorization();
		if ($level === 'register') {
			$block = $register->getAuthorization();
		}

		$roles = $this->roleDefinitionsOf(register: $register);
		$set = $this->resolver->holders(blocks: [$level => $block], roleDefinitions: $roles);

		$grants = [];
		foreach ($set['holders'] as $holder) {
			$grants = [...$grants, ...$holder['rules']];
		}

		// A deny is not overridden by a broader grant, so the register's denies
		// reach a schema that writes its own block, and both are read.
		$denies = $this->resolver->holders(
			blocks: ['schema' => $schema->getAuthorization(), 'register' => $register->getAuthorization()],
			roleDefinitions: $roles
		);

		return ['grants' => $grants, 'denied' => $denies['denied']];
	}//end decidingRules()

	/**
	 * Which block decides: the schema's own, else the register's, else none.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return string|null `schema`, `register`, or null for the open default.
	 */
	private function decidingLevel(Register $register, Schema $schema): ?string {
		if ($this->isConfigured(block: $schema->getAuthorization()) === true) {
			return 'schema';
		}

		if ($this->isConfigured(block: $register->getAuthorization()) === true) {
			return 'register';
		}

		return null;
	}//end decidingLevel()

	/**
	 * Whether a block is written at all.
	 *
	 * @param mixed $block The block.
	 *
	 * @return bool True for a non-empty array.
	 */
	private function isConfigured(mixed $block): bool {
		return is_array($block) === true && $block !== [];
	}//end isConfigured()

	/**
	 * The role definitions a register declares.
	 *
	 * @param Register $register The register.
	 *
	 * @return mixed The definitions, or null.
	 */
	private function roleDefinitionsOf(Register $register): mixed {
		$configuration = $register->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		return ($configuration['roles'] ?? null);
	}//end roleDefinitionsOf()

	/**
	 * Whether the subject holds the verb, and the rule that says so.
	 *
	 * @param array|null              $rules     The deciding block's grants and denies, or null.
	 * @param PermissionMatrixSubject $subject   The subject.
	 * @param string                  $verb      The verb.
	 * @param bool                    $enforcing Whether a deny bites now.
	 *
	 * @return array{granted: bool, rule: string, level: string, role: string, conditional: bool} The decision.
	 */
	private function decide(?array $rules, PermissionMatrixSubject $subject, string $verb, bool $enforcing): array {
		if ($subject->isAdministrator() === true) {
			return $this->decision(granted: true, rule: self::RULE_ADMINISTRATOR);
		}

		if ($rules === null) {
			return $this->decision(granted: true, rule: self::RULE_DEFAULT);
		}

		$principals = $subject->principals();
		$grant = $this->firstFor(rules: $rules['grants'], principals: $principals, verb: $verb);
		$deny = $this->firstFor(rules: $rules['denied'], principals: $principals, verb: $verb);

		if ($deny !== null && $enforcing === true) {
			return $this->decision(granted: false, rule: self::RULE_DENY, from: $deny);
		}

		if ($deny !== null) {
			return $this->decision(granted: ($grant !== null), rule: self::RULE_DENY_STAGED, from: $deny);
		}

		if ($grant !== null) {
			return $this->decision(granted: true, rule: $this->grantRule(grant: $grant), from: $grant);
		}

		return $this->decision(granted: false, rule: self::RULE_NONE);
	}//end decide()

	/**
	 * The label of the rule a grant came through.
	 *
	 * @param array<string, mixed> $grant The grant.
	 *
	 * @return string A role, the schema's own rule, or the register's block standing in for the schema's.
	 */
	private function grantRule(array $grant): string {
		if (($grant['role'] ?? null) !== null) {
			return self::RULE_ROLE;
		}

		if ($grant['level'] === 'schema') {
			return self::RULE_SCHEMA;
		}

		return self::RULE_REGISTER;
	}//end grantRule()

	/**
	 * The first rule on this verb that names one of the principals.
	 *
	 * @param array<int, array<string, mixed>> $rules      The rules.
	 * @param array<int, string>               $principals The subject's principal names.
	 * @param string                           $verb       The verb.
	 *
	 * @return array<string, mixed>|null The rule, or null.
	 */
	private function firstFor(array $rules, array $principals, string $verb): ?array {
		foreach ($rules as $rule) {
			if (($rule['action'] ?? null) === $verb && in_array($rule['principal'] ?? null, $principals, true) === true) {
				return $rule;
			}
		}

		return null;
	}//end firstFor()

	/**
	 * A decision, with the level, role and condition of the rule behind it.
	 *
	 * @param bool                      $granted Whether the verb is held.
	 * @param string                    $rule    The rule label.
	 * @param array<string, mixed>|null $from    The rule as resolved, or null for a decision no rule wrote.
	 *
	 * @return array{granted: bool, rule: string, level: string, role: string, conditional: bool} The decision.
	 */
	private function decision(bool $granted, string $rule, ?array $from = null): array {
		return [
			'granted' => $granted,
			'rule' => $rule,
			'level' => (string)($from['level'] ?? ''),
			'role' => (string)($from['role'] ?? ''),
			'conditional' => (($from['conditional'] ?? false) === true),
		];
	}//end decision()

	/**
	 * One matrix row.
	 *
	 * @param PermissionMatrixSubject                            $subject  The subject.
	 * @param array{register: string, schema: string, object: string} $place    Where.
	 * @param string                                             $verb     The verb.
	 * @param array<string, mixed>                               $decision The decision.
	 *
	 * @return array<string, string> The row, keyed by column.
	 */
	private function row(PermissionMatrixSubject $subject, array $place, string $verb, array $decision): array {
		return [
			'subjectType' => $subject->type,
			'subject' => $subject->id,
			'groups' => implode('|', $subject->groups),
			'register' => $place['register'],
			'schema' => $place['schema'],
			'object' => $place['object'],
			'action' => $verb,
			'granted' => $this->flag(value: $decision['granted']),
			'rule' => $decision['rule'],
			'level' => $decision['level'],
			'role' => $decision['role'],
			'conditional' => $this->flag(value: $decision['conditional']),
		];
	}//end row()

	/**
	 * One per-object grant as its own row.
	 *
	 * @param array<string, mixed> $grant The grant: register, schema, object, principal, principalType, action, rule.
	 *
	 * @return array<string, string> The row, keyed by column.
	 */
	private function objectGrantRow(array $grant): array {
		return [
			'subjectType' => (string)($grant['principalType'] ?? PermissionMatrixSubject::TYPE_GROUP),
			'subject' => (string)($grant['principal'] ?? ''),
			'groups' => '',
			'register' => (string)($grant['register'] ?? ''),
			'schema' => (string)($grant['schema'] ?? ''),
			'object' => (string)($grant['object'] ?? ''),
			'action' => (string)($grant['action'] ?? ''),
			'granted' => 'true',
			'rule' => (string)($grant['rule'] ?? self::RULE_OBJECT),
			'level' => 'object',
			'role' => '',
			'conditional' => 'false',
		];
	}//end objectGrantRow()

	/**
	 * A boolean as the matrix writes it.
	 *
	 * @param bool $value The value.
	 *
	 * @return string `true` or `false`.
	 */
	private function flag(bool $value): string {
		if ($value === true) {
			return 'true';
		}

		return 'false';
	}//end flag()
}//end class
