<?php

/**
 * MarkImplicitCrossOrganisationReadRules: keeps the read rules that bound a
 * row field to the caller's organisation reading across organisations, now
 * that only `crossOrganisation: true` takes a rule there.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\MatrixRowKeys;
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: add `crossOrganisation: true` to every read rule of a schema's
 * own block that binds a field other than `_organisation` to the caller's
 * organisation or membership and is held through a named group
 * (`{"group": "aanbod-beheerder", "match": {"provider": "$organisation"}}`).
 *
 * Such a rule has no meaning inside one organisation, so it is marked rather
 * than narrowed. Plain group rules, `authenticated`, user rules and `public`
 * rules are not marked: they keep the caller's organisation filter, and the
 * step logs each schema that has them so an administrator can mark the ones
 * that are meant to cross.
 *
 * Idempotent, and never throws: one unreadable schema must not abort an upgrade.
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */
class MarkImplicitCrossOrganisationReadRules implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface    $schemaMapper Schema lookups and writes.
	 * @param LoggerInterface $logger       Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function getName(): string {
		return 'Mark the read rules that read across organisations by a field bound to the caller\'s organisation';
	}//end getName()

	/**
	 * Walk every schema, mark its implicit crossing read rules and log the rest.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function run(IOutput $output): void {
		$schemas = (new SchemaListing(schemaMapper: $this->schemaMapper, logger: $this->logger))->allOrSkip(step: 'MarkImplicitCrossOrganisationReadRules');
		if ($schemas === null) {
			return;
		}

		$output->info(self::summary(counts: $this->repairAll(schemas: $schemas)));
	}//end run()

	/**
	 * Repair every schema and count the outcomes.
	 *
	 * @param array $schemas The listed schemas (entries that are not schemas are skipped).
	 *
	 * @return array<string, int> Schemas marked, failed and unchanged, and read rules that narrow.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function repairAll(array $schemas): array {
		$counts = ['marked' => 0, 'failed' => 0, 'unchanged' => 0, 'narrowed' => 0];
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			$outcome = $this->repairSchema(schema: $schema);
			$counts[$outcome['state']]++;
			$counts['narrowed'] += $outcome['narrowed'];
		}

		return $counts;
	}//end repairAll()

	/**
	 * The closing line: schemas marked, writes refused, and read rules that now narrow.
	 *
	 * @param array<string, int> $counts The run's counts.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private static function summary(array $counts): string {
		return sprintf(
			'Marked crossOrganisation on the read rules of %d schema(s), %d could not be written. '
			. '%d other read rule(s) now keep the caller\'s organisation filter (listed in the log).',
			$counts['marked'],
			$counts['failed'],
			$counts['narrowed']
		);
	}//end summary()

	/**
	 * Mark one schema's implicit crossing read rules and log the rules that narrow.
	 *
	 * @param Schema $schema The schema to walk.
	 *
	 * @return array{state: string, narrowed: int} `state` is marked, failed or unchanged.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function repairSchema(Schema $schema): array {
		$authorization = ($schema->getAuthorization() ?? []);
		$read = ($authorization['read'] ?? null);
		if (is_array($read) === false) {
			return ['state' => 'unchanged', 'narrowed' => 0];
		}

		$tally = $this->markRules(read: $read);
		$this->logNarrowed(schema: $schema, narrowed: $tally['narrowed']);

		if ($tally['marked'] === 0) {
			return ['state' => 'unchanged', 'narrowed' => $tally['narrowed']];
		}

		$authorization['read'] = $tally['read'];
		return ['state' => $this->write(schema: $schema, authorization: $authorization), 'narrowed' => $tally['narrowed']];
	}//end repairSchema()

	/**
	 * Mark the read rules that cross implicitly, and count those and the rules that narrow.
	 *
	 * @param array $read The read rules as written.
	 *
	 * @return array{read: array, marked: int, narrowed: int} The marked rules and the two counts.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function markRules(array $read): array {
		$marked = 0;
		$narrowed = 0;
		foreach ($read as $index => $rule) {
			if ($this->crossesImplicitly(rule: $rule) === true) {
				$read[$index][MatrixRowKeys::CROSS_ORGANISATION_KEY] = true;
				$marked++;
				continue;
			}

			$narrowed += (int)$this->narrows(rule: $rule);
		}

		return ['read' => $read, 'marked' => $marked, 'narrowed' => $narrowed];
	}//end markRules()

	/**
	 * Log the read rules of a schema that now keep the caller's organisation filter, when there are any.
	 *
	 * @param Schema $schema   The schema.
	 * @param int    $narrowed How many of its read rules narrow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function logNarrowed(Schema $schema, int $narrowed): void {
		if ($narrowed > 0) {
			$this->logger->info(
				'[MarkImplicitCrossOrganisationReadRules] Schema ' . (string)$schema->getId()
				. ' has ' . $narrowed . ' read rule(s) that now keep the caller\'s organisation filter;'
				. ' add crossOrganisation: true to those meant to read other organisations\' rows.'
			);
		}
	}//end logNarrowed()

	/**
	 * Whether a rule crossed organisations by binding a field to the caller's
	 * organisation or membership, and is not marked yet.
	 *
	 * @param mixed $rule The rule as written.
	 *
	 * @return bool True when the rule must be marked to keep reading across organisations.
	 */
	private function crossesImplicitly(mixed $rule): bool {
		$tenancy = new ReadRuleTenancy();
		if (is_array($rule) === false || array_key_exists(MatrixRowKeys::CROSS_ORGANISATION_KEY, $rule) === true || isset($rule['user']) === true) {
			return false;
		}

		$group = ($rule['group'] ?? null);
		if (is_string($group) === false || in_array($group, ['', 'public', 'authenticated'], true) === true) {
			return false;
		}

		return $tenancy->pinsCallerOrganisation(value: ($rule['match']['_organisation'] ?? null)) === false
			&& $tenancy->bindsFieldToCallerTenancy(rule: $rule) === true;
	}//end crossesImplicitly()

	/**
	 * Whether a rule read other organisations' rows before and keeps the
	 * caller's organisation filter now: a plain group rule or a `public` rule
	 * without the marker.
	 *
	 * @param mixed $rule The rule as written.
	 *
	 * @return bool True when the rule narrows.
	 */
	private function narrows(mixed $rule): bool {
		if (is_string($rule) === true) {
			return in_array($rule, ['authenticated', 'admin'], true) === false && str_starts_with($rule, 'user:') === false;
		}

		return is_array($rule) === true && ($rule['group'] ?? null) === 'public'
			&& (new ReadRuleTenancy())->isMarked(rule: $rule) === false;
	}//end narrows()

	/**
	 * Write the marked authorization block.
	 *
	 * @param Schema $schema        The schema.
	 * @param array  $authorization The block with its marked read rules.
	 *
	 * @return string `marked`, or `failed` when the write was refused.
	 */
	private function write(Schema $schema, array $authorization): string {
		try {
			$schema->setAuthorization($authorization);
			$this->schemaMapper->update($schema);
		} catch (Throwable $e) {
			$this->logRefusedWrite(schema: $schema, error: $e);
			return 'failed';
		}

		$this->logger->info(
			'[MarkImplicitCrossOrganisationReadRules] Marked crossOrganisation on the read rules of schema '
			. (string)$schema->getId()
		);
		return 'marked';
	}//end write()

	/**
	 * Log a schema whose marked read rules could not be written.
	 *
	 * @param Schema    $schema The schema.
	 * @param Throwable $error  Why the write was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	private function logRefusedWrite(Schema $schema, Throwable $error): void {
		$this->logger->warning(
			'[MarkImplicitCrossOrganisationReadRules] Could not mark the read rules of schema '
			. (string)$schema->getId() . ': ' . $error->getMessage()
		);
	}//end logRefusedWrite()
}//end class
