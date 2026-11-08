<?php

/**
 * OpenRegister Example Set Cast Organisations
 *
 * The OpenRegister organisations a cast declares, read into the plan: each
 * needs a key, a name and a slug, may declare a quota of whole numbers (and
 * the schema its object quota counts), and names its members by the keys of
 * cast people with an account. Anything else refuses the set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Reads the organisations a cast declares.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
 */
final class ExampleSetCastOrganisations {
	use ExampleSetKeyOf;

	/**
	 * The quotas that count, each a whole number.
	 */
	private const COUNTS = ['storage', 'requests', 'bandwidth', 'users', 'objects'];

	/**
	 * The OpenRegister organisations the cast declares, each with its quota and its members' accounts.
	 *
	 * @param array<int, mixed>  $declared The declared organisations.
	 * @param string             $file     The cast file, for messages.
	 * @param array<int, mixed>  $people   The cast's people, to turn member keys into accounts.
	 *
	 * @return array<int, array{key: string, name: string, slug: string, description: string, quota: array<string, int>, members: array<int, string>}>
	 *
	 * @throws ExampleSetException When an organisation lacks a key, name or slug, names a quota that is not a whole number,
	 *                             or a member the cast does not hold.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
	 */
	public function entities(array $declared, string $file, array $people=[]): array {
		$accounts      = $this->accountsByKey(people: $people);
		$organisations = [];
		foreach ($declared as $index => $organisation) {
			$organisation = (array) $organisation;
			if ($this->keyOf(entry: $organisation) === '' || (string) ($organisation['name'] ?? '') === '' || (string) ($organisation['slug'] ?? '') === '') {
				throw new ExampleSetException($file.': OpenRegister organisation '.$index.' needs a key, a name and a slug.');
			}

			$organisations[] = $this->organisationEntity(organisation: $organisation, file: $file, accounts: $accounts);
		}

		return $organisations;
	}//end entities()

	/**
	 * Each cast person's account, by key.
	 *
	 * @param array<int, mixed> $people The cast's people.
	 *
	 * @return array<string, string>
	 */
	private function accountsByKey(array $people): array {
		$accounts = [];
		foreach ($people as $person) {
			$person = (array) $person;
			$accounts[(string) ($person['key'] ?? '')] = (string) ($person['uid'] ?? '');
		}

		return $accounts;
	}//end accountsByKey()

	/**
	 * One declared organisation, with a key, a name and a slug, as the plan holds it.
	 *
	 * @param array<string, mixed>  $organisation The declared organisation.
	 * @param string                $file         The cast file, for messages.
	 * @param array<string, string> $accounts     Each cast person's account, by key.
	 *
	 * @return array{
	 *     key: string, name: string, slug: string, description: string, type: string, contactEmail: string,
	 *     quota: array<string, int|string>, members: array<int, string>
	 * }
	 *
	 * @throws ExampleSetException When a quota is not a whole number or a member is not a cast person with an account.
	 */
	private function organisationEntity(array $organisation, string $file, array $accounts): array {
		$slug = (string) $organisation['slug'];
		return [
			'key'          => $this->keyOf(entry: $organisation),
			'name'         => (string) $organisation['name'],
			'slug'         => $slug,
			'description'  => (string) ($organisation['description'] ?? ''),
			'type'         => (string) ($organisation['type'] ?? ''),
			'contactEmail' => (string) ($organisation['contactEmail'] ?? ''),
			'quota'        => $this->organisationQuota(declared: ($organisation['quota'] ?? []), file: $file, slug: $slug),
			'members'      => $this->organisationMembers(declared: ($organisation['members'] ?? []), accounts: $accounts, file: $file, slug: $slug),
		];
	}//end organisationEntity()

	/**
	 * An organisation's declared quota: storage (bytes), requests, bandwidth,
	 * users and objects, each a whole number, and objectsSchema, the slug of the
	 * schema the object quota counts.
	 *
	 * @param mixed  $declared The declared quota.
	 * @param string $file     The cast file, for messages.
	 * @param string $slug     The organisation's slug, for messages.
	 *
	 * @return array<string, int|string>
	 *
	 * @throws ExampleSetException When a key is unknown or a value is not a whole number.
	 */
	private function organisationQuota(mixed $declared, string $file, string $slug): array {
		$quota = [];
		foreach ((array) $declared as $key => $value) {
			if ($this->isQuota(key: $key, value: $value) === false) {
				throw new ExampleSetException(
					$file.': OpenRegister organisation "'.$slug.'": quota "'.$key.'" must be storage, requests, bandwidth, users or objects'
					.' with a whole number, or objectsSchema with a schema slug.'
				);
			}

			$quota[$key] = $value;
		}

		return $quota;
	}//end organisationQuota()

	/**
	 * Whether a declared quota is one an organisation takes: objectsSchema with a schema slug, or storage, requests,
	 * bandwidth, users or objects with a whole number of 0 or more.
	 *
	 * @param int|string $key   The quota.
	 * @param mixed      $value The declared value.
	 *
	 * @return bool
	 */
	private function isQuota(int|string $key, mixed $value): bool {
		if ($key === 'objectsSchema') {
			return is_string($value) === true && $value !== '';
		}

		return $this->isCount(key: $key, value: $value);
	}//end isQuota()

	/**
	 * Whether a declared quota is storage, requests, bandwidth, users or objects with a whole number of 0 or more.
	 *
	 * @param int|string $key   The quota.
	 * @param mixed      $value The declared value.
	 *
	 * @return bool
	 */
	private function isCount(int|string $key, mixed $value): bool {
		return in_array($key, self::COUNTS, true) === true && is_int($value) === true && $value >= 0;
	}//end isCount()

	/**
	 * The accounts of an organisation's declared members, which are cast people's keys.
	 *
	 * @param mixed                 $declared The declared member keys.
	 * @param array<string, string> $accounts Each cast person's account, by key.
	 * @param string                $file     The cast file, for messages.
	 * @param string                $slug     The organisation's slug, for messages.
	 *
	 * @return array<int, string>
	 *
	 * @throws ExampleSetException When a member is not a cast person with an account.
	 */
	private function organisationMembers(mixed $declared, array $accounts, string $file, string $slug): array {
		$members = [];
		foreach ((array) $declared as $key) {
			if (($accounts[(string) $key] ?? '') === '') {
				throw new ExampleSetException($file.': OpenRegister organisation "'.$slug.'": member "'.$key.'" is not a cast person with an account.');
			}

			$members[] = $accounts[(string) $key];
		}

		return $members;
	}//end organisationMembers()
}//end class
