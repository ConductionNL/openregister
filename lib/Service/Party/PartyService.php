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
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * Reads a party record and the things that hang off it.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class answers everything
 *   a party record is asked: its declaration, its addresses, its indicators,
 *   its place in the tree, and which party an inbound address belongs to. Each
 *   reads the same declaration, so splitting them would mean four classes
 *   resolving it four times and able to disagree.
 * @SuppressWarnings(PHPMD.StaticAccess) `PartyDefinition::fromSchema()` is the
 *   value object's named constructor; there is no instance to call it on.
 */
class PartyService implements TrustedPartyReaderInterface, PartySchemaCatalogueInterface {

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
	 * The register and schema ids of every party table, for the life of the request.
	 *
	 * @var array<int, array{register: int, schema: int}>|null
	 */
	private ?array $partyTables = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object layer a party record lives in.
	 * @param SchemaCatalogInterface    $catalog The schemas, for the party declaration, and the registers
	 *                                           carrying a party schema, so a lookup reads only the party tables.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaCatalogInterface $catalog,
	) {
	}//end __construct()

	/**
	 * The party object with that uuid, null when there is none the caller may read.
	 *
	 * @param string $partyUuid The party's uuid.
	 *
	 * @return ObjectEntity|null The party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function find(string $partyUuid): ?ObjectEntity {
		if (trim($partyUuid) === '') {
			return null;
		}

		// The party tables first: an id alone makes the object layer search every
		// magic table on the instance, which took seconds per party.
		foreach ($this->partyTables() as $table) {
			$found = $this->findInTable(partyUuid: $partyUuid, table: $table);
			if ($found !== null) {
				return $found;
			}
		}

		return $this->findOnInstance(partyUuid: $partyUuid);
	}//end find()

	/**
	 * The object with that uuid anywhere on the instance, null when there is none the caller may read.
	 *
	 * @param string $partyUuid The party's uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function findOnInstance(string $partyUuid): ?ObjectEntity {
		try {
			return $this->objects->find(id: $partyUuid, options: new ObjectFindOptions(render: false, audit: false));
		} catch (Throwable) {
			return null;
		}
	}//end findOnInstance()

	/**
	 * The party with that uuid in one party table, null when that table does not hold it.
	 *
	 * @param string $partyUuid The party's uuid.
	 * @param array{register: int, schema: int} $table The table.
	 *
	 * @return ObjectEntity|null The party.
	 */
	private function findInTable(string $partyUuid, array $table): ?ObjectEntity {
		try {
			return $this->objects->find(
				id: $partyUuid,
				options: new ObjectFindOptions(register: $table['register'], schema: $table['schema'], render: false, audit: false)
			);
		} catch (Throwable) {
			return null;
		}
	}//end findInTable()

	/**
	 * The register and schema ids of every table that holds party records.
	 *
	 * @return array<int, array{register: int, schema: int}> The tables.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-is-looked-up-in-the-party-tables-first
	 */
	private function partyTables(): array {
		if ($this->partyTables !== null) {
			return $this->partyTables;
		}

		$this->partyTables = $this->readPartyTables(registers: $this->catalog->registers());

		return $this->partyTables;
	}//end partyTables()

	/**
	 * Every register/schema pair holding a party schema; none when the
	 * registers cannot be read.
	 *
	 * @param RegisterMapperInterface $registers The registers.
	 *
	 * @return array<int, array{register: int, schema: int}> The tables.
	 */
	private function readPartyTables(RegisterMapperInterface $registers): array {
		$tables = [];
		try {
			foreach ($this->partySchemas() as $party) {
				$schemaId = (int)$party['schema']->getId();
				foreach ($registers->getAllRegisterIdsWithSchema(schemaId: $schemaId) as $registerId) {
					$tables[] = ['register' => (int)$registerId, 'schema' => $schemaId];
				}
			}
		} catch (Throwable) {
			$tables = [];
		}

		return $tables;
	}//end readPartyTables()

	/**
	 * The object with that uuid, read without RBAC or multitenancy; null when there is none.
	 *
	 * For a trusted write with no user to read as, such as an occ example-set
	 * install: there `find` answers a schema that refuses "Anonymous" as null.
	 * Never for a request, which reads with its caller's rights through `find`.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @spec openspec/changes/example-set-install-agrees-with-the-store/specs/example-sets/spec.md
	 */
	public function findUnscoped(string $uuid): ?ObjectEntity {
		if (trim($uuid) === '') {
			return null;
		}

		try {
			return $this->objects->find(id: $uuid, options: new ObjectFindOptions(render: false, audit: false), _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}
	}//end findUnscoped()

	/**
	 * The party declaration of an object's schema, null when it is not a party.
	 *
	 * @param ObjectEntity $party The object.
	 *
	 * @return PartyDefinition|null The declaration.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
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
			$definition = PartyDefinition::fromSchema(schema: $this->catalog->schemas()->find($schemaId));
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function partySchemas(): array {
		$found = [];
		foreach ($this->catalog->schemas()->findAll() as $schema) {
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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addresses(ObjectEntity $party, ?PartyDefinition $definition = null): array {
		$definition ??= $this->definitionFor(party: $party);
		if ($definition === null) {
			return [];
		}

		return self::normaliseEach(
			raw: ($party->getObject()[$definition->addressesProperty()] ?? null),
			normalise: self::normaliseAddress(...)
		);
	}//end addresses()

	/**
	 * The addresses of one kind, in declaration order.
	 *
	 * @param ObjectEntity          $party     The party.
	 * @param PartyAddressSelection $selection The address kind (`correspondence` / `case` / `location`) and the declaration,
	 *                                         resolved when null.
	 *
	 * @return array<int, array{kind: string, type: string, value: string, label: string|null}> The addresses.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addressesOfKind(ObjectEntity $party, PartyAddressSelection $selection): array {
		return array_values(
			array_filter(
				$this->addresses(party: $party, definition: $selection->definition),
				static fn (array $address): bool => $address['kind'] === $selection->kind
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
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function indicators(ObjectEntity $party, ?PartyDefinition $definition = null): array {
		$definition ??= $this->definitionFor(party: $party);
		if ($definition === null) {
			return [];
		}

		return self::normaliseEach(
			raw: ($party->getObject()[$definition->indicatorsProperty()] ?? null),
			normalise: self::normaliseIndicator(...)
		);
	}//end indicators()

	/**
	 * Normalise every entry of a stored list, dropping the malformed ones; a
	 * value that is not a list holds none.
	 *
	 * @param mixed $raw The stored value.
	 * @param callable(mixed): (array<string, mixed>|null) $normalise The entry normaliser.
	 *
	 * @return array<int, array<string, mixed>> The normalised entries.
	 */
	private static function normaliseEach(mixed $raw, callable $normalise): array {
		if (is_array($raw) === false) {
			return [];
		}

		$entries = [];
		foreach ($raw as $entry) {
			$normalised = $normalise($entry);
			if ($normalised !== null) {
				$entries[] = $normalised;
			}
		}

		return $entries;
	}//end normaliseEach()

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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function resolveByAddress(string $address): ?ObjectEntity {
		$needle = self::normaliseValue(value: $address);
		if ($needle === '') {
			return null;
		}

		foreach ($this->partySchemas() as $party) {
			$holder = $this->firstHolder(
				candidates: $this->addressCandidates(schema: $party['schema'], address: $address),
				lookup: new PartyAddressLookup(needle: $needle, definition: $party['definition'])
			);
			if ($holder !== null) {
				return $holder;
			}
		}

		return null;
	}//end resolveByAddress()

	/**
	 * The objects of one party schema a search for the address finds; none
	 * when the search fails.
	 *
	 * @param Schema $schema The party schema.
	 * @param string $address The address as given.
	 *
	 * @return array<int, mixed> The candidates.
	 */
	private function addressCandidates(Schema $schema, string $address): array {
		try {
			return $this->objects->findAll(
				config: [
					'filters' => ['schema' => $schema->getId()],
					'search' => $address,
					'limit' => 50,
				]
			);
		} catch (Throwable) {
			return [];
		}
	}//end addressCandidates()

	/**
	 * The first candidate that holds the address exactly.
	 *
	 * @param array<int, mixed> $candidates The search results.
	 * @param PartyAddressLookup $lookup The normalised address and the schema's party declaration.
	 *
	 * @return ObjectEntity|null The holder.
	 */
	private function firstHolder(array $candidates, PartyAddressLookup $lookup): ?ObjectEntity {
		foreach ($candidates as $candidate) {
			if ($candidate instanceof ObjectEntity === false) {
				continue;
			}

			if ($this->holdsAddress(party: $candidate, lookup: $lookup) === true) {
				return $candidate;
			}
		}

		return null;
	}//end firstHolder()

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
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function assertParentAllowed(string $partyUuid, ?string $parentUuid): void {
		if ($parentUuid === null || trim($parentUuid) === '') {
			return;
		}

		if ($parentUuid === $partyUuid) {
			throw new Exception('Party "' . $partyUuid . '" cannot be its own parent', 400);
		}

		$this->assertNoCycle(partyUuid: $partyUuid, parentUuid: $parentUuid);
	}//end assertParentAllowed()

	/**
	 * Walk up from the proposed parent and refuse a chain that returns to a
	 * party already seen, or that runs deeper than the declared bound.
	 *
	 * @param string $partyUuid The party.
	 * @param string $parentUuid The proposed parent.
	 *
	 * @return void
	 *
	 * @throws Exception 400 when the parent would close a cycle or exceed the depth.
	 */
	private function assertNoCycle(string $partyUuid, string $parentUuid): void {
		$chain  = [$partyUuid];
		$cursor = $parentUuid;
		while ($cursor !== null && $cursor !== '') {
			if (in_array($cursor, $chain, true) === true) {
				throw new Exception(
					'Party "' . $parentUuid . '" cannot be the parent of "' . $partyUuid
					. '": it would close a cycle through "' . $cursor . '"',
					400
				);
			}

			$chain[] = $cursor;
			$cursor  = $this->parentOf(chain: $chain);
		}//end while
	}//end assertNoCycle()

	/**
	 * One step up the parent chain, or null when the walk ends here.
	 *
	 * The walk ends at a party the caller cannot read and at a party whose
	 * schema declares no parent: both mean there is no further edge this
	 * instance can follow, and neither is a cycle.
	 *
	 * The chain starts with the party the walk started from, named in the
	 * message, and ends with the party being stepped over; the steps taken
	 * are the chain's length less that first party.
	 *
	 * @param array<int, string> $chain The parties walked so far, the party itself first.
	 *
	 * @return string|null The next parent, or null when the chain ends.
	 *
	 * @throws Exception 400 when the chain is longer than the schema allows.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	private function parentOf(array $chain): ?string {
		$ancestor = $this->find(partyUuid: $chain[(count($chain) - 1)]);
		if ($ancestor === null) {
			return null;
		}

		$definition = $this->nestingDefinitionOf(party: $ancestor);
		if ($definition === null) {
			return null;
		}

		if ((count($chain) - 1) >= $definition->maxDepth()) {
			throw new Exception(
				'Party "' . $chain[0] . '" would sit deeper than the declared maximum of '
				. $definition->maxDepth(),
				400
			);
		}

		return self::text(value: ($ancestor->getObject()[$definition->parentProperty()] ?? null));
	}//end parentOf()

	/**
	 * The party's declaration when it declares a parent property, else null.
	 *
	 * @param ObjectEntity $party The party.
	 *
	 * @return PartyDefinition|null The declaration.
	 */
	private function nestingDefinitionOf(ObjectEntity $party): ?PartyDefinition {
		$definition = $this->definitionFor(party: $party);
		if ($definition === null || $definition->parentProperty() === null) {
			return null;
		}

		return $definition;
	}//end nestingDefinitionOf()

	/**
	 * Whether a party holds that exact address.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyAddressLookup $lookup The normalised address and the declaration.
	 *
	 * @return bool True when one of its addresses matches.
	 */
	private function holdsAddress(ObjectEntity $party, PartyAddressLookup $lookup): bool {
		foreach ($this->addresses(party: $party, definition: $lookup->definition) as $address) {
			if (self::normaliseValue(value: $address['value']) === $lookup->needle) {
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

		return [
			'key' => $key,
			'label' => (self::text(value: ($entry['label'] ?? null)) ?? $key),
			'effect' => self::effectOf(entry: $entry),
			'note' => self::text(value: ($entry['note'] ?? null)),
		];
	}//end normaliseIndicator()

	/**
	 * An indicator's declared effect; one naming none, or one this version
	 * does not know, warns.
	 *
	 * @param array<mixed> $entry The stored indicator.
	 *
	 * @return string The effect.
	 */
	private static function effectOf(array $entry): string {
		$effect = (self::text(value: ($entry['effect'] ?? null)) ?? 'warn');
		if (in_array($effect, self::EFFECTS, true) === false) {
			return 'warn';
		}

		return $effect;
	}//end effectOf()

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
