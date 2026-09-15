<?php

/**
 * What a schema says when it says its objects are parties.
 *
 * The declaration is `x-openregister-party` on the schema's configuration.
 * It names the kind of party (person, organisation, or whatever the
 * instance calls them) and which properties carry the name, the addresses,
 * the indicators and the parent. Everything the party model reads off a
 * party object goes through this object, so a leaf app keeps its own
 * property names and OpenRegister keeps one model.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\Schema;

/**
 * The party declaration of one schema.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) An immutable value object with one
 *   reader per declared field. The count IS the annotation's field count, and
 *   collapsing them into a `get(string $key)` would trade a typed reader for a
 *   string nobody can check.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   `unionAddresses` is a value the
 *   schema declares, not a mode the caller picks. It is read off the annotation
 *   and carried; no call site passes a literal.
 */
class PartyDefinition {

	/**
	 * The kind a schema declares when it names none.
	 */
	public const DEFAULT_KIND = 'person';

	/**
	 * How deep an organisation tree may go when the schema names no bound.
	 */
	public const DEFAULT_MAX_DEPTH = 10;

	/**
	 * The three address kinds the corpus names. A schema may use others; these
	 * are the ones the outbound picker knows by name.
	 */
	public const ADDRESS_KINDS = ['correspondence', 'case', 'location'];

	/**
	 * Constructor.
	 *
	 * @param string $kind The kind of party objects of this schema are.
	 * @param string|null $nameProperty The property carrying the party's display name.
	 * @param string $addressesProperty The property carrying the addresses.
	 * @param string $indicatorsProperty The property carrying the indicators.
	 * @param string|null $parentProperty The property naming the parent party, organisations only.
	 * @param int $maxDepth How deep the parent chain may go.
	 * @param array<int, string> $survivingProperties Properties the merge keeps from the survivor.
	 * @param bool $unionAddresses Whether a merge unions both parties' addresses.
	 */
	public function __construct(
		private readonly string $kind = self::DEFAULT_KIND,
		private readonly ?string $nameProperty = null,
		private readonly string $addressesProperty = 'addresses',
		private readonly string $indicatorsProperty = 'indicators',
		private readonly ?string $parentProperty = null,
		private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
		private readonly array $survivingProperties = [],
		private readonly bool $unionAddresses = true,
	) {
	}//end __construct()

	/**
	 * Read a schema's declaration, null when the schema is not a party schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return self|null The declaration.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public static function fromSchema(Schema $schema): ?self {
		$annotation = $schema->getPartyAnnotation();
		if ($annotation === []) {
			return null;
		}

		return self::fromAnnotation(annotation: $annotation);
	}//end fromSchema()

	/**
	 * Read a declaration from the raw annotation.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-party` value.
	 *
	 * @return self The declaration, every key defaulted.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public static function fromAnnotation(array $annotation): self {
		$merge = ($annotation['merge'] ?? []);
		if (is_array($merge) === false) {
			$merge = [];
		}

		$surviving = [];
		foreach ((array)($merge['survivingProperties'] ?? []) as $property) {
			$property = trim((string)$property);
			if ($property !== '') {
				$surviving[] = $property;
			}
		}

		return new self(
			kind: (self::text(value: ($annotation['kind'] ?? null)) ?? self::DEFAULT_KIND),
			nameProperty: self::text(value: ($annotation['nameProperty'] ?? null)),
			addressesProperty: (self::text(value: ($annotation['addressesProperty'] ?? null)) ?? 'addresses'),
			indicatorsProperty: (self::text(value: ($annotation['indicatorsProperty'] ?? null)) ?? 'indicators'),
			parentProperty: self::text(value: ($annotation['parentProperty'] ?? null)),
			maxDepth: self::depth(value: ($annotation['maxDepth'] ?? null)),
			survivingProperties: $surviving,
			unionAddresses: (($merge['unionAddresses'] ?? true) !== false),
		);
	}//end fromAnnotation()

	/**
	 * The kind of party objects of this schema are.
	 *
	 * @return string The kind.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function kind(): string {
		return $this->kind;
	}//end kind()

	/**
	 * The property carrying the party's display name, null when undeclared.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function nameProperty(): ?string {
		return $this->nameProperty;
	}//end nameProperty()

	/**
	 * The property carrying the party's addresses.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addressesProperty(): string {
		return $this->addressesProperty;
	}//end addressesProperty()

	/**
	 * The property carrying the party's indicators.
	 *
	 * @return string The property name.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function indicatorsProperty(): string {
		return $this->indicatorsProperty;
	}//end indicatorsProperty()

	/**
	 * The property naming the parent party, null when the kind does not nest.
	 *
	 * @return string|null The property name.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function parentProperty(): ?string {
		return $this->parentProperty;
	}//end parentProperty()

	/**
	 * How deep the parent chain may go.
	 *
	 * @return int The bound, at least one.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function maxDepth(): int {
		return $this->maxDepth;
	}//end maxDepth()

	/**
	 * The properties a merge keeps from the surviving party.
	 *
	 * @return array<int, string> The property names, [] when the schema names none.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function survivingProperties(): array {
		return $this->survivingProperties;
	}//end survivingProperties()

	/**
	 * Whether a merge unions both parties' addresses onto the survivor.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function unionAddresses(): bool {
		return $this->unionAddresses;
	}//end unionAddresses()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()

	/**
	 * A positive depth bound, defaulted.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return int The bound.
	 */
	private static function depth(mixed $value): int {
		$depth = (int)($value ?? 0);
		if ($depth < 1) {
			return self::DEFAULT_MAX_DEPTH;
		}

		return $depth;
	}//end depth()
}//end class
