<?php

/**
 * The party record: its addresses, its indicators, and its place in a tree.
 *
 * A party is an object of a schema that declares `x-openregister-party`. It
 * needs no Nextcloud account: everything a melder carries — the name, the
 * addresses mail reaches them at, the indicators that travel with them —
 * lives on the record, and the schema says which property holds which.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Reads a party record and the things that hang off it.
 *
 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
class PartyService {

	/**
	 * The effects an indicator may declare.
	 *
	 * @var array<int, string>
	 */
	public const EFFECTS = ['warn', 'refuse-publication', 'refuse-send'];

	/**
	 * Party declarations by schema id, for the life of the request.
	 *
	 * @var array<string, PartyDefinition|null>
	 */
	private array $definitions = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The object layer a party record lives in.
	 * @param SchemaMapper $schemas The schemas, for the party declaration.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly SchemaMapper $schemas,
	) {
	}//end __construct()

	/**
	 * The party object with that uuid, null when there is none the caller may read.
	 *
	 * @param string $partyUuid The party's uuid.
	 *
	 * @return ObjectEntity|null The party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function find(string $partyUuid): ?ObjectEntity {
		if (trim($partyUuid) === '') {
			return null;
		}

		try {
			return $this->objects->find(id: $partyUuid, _render: false, _audit: false);
		} catch (Throwable) {
			return null;
		}
	}//end find()

	/**
	 * The party declaration of an object's schema, null when it is not a party.
	 *
	 * @param ObjectEntity $party The object.
	 *
	 * @return PartyDefinition|null The declaration.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function definitionFor(ObjectEntity $party): ?PartyDefinition {
		$schemaId = (string)($party->getSchema() ?? '');
		if ($schemaId === '') {
			return null;
		}

		if (array_key_exists($schemaId, $this->definitions) === true) {
			return $this->definitions[$schemaId];
		}

		$definition = null;
		try {
			$definition = PartyDefinition::fromSchema(schema: $this->schemas->find($schemaId));
		} catch (Throwable) {
			$definition = null;
		}

		$this->definitions[$schemaId] = $definition;

		return $definition;
	}//end definitionFor()

	/**
	 * Every schema on the instance that declares itself a party schema.
	 *
	 * @return array<int, array{schema: Schema, definition: PartyDefinition}> The party schemas.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function partySchemas(): array {
		$found = [];
		foreach ($this->schemas->findAll() as $schema) {
			$definition = PartyDefinition::fromSchema(schema: $schema);
			if ($definition !== null) {
				$found[] = ['schema' => $schema, 'definition' => $definition];
			}
		}

		return $found;
	}//end partySchemas()

	/**
	 * The addresses a party holds, each normalised to `{kind, type, value, label}`.
	 *
	 * An entry that is a bare string reads as a correspondence address, which
	 * is how a register that has always held one e-mail per party keeps
	 * working without being rewritten.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{kind: string, type: string, value: string, label: string|null}> The addresses.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addresses(ObjectEntity $party, ?PartyDefinition $definition = null): array {
		$definition ??= $this->definitionFor(party: $party);
		if ($definition === null) {
			return [];
		}

		$raw = ($party->getObject()[$definition->addressesProperty()] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$addresses = [];
		foreach ($raw as $entry) {
			$address = self::normaliseAddress(entry: $entry);
			if ($address !== null) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}//end addresses()

	/**
	 * The addresses of one kind, in declaration order.
	 *
	 * @param ObjectEntity $party The party.
	 * @param string $kind The address kind, `correspondence` / `case` / `location`.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{kind: string, type: string, value: string, label: string|null}> The addresses.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addressesOfKind(ObjectEntity $party, string $kind, ?PartyDefinition $definition = null): array {
		return array_values(
			array_filter(
				$this->addresses(party: $party, definition: $definition),
				static fn (array $address): bool => $address['kind'] === $kind
			)
		);
	}//end addressesOfKind()

	/**
	 * The indicators a party carries, each normalised to `{key, label, effect, note}`.
	 *
	 * An entry naming no effect warns: an indicator somebody wrote down is an
	 * indicator somebody meant, and the weakest effect is the safe reading.
	 * An entry naming an effect this version does not know also warns, rather
	 * than being dropped, for the same reason.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{key: string, label: string, effect: string, note: string|null}> The indicators.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function indicators(ObjectEntity $party, ?PartyDefinition $definition = null): array {
		$definition ??= $this->definitionFor(party: $party);
		if ($definition === null) {
			return [];
		}

		$raw = ($party->getObject()[$definition->indicatorsProperty()] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$indicators = [];
		foreach ($raw as $entry) {
			$indicator = self::normaliseIndicator(entry: $entry);
			if ($indicator !== null) {
				$indicators[] = $indicator;
			}
		}

		return $indicators;
	}//end indicators()

	/**
	 * The party holding that address, null when no party does.
	 *
	 * Inbound mail from any address a party holds must reach that party
	 * rather than create a second record. The full-text search narrows the
	 * candidates; the exact comparison over the party's own addresses decides,
	 * so a search that matches loosely never returns the wrong party.
	 *
	 * @param string $address The address as it arrived.
	 *
	 * @return ObjectEntity|null The party.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function resolveByAddress(string $address): ?ObjectEntity {
		$needle = self::normaliseValue(value: $address);
		if ($needle === '') {
			return null;
		}

		foreach ($this->partySchemas() as $party) {
			$candidates = [];
			try {
				$candidates = $this->objects->findAll(
					config: [
						'filters' => ['schema' => $party['schema']->getId()],
						'search' => $address,
						'limit' => 50,
					]
				);
			} catch (Throwable) {
				$candidates = [];
			}

			foreach ($candidates as $candidate) {
				if ($candidate instanceof ObjectEntity === false) {
					continue;
				}

				if ($this->holdsAddress(party: $candidate, needle: $needle, definition: $party['definition']) === true) {
					return $candidate;
				}
			}
		}//end foreach

		return null;
	}//end resolveByAddress()

	/**
	 * Refuse a parent that would close a cycle or push the tree past its bound.
	 *
	 * @param string $partyUuid The party being given a parent.
	 * @param string|null $parentUuid The proposed parent, null to clear.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the cycle or the depth.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function assertParentAllowed(string $partyUuid, ?string $parentUuid): void {
		if ($parentUuid === null || trim($parentUuid) === '') {
			return;
		}

		if ($parentUuid === $partyUuid) {
			throw new Exception('Party "' . $partyUuid . '" cannot be its own parent', 400);
		}

		$seen = [$partyUuid => true];
		$cursor = $parentUuid;
		$depth = 1;
		while ($cursor !== null && $cursor !== '') {
			if (isset($seen[$cursor]) === true) {
				throw new Exception(
					'Party "' . $parentUuid . '" cannot be the parent of "' . $partyUuid
					. '": it would close a cycle through "' . $cursor . '"',
					400
				);
			}

			$seen[$cursor] = true;
			$ancestor = $this->find(partyUuid: $cursor);
			if ($ancestor === null) {
				return;
			}

			$definition = $this->definitionFor(party: $ancestor);
			if ($definition === null || $definition->parentProperty() === null) {
				return;
			}

			if ($depth >= $definition->maxDepth()) {
				throw new Exception(
					'Party "' . $partyUuid . '" would sit deeper than the declared maximum of '
					. $definition->maxDepth(),
					400
				);
			}

			$cursor = self::text(value: ($ancestor->getObject()[$definition->parentProperty()] ?? null));
			$depth++;
		}//end while
	}//end assertParentAllowed()

	/**
	 * Whether a party holds that exact address.
	 *
	 * @param ObjectEntity $party The party.
	 * @param string $needle The normalised address.
	 * @param PartyDefinition $definition The declaration.
	 *
	 * @return bool True when one of its addresses matches.
	 */
	private function holdsAddress(ObjectEntity $party, string $needle, PartyDefinition $definition): bool {
		foreach ($this->addresses(party: $party, definition: $definition) as $address) {
			if (self::normaliseValue(value: $address['value']) === $needle) {
				return true;
			}
		}

		return false;
	}//end holdsAddress()

	/**
	 * One address entry, normalised, or null when it carries no value.
	 *
	 * @param mixed $entry A string or an array.
	 *
	 * @return array{kind: string, type: string, value: string, label: string|null}|null The address.
	 */
	private static function normaliseAddress(mixed $entry): ?array {
		if (is_string($entry) === true) {
			$entry = ['value' => $entry];
		}

		if (is_array($entry) === false) {
			return null;
		}

		$value = self::text(value: ($entry['value'] ?? ($entry['email'] ?? ($entry['address'] ?? null))));
		if ($value === null) {
			return null;
		}

		return [
			'kind' => (self::text(value: ($entry['kind'] ?? null)) ?? 'correspondence'),
			'type' => (self::text(value: ($entry['type'] ?? null)) ?? 'email'),
			'value' => $value,
			'label' => self::text(value: ($entry['label'] ?? null)),
		];
	}//end normaliseAddress()

	/**
	 * One indicator entry, normalised, or null when it names nothing.
	 *
	 * @param mixed $entry A string or an array.
	 *
	 * @return array{key: string, label: string, effect: string, note: string|null}|null The indicator.
	 */
	private static function normaliseIndicator(mixed $entry): ?array {
		if (is_string($entry) === true) {
			$entry = ['key' => $entry];
		}

		if (is_array($entry) === false) {
			return null;
		}

		$key = self::text(value: ($entry['key'] ?? null));
		if ($key === null) {
			return null;
		}

		$effect = (self::text(value: ($entry['effect'] ?? null)) ?? 'warn');
		if (in_array($effect, self::EFFECTS, true) === false) {
			$effect = 'warn';
		}

		return [
			'key' => $key,
			'label' => (self::text(value: ($entry['label'] ?? null)) ?? $key),
			'effect' => $effect,
			'note' => self::text(value: ($entry['note'] ?? null)),
		];
	}//end normaliseIndicator()

	/**
	 * An address value reduced to what a comparison should care about.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The normalised value.
	 */
	private static function normaliseValue(string $value): string {
		return mb_strtolower(trim($value));
	}//end normaliseValue()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
