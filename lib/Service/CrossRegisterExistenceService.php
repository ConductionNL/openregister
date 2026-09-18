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
 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answer, per probe, whether a row exists and how many.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md
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
	 * @param ObjectService   $objects The object service, used with RBAC ON.
	 * @param SchemaMapper    $schemas The schema store, for what a field may be.
	 * @param LoggerInterface $logger  The logger.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly SchemaMapper $schemas,
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
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function probe(array $probes): array {
		if (count($probes) > self::MAX_PROBES) {
			return [
				'error' => 'too-many-probes',
				'message' => 'A call carries at most ' . self::MAX_PROBES . ' probes; this one carried '
					. count($probes) . '. Nothing was queried.',
			];
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
	 * Answer one probe.
	 *
	 * @param array<string, mixed> $probe The probe.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	private function one(array $probe): array {
		$register = trim((string)($probe['register'] ?? ''));
		$schema = trim((string)($probe['schema'] ?? ''));
		$filters = ($probe['filters'] ?? []);
		$reveal = ($probe['reveal'] ?? []);

		if ($register === '' || $schema === '' || is_array($filters) === false || $filters === []) {
			return $this->answer(
				register: $register,
				schema: $schema,
				refused: 'A probe names a register, a schema and at least one filter.',
			);
		}

		$wanted = [];
		if (is_array($reveal) === true) {
			$wanted = $reveal;
		}

		[$allowed, $refusedFields] = $this->narrowReveal(
			schema: $schema,
			reveal: $wanted
		);

		try {
			// RBAC ON, which is the whole authorisation (D-4): this endpoint
			// can never answer about a register the caller could not have
			// searched, because it asks the same way a search does.
			$rows = $this->objects->searchObjects(
				query: array_merge(
					$filters,
					['@self' => ['register' => $register, 'schema' => $schema], '_limit' => self::MAX_COUNT]
				),
				_rbac: true,
				_multitenancy: true,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[CrossRegisterExistenceService] probe of ' . $register . '/' . $schema
					. ' was refused: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $this->answer(
				register: $register,
				schema: $schema,
				refusedFields: $refusedFields,
				refused: 'This probe was refused. It is not an answer about whether a row exists.',
			);
		}//end try

		$rows = $this->rowsOf(value: $rows);

		// FIELD BY FIELD, from the first match only. Nothing of the row travels
		// but the values a caller named and the schema allowed.
		$revealed = [];
		if ($rows !== []) {
			$revealed = $this->reveal(row: $rows[0], fields: $allowed);
		}

		return [
			'register' => $register,
			'schema' => $schema,
			'exists' => ($rows !== []),
			'matches' => count($rows),
			// FIELD BY FIELD, from the first match only. Nothing of the row
			// travels but the values a caller named and the schema allowed.
			'revealed' => $revealed,
			'refusedFields' => $refusedFields,
			'refused' => '',
		];
	}//end one()

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
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-revealed-fields-are-bounded-by-the-schema-not-by-the-caller
	 */
	private function narrowReveal(string $schema, array $reveal): array {
		if ($reveal === []) {
			return [[], []];
		}

		$declared = [];
		$withheld = [];
		try {
			$found = $this->schemas->find($schema);
			$declared = array_keys($found->getProperties());
			$withheld = array_merge(
				$found->getWriteOnlyProperties(),
				array_keys($found->getPropertiesWithAuthorization())
			);
		} catch (Throwable $e) {
			// An unreadable schema allows NOTHING, which fails closed: the
			// alternative is revealing a field nobody could check the rules for.
			return [[], array_values(array_map(static fn ($f): string => (string)$f, $reveal))];
		}

		$allowed = [];
		$refused = [];
		foreach ($reveal as $field) {
			$field = trim((string)$field);
			if ($field === '') {
				continue;
			}

			if (in_array($field, $declared, true) === false || in_array($field, $withheld, true) === true) {
				$refused[] = $field;
				continue;
			}

			$allowed[] = $field;
		}

		return [$allowed, $refused];
	}//end narrowReveal()

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
		if (is_array($value) === true && isset($value['results']) === true && is_array($value['results']) === true) {
			$value = $value['results'];
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * A refused answer, shaped like every other one.
	 *
	 * `exists` is FALSE and `refused` is non-empty, and a caller must read the
	 * second: the first is not a claim about the register, it is the absence of
	 * one. The spec says so, and so does the sentence.
	 *
	 * @param string             $register      The register.
	 * @param string             $schema        The schema.
	 * @param array<int, string> $refusedFields Fields narrowed out.
	 * @param string             $refused       Why the probe was refused.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function answer(
		string $register,
		string $schema,
		array $refusedFields = [],
		string $refused = '',
	): array {
		return [
			'register' => $register,
			'schema' => $schema,
			'exists' => false,
			'matches' => 0,
			'revealed' => [],
			'refusedFields' => $refusedFields,
			'refused' => $refused,
		];
	}//end answer()
}//end class
