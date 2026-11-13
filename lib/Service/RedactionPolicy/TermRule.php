<?php

/**
 * TermRule: one always or never-redact rule, read from a termRule object.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

use DateTimeImmutable;
use Exception;

/**
 * A term rule as the matcher reads it.
 *
 * Built only for a rule that can match now: active, inside its time bounds,
 * with a uuid and at least one well-formed match rule of a known type.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
final class TermRule {

	public const LIST_ALWAYS = 'always';
	public const LIST_NEVER = 'never';

	/**
	 * The match types a rule may use.
	 *
	 * @var array<int, string>
	 */
	public const MATCH_TYPES = ['exact', 'normalized', 'bsn', 'kvk'];

	/**
	 * Constructor.
	 *
	 * @param string                                          $uuid       The object's uuid.
	 * @param string                                          $list       always or never.
	 * @param string|null                                     $entityType The entity type, or null for any.
	 * @param array<int, array{type: string, value: string}> $matchRules The match rules.
	 * @param array<int, string>                              $bases      The ground identifiers.
	 * @param string|null                                     $requestRef For a request-scoped rule: its request.
	 * @param string|null                                     $slug       The object's slug.
	 * @param string                                          $layer      base or local.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $list,
		public readonly ?string $entityType,
		public readonly array $matchRules,
		public readonly array $bases=[],
		public readonly ?string $requestRef=null,
		public readonly ?string $slug=null,
		public readonly string $layer='local',
	) {
	}//end __construct()

	/**
	 * The rule a termRule object holds, or null when it cannot match at this moment.
	 *
	 * @param array<string, mixed> $object The object (with `@self` or top-level uuid/slug).
	 * @param DateTimeImmutable    $now    The moment matched at.
	 *
	 * @return self|null
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public static function fromObject(array $object, DateTimeImmutable $now): ?self {
		$uuid       = self::identity(object: $object, key: 'uuid');
		$list       = ($object['list'] ?? null);
		$matchRules = self::wellFormed(matchRules: ($object['matchRules'] ?? null));
		if ($uuid === null || in_array($list, [self::LIST_ALWAYS, self::LIST_NEVER], true) === false || $matchRules === []) {
			return null;
		}

		if (($object['active'] ?? true) !== true || self::isOpen(object: $object, now: $now) === false) {
			return null;
		}

		$requestRef = self::requestRef(object: $object);
		if ($requestRef === '') {
			// A request-scoped rule without a request applies to no call.
			return null;
		}

		$entityType = trim((string) ($object['entityType'] ?? ''));
		if ($entityType === '' || $entityType === 'OTHER') {
			$entityType = null;
		}

		return new self(
			uuid: $uuid,
			list: $list,
			entityType: $entityType,
			matchRules: $matchRules,
			bases: array_values(array_map('strval', array_filter((array) ($object['bases'] ?? []), 'is_scalar'))),
			requestRef: $requestRef,
			slug: self::identity(object: $object, key: 'slug'),
			layer: (string) ($object['layer'] ?? 'local')
		);
	}//end fromObject()

	/**
	 * Whether the rule applies to a call for this request (null: no request).
	 *
	 * @param string|null $requestRef The call's policyContext.requestRef.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-002
	 */
	public function appliesTo(?string $requestRef): bool {
		return $this->requestRef === null || $this->requestRef === $requestRef;
	}//end appliesTo()

	/**
	 * Whether the rule covers a detected entity type.
	 *
	 * @param string $entityType The detected type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function covers(string $entityType): bool {
		return $this->entityType === null || strcasecmp($this->entityType, $entityType) === 0;
	}//end covers()

	/**
	 * The request a rule is scoped to: null for an organisation-wide rule,
	 * empty for a request-scoped rule that names no request.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string|null
	 */
	private static function requestRef(array $object): ?string {
		if (($object['scope'] ?? 'organisation') !== 'request') {
			return null;
		}

		return (string) ($object['requestRef'] ?? '');
	}//end requestRef()

	/**
	 * The uuid or slug of an object, from `@self` first, else the top level.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $key    uuid or slug.
	 *
	 * @return string|null
	 */
	private static function identity(array $object, string $key): ?string {
		$self  = (array) ($object['@self'] ?? []);
		$value = ($self[$key] ?? $object[$key] ?? null);
		if ($key === 'uuid') {
			$value = ($self['uuid'] ?? $self['id'] ?? $object['uuid'] ?? $object['id'] ?? null);
		}

		if (is_scalar($value) === false || (string) $value === '') {
			return null;
		}

		return (string) $value;
	}//end identity()

	/**
	 * The well-formed match rules of a known type.
	 *
	 * @param mixed $matchRules The stored match rules.
	 *
	 * @return array<int, array{type: string, value: string}>
	 */
	private static function wellFormed(mixed $matchRules): array {
		$rules = [];
		foreach ((array) $matchRules as $rule) {
			if (is_array($rule) === true
				&& in_array(($rule['type'] ?? null), self::MATCH_TYPES, true) === true
				&& is_scalar($rule['value'] ?? null) === true
				&& (string) $rule['value'] !== ''
			) {
				$rules[] = ['type' => (string) $rule['type'], 'value' => (string) $rule['value']];
			}
		}

		return $rules;
	}//end wellFormed()

	/**
	 * Whether now falls inside the rule's validFrom and validUntil (an unreadable bound closes it).
	 *
	 * @param array<string, mixed> $object The object.
	 * @param DateTimeImmutable    $now    The moment.
	 *
	 * @return bool
	 */
	private static function isOpen(array $object, DateTimeImmutable $now): bool {
		try {
			$from = (string) ($object['validFrom'] ?? '');
			if ($from !== '' && new DateTimeImmutable($from) > $now) {
				return false;
			}

			$until = (string) ($object['validUntil'] ?? '');
			if ($until !== '' && new DateTimeImmutable($until) < $now) {
				return false;
			}
		} catch (Exception) {
			return false;
		}

		return true;
	}//end isOpen()
}//end class
