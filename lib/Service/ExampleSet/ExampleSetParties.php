<?php

/**
 * OpenRegister Example Set Parties
 *
 * The parties an example set puts on its objects, such as a representative
 * with a mandate on a demo case. A party link is a row in the party model,
 * not an object in a register, so the set writes it through the party role
 * service's trusted path with the fragment's app as the linker, and records
 * the object, party and role. Remove unlinks the parties the record holds.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and unlinks the parties of an example set.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ContactLink::partyUid is the link table's own key for a party.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
 */
class ExampleSetParties extends ExampleSetEngineRows {
	/**
	 * The party link fields a fragment may set.
	 */
	public const FIELDS = ['objectUuid', 'partyUuid', 'role', 'validFrom', 'validUntil', 'note', 'primary'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the party services and the link mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part party links live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function part(): string {
		return 'parties';
	}//end part()

	/**
	 * A refresh writes a held link again where it is: the link store finds it by object, party and role, and the
	 * name and e-mail address it caches of the party are read afresh.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-a-party-link-again-where-it-is
	 */
	public function updatesInPlace(): bool {
		return true;
	}//end updatesInPlace()

	/**
	 * The word for one party link, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function noun(): string {
		return 'party';
	}//end noun()

	/**
	 * Why a filled party link cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled link fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts (unused: a party needs no account).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a party field a set may write';
		}

		foreach (['objectUuid' => 'the object it is on', 'partyUuid' => 'the party', 'role' => 'a role'] as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'a party link needs '.$what.' ('.$field.')';
			}
		}

		return $this->datesRefusal(data: $data);
	}//end refusal()

	/**
	 * Link the party to the object through the party model's trusted path.
	 *
	 * The object is found without RBAC, as every other example-set read is: an
	 * occ install has no user, and a schema that refuses "Anonymous" would
	 * otherwise read as "no such object".
	 *
	 * @param string               $app  The app that ships the fragment, recorded as the linker.
	 * @param string               $key  The link's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return array{error: string, entry: array{objectUuid: string, partyUuid: string, role: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$objectUuid = (string) $data['objectUuid'];
		$object     = $this->parties()->findUnscoped(uuid: $objectUuid);
		if ($object === null) {
			return ['error' => 'no object "'.$objectUuid.'" to put the party on', 'entry' => null];
		}

		try {
			$this->roles()->importParty(
				objectUuid: $objectUuid,
				registerId: (int) $object->getRegister(),
				schemaId: $object->getSchema(),
				payload: array_diff_key($data, ['objectUuid' => true]),
				linkedBy: $app
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['objectUuid' => $objectUuid, 'partyUuid' => (string) $data['partyUuid'], 'role' => (string) $data['role']]];
	}//end write()

	/**
	 * Whether a written party link is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->links()->findByObjectContactAndRole(
				objectUuid: (string) $entry['objectUuid'],
				contactUid: ContactLink::partyUid(partyUuid: (string) $entry['partyUuid']),
				role: (string) $entry['role']
			) === null;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Unlink a party the set linked.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function revert(array $entry): string {
		if ($this->lost(entry: $entry) === true) {
			return 'removed';
		}

		try {
			$this->roles()->removeParty(
				objectUuid: (string) $entry['objectUuid'],
				partyUuid: (string) $entry['partyUuid'],
				role: (string) $entry['role']
			);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * A validFrom or validUntil that is not a date.
	 *
	 * @param array<string, mixed> $data The filled link fields.
	 *
	 * @return string
	 */
	private function datesRefusal(array $data): string {
		foreach (['validFrom', 'validUntil'] as $field) {
			if (isset($data[$field]) === false) {
				continue;
			}

			try {
				new DateTime((string) $data[$field]);
			} catch (Throwable) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		return '';
	}//end datesRefusal()

	/**
	 * The party role service.
	 *
	 * @return PartyRoleService
	 */
	private function roles(): PartyRoleService {
		return $this->container->get(PartyRoleService::class);
	}//end roles()

	/**
	 * The party service, which finds any object by uuid.
	 *
	 * @return PartyService
	 */
	private function parties(): PartyService {
		return $this->container->get(PartyService::class);
	}//end parties()

	/**
	 * The party link mapper.
	 *
	 * @return ContactLinkMapper
	 */
	private function links(): ContactLinkMapper {
		return $this->container->get(ContactLinkMapper::class);
	}//end links()
}//end class
