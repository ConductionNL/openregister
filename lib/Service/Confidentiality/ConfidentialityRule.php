<?php

/**
 * ConfidentialityRule: the read rule a confidentiality block derives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Confidentiality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Confidentiality;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Turns an `x-openregister-confidentiality` block into read conditions.
 *
 * An object is readable when the caller holds the group its tier requires,
 * or when its release moment has passed. A tier absent from `clearance`
 * inherits the requirement of the nearest less restrictive tier that names
 * one; a tier with none below it needs no group. An unset tier, or a value
 * that is not one of `tiers`, counts as the least restrictive tier.
 *
 * A block with faults FAILS CLOSED: only objects without a tier stay
 * readable, until the block is fixed. This is the opposite of the advisory
 * annotations (quality, dedup, survivorship), deliberately: this block decides
 * who reads what. Do not "simplify" it to fail open for consistency.
 *
 * Owner and admin bypass are not decided here; the enforcement point that
 * consumes these conditions applies them as it does for every authored rule.
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
 */
final class ConfidentialityRule {

	/**
	 * The pseudo-group of an authored rule that every caller qualifies for.
	 */
	public const EVERYONE = 'public';

	/**
	 * Constructor.
	 *
	 * @param string|null               $tierProperty      The property carrying the tier, null when unknown.
	 * @param array<int, string>        $tiers             The tiers, least restrictive first.
	 * @param array<string, string|null> $required          The group each tier requires, null for none.
	 * @param string|null               $groundProperty    The property carrying the legal ground.
	 * @param string|null               $releaseAtProperty The property carrying the release moment.
	 * @param array<int, string>        $errors            The faults that made this rule fail closed.
	 */
	private function __construct(
		private readonly ?string $tierProperty,
		private readonly array $tiers,
		private readonly array $required,
		private readonly ?string $groundProperty,
		private readonly ?string $releaseAtProperty,
		private readonly array $errors,
	) {
	}//end __construct()

	/**
	 * The rule of a block, failing closed when the block has faults.
	 *
	 * @param mixed                $block      The block as written.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-malformed-confidentiality-annotation-must-fail-closed-not-silently-permit-access
	 */
	public static function fromBlock(mixed $block, array $properties): self {
		$errors = (new ConfidentialityAnnotationValidator())->validate(block: $block, properties: $properties);
		if ($errors !== []) {
			$tierProperty = null;
			if (is_array($block) === true && is_string($block['tierProperty'] ?? null) === true && $block['tierProperty'] !== '') {
				$tierProperty = $block['tierProperty'];
			}

			return new self(
				tierProperty: $tierProperty,
				tiers: [],
				required: [],
				groundProperty: null,
				releaseAtProperty: null,
				errors: $errors
			);
		}

		return new self(
			tierProperty: $block['tierProperty'],
			tiers: $block['tiers'],
			required: self::inheritedRequirements(tiers: $block['tiers'], clearance: (array)($block['clearance'] ?? [])),
			groundProperty: self::optionalName(value: ($block['groundProperty'] ?? null)),
			releaseAtProperty: self::optionalName(value: ($block['releaseAtProperty'] ?? null)),
			errors: []
		);
	}//end fromBlock()

	/**
	 * Whether the block had faults and the rule fails closed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-malformed-confidentiality-annotation-must-fail-closed-not-silently-permit-access
	 */
	public function failsClosed(): bool {
		return $this->errors !== [];
	}//end failsClosed()

	/**
	 * The faults that made the rule fail closed.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-a-malformed-confidentiality-annotation-must-fail-closed-not-silently-permit-access
	 */
	public function errors(): array {
		return $this->errors;
	}//end errors()

	/**
	 * The group a tier requires, after inheritance; null when none.
	 *
	 * An unknown tier counts as the least restrictive one.
	 *
	 * @param string|null $tier The tier.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-schemas-must-be-able-to-declare-a-confidentiality-classification-via-x-openregister-confidentiality
	 */
	public function requiredGroup(?string $tier): ?string {
		return ($this->required[$this->effectiveTier(tier: $tier)] ?? null);
	}//end requiredGroup()

	/**
	 * The read conditions in the authored `{group, match}` rule shape, any one admitting.
	 *
	 * The release condition compares with `$now`, the dynamic value
	 * MagicRbacHandler and ConditionMatcher already resolve.
	 *
	 * @return array<int, array{group: string, match: array<string, mixed>}>
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
	 */
	public function readRules(): array {
		if ($this->tierProperty === null) {
			return [];
		}

		$property = $this->tierProperty;
		$rules    = [['group' => self::EVERYONE, 'match' => [$property => ['$exists' => false]]]];
		if ($this->failsClosed() === true) {
			$rules[] = ['group' => self::EVERYONE, 'match' => [$property => ['$eq' => '']]];
			return $rules;
		}

		$rules[] = ['group' => self::EVERYONE, 'match' => [$property => ['$nin' => $this->tiers]]];
		foreach ($this->tiersByGroup() as $group => $tiers) {
			$rules[] = ['group' => $group, 'match' => [$property => ['$in' => $tiers]]];
		}

		if ($this->releaseAtProperty !== null) {
			$rules[] = ['group' => self::EVERYONE, 'match' => [$this->releaseAtProperty => ['$lte' => '$now']]];
		}

		return $rules;
	}//end readRules()

	/**
	 * Whether a caller holding these groups may read this object.
	 *
	 * @param array<string, mixed> $object The object's data.
	 * @param array<int, string>   $groups The caller's groups.
	 * @param DateTimeInterface    $now    The current moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
	 */
	public function admits(array $object, array $groups, DateTimeInterface $now): bool {
		$tier = $this->tierOf(object: $object);
		if ($tier === null) {
			return $this->tierProperty !== null;
		}

		if ($this->failsClosed() === true) {
			return false;
		}

		$group = $this->requiredGroup(tier: $tier);
		if ($group === null || in_array($group, $groups, true) === true) {
			return true;
		}

		return $this->isReleased(object: $object, now: $now);
	}//end admits()

	/**
	 * The resolved classification of an object, as `@self.confidentiality` shows it.
	 *
	 * @param array<string, mixed> $object The object's data.
	 * @param DateTimeInterface    $now    The current moment.
	 *
	 * @return array{tier: string|null, ground: mixed, releaseAt: mixed, released: bool}
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-the-resolved-classification-must-be-exposed-on-render-as-selfconfidentiality
	 */
	public function describe(array $object, DateTimeInterface $now): array {
		$ground = null;
		if ($this->groundProperty !== null) {
			$ground = ($object[$this->groundProperty] ?? null);
		}

		$releaseAt = null;
		if ($this->releaseAtProperty !== null) {
			$releaseAt = ($object[$this->releaseAtProperty] ?? null);
		}

		$tier = null;
		if ($this->failsClosed() === false) {
			$tier = $this->effectiveTier(tier: $this->tierOf(object: $object));
		}

		return [
			'tier' => $tier,
			'ground' => $ground,
			'releaseAt' => $releaseAt,
			'released' => $this->isReleased(object: $object, now: $now),
		];
	}//end describe()

	/**
	 * Whether the object's release moment is set and has passed.
	 *
	 * @param array<string, mixed> $object The object's data.
	 * @param DateTimeInterface    $now    The current moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
	 */
	public function isReleased(array $object, DateTimeInterface $now): bool {
		if ($this->releaseAtProperty === null || $this->failsClosed() === true) {
			return false;
		}

		$value = ($object[$this->releaseAtProperty] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return false;
		}

		try {
			$releaseAt = new DateTimeImmutable($value);
		} catch (Throwable) {
			return false;
		}

		return $releaseAt <= $now;
	}//end isReleased()

	/**
	 * The object's tier value, null when it carries none.
	 *
	 * @param array<string, mixed> $object The object's data.
	 *
	 * @return string|null
	 */
	private function tierOf(array $object): ?string {
		if ($this->tierProperty === null) {
			return null;
		}

		$value = ($object[$this->tierProperty] ?? null);
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end tierOf()

	/**
	 * The tier itself when it is one of the tiers, else the least restrictive.
	 *
	 * @param string|null $tier The tier value.
	 *
	 * @return string|null
	 */
	private function effectiveTier(?string $tier): ?string {
		if ($tier !== null && in_array($tier, $this->tiers, true) === true) {
			return $tier;
		}

		return ($this->tiers[0] ?? null);
	}//end effectiveTier()

	/**
	 * The tiers each group admits to, for the tiers that require a group.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function tiersByGroup(): array {
		$byGroup = [];
		foreach ($this->tiers as $tier) {
			$group = ($this->required[$tier] ?? null);
			if ($group === null) {
				continue;
			}

			$byGroup[$group][] = $tier;
		}

		$open = array_values(array_filter($this->tiers, fn (string $tier): bool => ($this->required[$tier] ?? null) === null));
		if ($open !== []) {
			$byGroup = [self::EVERYONE => $open] + $byGroup;
		}

		return $byGroup;
	}//end tiersByGroup()

	/**
	 * Each tier's group, a tier absent from clearance taking the nearest less restrictive one's.
	 *
	 * @param array<int, string>   $tiers     The tiers, least restrictive first.
	 * @param array<string, mixed> $clearance The clearance map.
	 *
	 * @return array<string, string|null>
	 */
	private static function inheritedRequirements(array $tiers, array $clearance): array {
		$required = [];
		$current  = null;
		foreach ($tiers as $tier) {
			if (is_string($clearance[$tier] ?? null) === true) {
				$current = trim($clearance[$tier]);
			}

			$required[$tier] = $current;
		}

		return $required;
	}//end inheritedRequirements()

	/**
	 * A property name, or null when none is written.
	 *
	 * @param mixed $value The entry.
	 *
	 * @return string|null
	 */
	private static function optionalName(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end optionalName()
}//end class
