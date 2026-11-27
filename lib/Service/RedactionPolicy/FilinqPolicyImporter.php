<?php

/**
 * OpenRegister FilinqPolicyImporter: filinq's always and never lists as term rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Copies filinq's `publicationProhibition` objects to `always` term rules and its
 * entity-scoped `publicationConsent` objects to `never` term rules.
 *
 * Every rule is `layer: local` with `source` set to the filinq object's uuid,
 * and that uuid is the key: a second run updates the rule it wrote the first
 * time, and leaves an unchanged one unwritten. A document-scoped consent is a
 * decision about one document, not a standing rule, and is not imported.
 *
 * Both registers are read and written as the system, with RBAC and tenancy
 * off: the import is an operator act over every organisation's lists, and a
 * caller-scoped read would silently import only the caller's share of them.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */
class FilinqPolicyImporter {

	/**
	 * Slug of filinq's register.
	 *
	 * @var string
	 */
	public const FILINQ_REGISTER = 'filinq';

	/**
	 * filinq's always-redact list.
	 *
	 * @var string
	 */
	public const PROHIBITION = 'publicationProhibition';

	/**
	 * filinq's consents; those with `scope: entity` are its never-redact list.
	 *
	 * @var string
	 */
	public const CONSENT = 'publicationConsent';

	/**
	 * The match types a term rule accepts.
	 *
	 * @var array<int, string>
	 */
	private const MATCH_TYPES = ['exact', 'normalized', 'bsn', 'kvk'];

	/**
	 * The longest match value a term rule stores.
	 *
	 * @var int
	 */
	private const MAX_VALUE = 500;

	/**
	 * The objects read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Reads filinq's objects and writes the term rules.
	 */
	public function __construct(private readonly ObjectOperationsInterface $objectService) {
	}//end __construct()

	/**
	 * Run the import.
	 *
	 * @return array{filinq: bool, created: int, updated: int, unchanged: int, skipped: array<int, string>}
	 *     Whether a filinq register exists, the counts, and the uuids of filinq objects without a usable match rule.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
	 */
	public function import(): array {
		return $this->objectService->runAsSystem(fn (): array => $this->importAsSystem());
	}//end import()

	/**
	 * The import, run inside the system context.
	 *
	 * @return array{filinq: bool, created: int, updated: int, unchanged: int, skipped: array<int, string>} The result.
	 */
	private function importAsSystem(): array {
		$result = ['filinq' => false, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => []];
		if ($this->filinqRegisterExists() === false) {
			return $result;
		}

		$result['filinq'] = true;
		$existing         = $this->rulesBySource();

		$candidates = [];
		foreach ($this->objects(register: self::FILINQ_REGISTER, schema: self::PROHIBITION) as $row) {
			$candidates[] = [$row, 'always'];
		}

		foreach ($this->objects(register: self::FILINQ_REGISTER, schema: self::CONSENT) as $row) {
			if (($row['scope'] ?? null) === 'entity') {
				$candidates[] = [$row, 'never'];
			}
		}

		foreach ($candidates as [$row, $list]) {
			$rule = self::ruleFor(row: $row, list: $list);
			if ($rule === null) {
				$result['skipped'][] = self::uuidOf(row: $row);
				continue;
			}

			$outcome = $this->write(rule: $rule, existing: ($existing[$rule['source']] ?? null));
			$result[$outcome]++;
		}

		return $result;
	}//end importAsSystem()

	/**
	 * Whether this instance has a filinq register.
	 *
	 * @return bool False when no register has that slug.
	 */
	private function filinqRegisterExists(): bool {
		try {
			$this->objectService->setRegister(self::FILINQ_REGISTER);
		} catch (DoesNotExistException) {
			return false;
		}

		return true;
	}//end filinqRegisterExists()

	/**
	 * The term rules already imported, by the filinq uuid they came from.
	 *
	 * @return array<string, array<string, mixed>> The rules with their `@self`.
	 */
	private function rulesBySource(): array {
		$bySource = [];
		foreach ($this->objects(register: ObjectTermRuleSource::REGISTER, schema: ObjectTermRuleSource::SCHEMA) as $row) {
			$source = ($row['source'] ?? null);
			if (is_string($source) === true && $source !== '') {
				$bySource[$source] = $row;
			}
		}

		return $bySource;
	}//end rulesBySource()

	/**
	 * Every object of one register and schema, page by page, as arrays with their `@self`.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(string $register, string $schema): array {
		$objects = [];
		$offset  = 0;
		do {
			$page = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit'   => self::PAGE,
					'offset'  => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($page as $object) {
				if ($object instanceof ObjectEntity) {
					$objects[] = $object->jsonSerialize();
				} else if (is_array($object) === true) {
					$objects[] = $object;
				}
			}

			$offset  += self::PAGE;
			$fullPage = count($page) === self::PAGE;
		} while ($fullPage === true);

		return $objects;
	}//end objects()

	/**
	 * Create the rule, update it when it changed, or leave it.
	 *
	 * @param array<string, mixed>      $rule     The rule as it should be.
	 * @param array<string, mixed>|null $existing The rule imported earlier from the same filinq object.
	 *
	 * @return string 'created', 'updated' or 'unchanged'.
	 */
	private function write(array $rule, ?array $existing): string {
		if ($existing !== null && self::sameRule(rule: $rule, existing: $existing) === true) {
			return 'unchanged';
		}

		$uuid = null;
		if ($existing !== null) {
			$uuid = self::uuidOf(row: $existing);
		}

		$this->objectService->saveObject(
			object: $rule,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: ObjectTermRuleSource::REGISTER, schema: ObjectTermRuleSource::SCHEMA),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);

		if ($existing === null) {
			return 'created';
		}

		return 'updated';
	}//end write()

	/**
	 * Whether a stored rule already carries every field of the rule.
	 *
	 * @param array<string, mixed> $rule     The rule as it should be.
	 * @param array<string, mixed> $existing The stored rule.
	 *
	 * @return bool True when nothing would change.
	 */
	private static function sameRule(array $rule, array $existing): bool {
		foreach ($rule as $key => $value) {
			if (($existing[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end sameRule()

	/**
	 * The term rule a filinq object becomes, or null when it has no usable match rule.
	 *
	 * @param array<string, mixed> $row  The filinq object.
	 * @param string               $list 'always' for a prohibition, 'never' for a standing consent.
	 *
	 * @return array<string, mixed>|null The rule.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
	 */
	public static function ruleFor(array $row, string $list): ?array {
		$name = $row['entityText'] ?? null;
		if ($list === 'always') {
			$name = $row['primaryName'] ?? null;
		}

		$matchRules = self::matchRules(rules: ($row['matchRules'] ?? null), name: $name);
		$source     = self::uuidOf(row: $row);
		if ($matchRules === [] || $source === '') {
			return null;
		}

		$authority = $row['legalBasis'] ?? null;
		$reason    = $row['notes'] ?? null;
		if ($list === 'always') {
			$authority = $row['legalAuthority'] ?? null;
			$reason    = $row['reason'] ?? null;
		}

		$rule = [
			'list'       => $list,
			'entityType' => self::stringOrNull(value: ($row['entityType'] ?? null)),
			'matchRules' => $matchRules,
			'reason'     => self::stringOrNull(value: $reason),
			'legalAuthority' => self::stringOrNull(value: $authority),
			'bases'      => self::bases(bases: ($row['bases'] ?? null)),
			'validFrom'  => self::stringOrNull(value: ($row['validFrom'] ?? null)),
			'validUntil' => self::stringOrNull(value: ($row['validUntil'] ?? null)),
			'active'     => (($row['active'] ?? true) !== false),
			'scope'      => 'organisation',
			'layer'      => 'local',
			'source'     => $source,
		];

		return array_filter($rule, static fn (mixed $value): bool => $value !== null);
	}//end ruleFor()

	/**
	 * The object's match rules of the four types, or an exact match on its name when it has none.
	 *
	 * A prohibition that arrives without a usable match rule would otherwise be
	 * lost from the always list; its name is what filinq shows the officer.
	 *
	 * @param mixed $rules The object's matchRules.
	 * @param mixed $name  Its primary name (prohibition) or entity text (consent).
	 *
	 * @return array<int, array{type: string, value: string}> The match rules.
	 */
	private static function matchRules(mixed $rules, mixed $name): array {
		$kept = [];
		if (is_array($rules) === false) {
			$rules = [];
		}

		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$type  = ($rule['type'] ?? null);
			$value = ($rule['value'] ?? null);
			if (in_array($type, self::MATCH_TYPES, true) === true && is_string($value) === true && trim($value) !== '') {
				$kept[] = ['type' => $type, 'value' => mb_substr($value, 0, self::MAX_VALUE)];
			}
		}

		if ($kept === [] && is_string($name) === true && trim($name) !== '') {
			$kept[] = ['type' => 'exact', 'value' => mb_substr($name, 0, self::MAX_VALUE)];
		}

		return $kept;
	}//end matchRules()

	/**
	 * The grounds as a list of strings, or null when the object carries none.
	 *
	 * @param mixed $bases The object's bases.
	 *
	 * @return array<int, string>|null The grounds.
	 */
	private static function bases(mixed $bases): ?array {
		if (is_array($bases) === false) {
			return null;
		}

		$kept = array_values(array_filter($bases, static fn (mixed $basis): bool => is_string($basis) === true && $basis !== ''));
		if ($kept === []) {
			return null;
		}

		return $kept;
	}//end bases()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * The uuid of an object array.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string The uuid, '' when it has none.
	 */
	private static function uuidOf(array $row): string {
		$uuid = ($row['@self']['id'] ?? ($row['id'] ?? ''));

		if (is_string($uuid) === false) {
			return '';
		}

		return $uuid;
	}//end uuidOf()
}//end class
