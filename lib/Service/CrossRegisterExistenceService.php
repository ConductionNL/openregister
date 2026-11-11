<?php

/**
 * Asking across registers whether a row exists, without reading it.
 *
 * 🔴 EXISTENCE IS A DIFFERENT DISCLOSURE FROM THE ROW (D-1). A row in a
 * Jeugdwet register is special-category data; that a row exists is not.
 * Collapsing the two is what forces a caller to search, get the title, the
 * status, the dates and every property the schema declares, and throw most of
 * it away in code the register's owner never sees. Worse, a property somebody
 * adds to that schema tomorrow arrives in the caller's hands without anybody
 * deciding it should. This endpoint gives the smaller disclosure its own door.
 *
 * 🔴 THE ANSWER IS ASSEMBLED, NEVER FILTERED DOWN (D-2). Nothing here takes a
 * row and removes fields from it. It builds an answer out of named values, so a
 * schema that grows a property grows nothing in this answer. A leak in the
 * filtered shape needs a new `unset()` somebody has to think of; in this shape
 * it cannot happen.
 *
 * 🔑 SAID PRECISELY: THE SERVER STILL READS. It has to; it owns the data and
 * has to count. What it does not do is HAND THE ROW OVER. The property this
 * class provides is about what crosses the boundary to the caller, and that is
 * exactly the property the caller cannot provide for itself.
 *
 * 🔴 A REFUSED PROBE IS NOT AN EMPTY ONE (D-4). A caller with no read access to
 * a register is told the probe was refused, never told that nothing exists.
 * Reporting a refusal as "no row here" would make this endpoint a way to learn
 * that a register holds nothing about a person, which is itself an answer the
 * caller was not entitled to, and it would be indistinguishable from the truth.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/cross-register-existence-query/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answer, per probe, whether a row exists and how many.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/cross-register-existence-query/spec.md
 */
class CrossRegisterExistenceService {

	/**
	 * How many probes one call may carry.
	 *
	 * 🔑 REFUSED, NOT TRUNCATED. Silently dropping the eleventh probe answers
	 * "no row exists there" about a register nobody asked, which is a wrong
	 * answer rather than a missing one.
	 *
	 * @var int
	 */
	public const MAX_PROBES = 10;

	/**
	 * The most matches counted before the answer says "at least this many".
	 *
	 * A count, not rows (D-5). A bare boolean would send a caller back to
	 * searching the moment they need to know whether there is one or forty.
	 *
	 * @var int
	 */
	public const MAX_COUNT = 100;

	/**
	 * The keys every answer carries, and there are no others.
	 *
	 * Written down so a test can assert the shape rather than enumerate what it
	 * happens to see: "these and nothing else" is the requirement, and a test
	 * reading the keys off the answer would pass on an answer that grew a
	 * fourth.
	 *
	 * @var array<int, string>
	 */
	public const ANSWER_FIELDS = ['register', 'schema', 'exists', 'matches', 'revealed', 'refusedFields', 'refused'];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface   $objects The object service, used with RBAC ON.
	 * @param SchemaMapperInterface    $schemas The schema store, for what a field may be.
	 * @param LoggerInterface $logger  The logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a set of probes.
	 *
	 * @param array<int, array<string, mixed>> $probes Each `{register, schema, filters, reveal}`.
	 *
	 * @return array{probes: array<int, array<string, mixed>>}|array{error: string, message: string}
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function probe(array $probes): array {
		if (count($probes) > self::MAX_PROBES) {
			return self::tooManyProbes(count: count($probes));
		}

		$answers = [];
		foreach ($probes as $probe) {
			if (is_array($probe) === false) {
				continue;
			}

			$answers[] = $this->one(probe: $probe);
		}

		return ['probes' => $answers];
	}//end probe()

	/**
	 * The refusal of a call with more probes than allowed; nothing is queried.
	 *
	 * @param int $count How many probes the call carried.
	 *
	 * @return array{error: string, message: string}
	 */
	private static function tooManyProbes(int $count): array {
		return [
			'error' => 'too-many-probes',
			'message' => 'A call carries at most ' . self::MAX_PROBES . ' probes; this one carried '
				. $count . '. Nothing was queried.',
		];
	}//end tooManyProbes()

	/**
	 * Answer one probe.
	 *
	 * @param array<string, mixed> $probe The probe.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	private function one(array $probe): array {
		$target = [
			'register' => trim((string)($probe['register'] ?? '')),
			'schema' => trim((string)($probe['schema'] ?? '')),
		];
		$filters = ($probe['filters'] ?? []);

		if ($this->isIncomplete(target: $target, filters: $filters) === true) {
			return $this->answer(
				target: $target,
				outcome: ['refused' => 'A probe names a register, a schema and at least one filter.'],
			);
		}

		return $this->searchedAnswer(target: $target, probe: $probe);
	}//end one()

	/**
	 * The answer to a complete probe: what its search found, revealing only the fields the caller may read.
	 *
	 * @param array{register:string,schema:string} $target The trimmed register and schema.
	 * @param array<string,mixed>                  $probe  The probe.
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	private function searchedAnswer(array $target, array $probe): array {
		[$allowed, $refusedFields] = $this->narrowReveal(
			schema: $target['schema'],
			reveal: $this->wantedFields(probe: $probe)
		);

		$found = $this->search(target: $target, filters: ($probe['filters'] ?? []));
		if ($found === null) {
			return $this->refusedSearchAnswer(target: $target, refusedFields: $refusedFields);
		}

		$rows = $this->rowsOf(value: $found['value']);

		return $this->answer(
			target: $target,
			outcome: $this->matched(rows: $rows, fields: $allowed) + ['refusedFields' => $refusedFields],
		);
	}//end searchedAnswer()

	/**
	 * The answer to a probe whose search was refused: it says nothing about whether a row exists.
	 *
	 * @param array{register:string,schema:string} $target        The trimmed register and schema.
	 * @param array<int,string>                    $refusedFields The fields the caller may not have revealed.
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	private function refusedSearchAnswer(array $target, array $refusedFields): array {
		return $this->answer(
			target: $target,
			outcome: [
				'refusedFields' => $refusedFields,
				'refused' => 'This probe was refused. It is not an answer about whether a row exists.',
			],
		);
	}//end refusedSearchAnswer()

	/**
	 * Whether a probe lacks a register, a schema or a filter.
	 *
	 * @param array{register: string, schema: string} $target  The trimmed register and schema.
	 * @param mixed                                   $filters The probe's filters.
	 *
	 * @return bool True when the probe cannot be asked.
	 */
	private function isIncomplete(array $target, mixed $filters): bool {
		return $target['register'] === '' || $target['schema'] === '' || is_array($filters) === false || $filters === [];
	}//end isIncomplete()

	/**
	 * The fields a probe asks to reveal; anything but a list asks for none.
	 *
	 * @param array<string, mixed> $probe The probe.
	 *
	 * @return array<int|string, mixed> The asked fields.
	 */
	private function wantedFields(array $probe): array {
		$reveal = ($probe['reveal'] ?? []);
		if (is_array($reveal) === true) {
			return $reveal;
		}

		return [];
	}//end wantedFields()

	/**
	 * Search the probed register and schema the way a search does, RBAC ON.
	 *
	 * @param array{register: string, schema: string} $target  The register and schema.
	 * @param array<string, mixed>                    $filters The probe's filters.
	 *
	 * @return array{value: mixed}|null What the search answered, or null when it was refused.
	 */
	private function search(array $target, array $filters): ?array {
		try {
			// RBAC ON, which is the whole authorisation (D-4): this endpoint
			// can never answer about a register the caller could not have
			// searched, because it asks the same way a search does.
			$rows = $this->objects->searchObjects(
				query: self::scopedQuery(target: $target, filters: $filters),
				_rbac: true,
				_multitenancy: true,
			);
		} catch (Throwable $e) {
			$this->logRefusedProbe(target: $target, error: $e);

			return null;
		}//end try

		return ['value' => $rows];
	}//end search()

	/**
	 * A probe's filters, scoped to its register and schema and capped at MAX_COUNT rows.
	 *
	 * @param array{register:string,schema:string} $target  The register and schema.
	 * @param array                                $filters The probe's filters.
	 *
	 * @return array
	 */
	private static function scopedQuery(array $target, array $filters): array {
		return array_merge(
			$filters,
			['@self' => ['register' => $target['register'], 'schema' => $target['schema']], '_limit' => self::MAX_COUNT]
		);
	}//end scopedQuery()

	/**
	 * Log a probe the search refused; it says nothing about whether a row exists.
	 *
	 * @param array{register:string,schema:string} $target The register and schema.
	 * @param Throwable                            $error  The refusal.
	 *
	 * @return void
	 */
	private function logRefusedProbe(array $target, Throwable $error): void {
		$this->logger->info(
			message: '[CrossRegisterExistenceService] probe of ' . $target['register'] . '/' . $target['schema']
				. ' was refused: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logRefusedProbe()

	/**
	 * Whether rows matched, how many, and the allowed values of the first.
	 *
	 * FIELD BY FIELD, from the first match only. Nothing of the row travels
	 * but the values a caller named and the schema allowed.
	 *
	 * @param array<int, array<string, mixed>> $rows   The matched rows.
	 * @param array<int, string>               $fields The allowed fields.
	 *
	 * @return array{exists: bool, matches: int, revealed: array<string, mixed>}
	 */
	private function matched(array $rows, array $fields): array {
		$revealed = [];
		if ($rows !== []) {
			$revealed = $this->reveal(row: $rows[0], fields: $fields);
		}

		return ['exists' => ($rows !== []), 'matches' => count($rows), 'revealed' => $revealed];
	}//end matched()

	/**
	 * Narrow a caller's `reveal` to what the schema allows, naming the rest.
	 *
	 * 🔴 THE REFUSED FIELDS ARE REPORTED BY NAME (D-3). A caller silently
	 * receiving less than it asked for goes looking for a bug in its own code,
	 * or worse, concludes the row does not carry that value.
	 *
	 * `writeOnly` and a property carrying an `authorization` block are the
	 * platform's EXISTING vocabulary for "not for every reader"
	 * (`row-field-level-security`). Inventing a second marker here would give
	 * one schema two answers about one property.
	 *
	 * @param string             $schema The schema slug.
	 * @param array<int, string> $reveal What the caller asked for.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The allowed and the refused.
	 *
	 * @spec openspec/specs/cross-register-existence-query/spec.md#requirement-revealed-fields-are-bounded-by-the-schema-not-by-the-caller
	 */
	private function narrowReveal(string $schema, array $reveal): array {
		if ($reveal === []) {
			return [[], []];
		}

		$rules = $this->fieldRules(schema: $schema);
		if ($rules === null) {
			// An unreadable schema allows NOTHING, which fails closed: the
			// alternative is revealing a field nobody could check the rules for.
			return [[], array_values(array_map(static fn ($f): string => (string)$f, $reveal))];
		}

		return $this->partition(reveal: $reveal, rules: $rules);
	}//end narrowReveal()

	/**
	 * The declared and the withheld properties of a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array{declared: array<int, string>, withheld: array<int, string>}|null Null when the schema cannot be read.
	 */
	private function fieldRules(string $schema): ?array {
		try {
			$found = $this->schemas->find($schema);
			return [
				'declared' => array_keys($found->getProperties()),
				'withheld' => array_merge(
					$found->getWriteOnlyProperties(),
					array_keys($found->getPropertiesWithAuthorization())
				),
			];
		} catch (Throwable $e) {
			return null;
		}
	}//end fieldRules()

	/**
	 * Split the asked fields into the allowed and the refused.
	 *
	 * @param array<int, string>                                                $reveal What the caller asked for.
	 * @param array{declared: array<int, string>, withheld: array<int, string>} $rules  The schema's field rules.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The allowed and the refused.
	 */
	private function partition(array $reveal, array $rules): array {
		$allowed = [];
		$refused = [];
		foreach (self::namedFields(reveal: $reveal) as $field) {
			if (self::isRevealable(field: $field, rules: $rules) === true) {
				$allowed[] = $field;
				continue;
			}

			$refused[] = $field;
		}

		return [$allowed, $refused];
	}//end partition()

	/**
	 * The requested field names, trimmed, without the empty ones.
	 *
	 * @param array $reveal The fields the probe asks to reveal.
	 *
	 * @return array<int, string>
	 */
	private static function namedFields(array $reveal): array {
		$fields = [];
		foreach ($reveal as $field) {
			$field = trim((string)$field);
			if ($field !== '') {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end namedFields()

	/**
	 * Whether a field may be revealed: the schema declares it and does not withhold it.
	 *
	 * @param string                                                       $field The field.
	 * @param array{declared: array<int, string>, withheld: array<int, string>} $rules The schema's declared and withheld fields.
	 *
	 * @return bool
	 */
	private static function isRevealable(string $field, array $rules): bool {
		return in_array($field, $rules['declared'], true) === true && in_array($field, $rules['withheld'], true) === false;
	}//end isRevealable()

	/**
	 * Build the revealed block out of named values.
	 *
	 * @param array<string, mixed> $row    The matched row.
	 * @param array<int, string>   $fields The allowed fields.
	 *
	 * @return array<string, mixed> The revealed values.
	 */
	private function reveal(array $row, array $fields): array {
		$revealed = [];
		foreach ($fields as $field) {
			// An ABSENT value is absent, not null: "this row has no handler"
			// and "you may not see the handler" are different facts, and the
			// second is already reported in `refusedFields`.
			if (array_key_exists($field, $row) === true) {
				$revealed[$field] = $row[$field];
			}
		}

		return $revealed;
	}//end reveal()

	/**
	 * Normalise whatever the search answered into plain rows.
	 *
	 * The store answers a bare list on some reads and a paged envelope on
	 * others; reading one shape only is how a probe reports "nothing exists"
	 * about a register that answered.
	 *
	 * @param mixed $value The search answer.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(mixed $value): array {
		$value = $this->unwrapResults(value: $value);
		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			$row = $this->plainRow(row: $row);
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The `results` list of a paged envelope, or the value itself.
	 *
	 * @param mixed $value The search answer.
	 *
	 * @return mixed The list to read rows from.
	 */
	private function unwrapResults(mixed $value): mixed {
		if (is_array($value) === true && isset($value['results']) === true && is_array($value['results']) === true) {
			return $value['results'];
		}

		return $value;
	}//end unwrapResults()

	/**
	 * A serialisable row as its array, anything else as it is.
	 *
	 * @param mixed $row One entry of the search answer.
	 *
	 * @return mixed The row.
	 */
	private function plainRow(mixed $row): mixed {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return $row;
	}//end plainRow()

	/**
	 * An answer, shaped like every other one.
	 *
	 * A refused answer has `exists` FALSE and `refused` non-empty, and a
	 * caller must read the second: the first is not a claim about the
	 * register, it is the absence of one. The spec says so, and so does the
	 * sentence.
	 *
	 * @param array{register: string, schema: string} $target  The register and schema.
	 * @param array<string, mixed>                    $outcome The fields that differ from a refused, empty answer.
	 *
	 * @return array<string, mixed> The answer, keys in ANSWER_FIELDS order.
	 */
	private function answer(array $target, array $outcome): array {
		return array_replace(
			[
				'register' => $target['register'],
				'schema' => $target['schema'],
				'exists' => false,
				'matches' => 0,
				'revealed' => [],
				'refusedFields' => [],
				'refused' => '',
			],
			$outcome
		);
	}//end answer()
}//end class
