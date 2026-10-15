<?php

/**
 * Reads an organisation's special rights, and answers whether a caller holds one.
 *
 * WHAT A SPECIAL RIGHT IS. An organisation's `authorization` block carries the
 * create, read, update and delete lists per entity type, and beside them a few
 * rights that are not one of those four: `object_publish`, `agent_use`,
 * `dashboard_view` and `llm_use` ({@see \OCA\OpenRegister\Db\Organisation}). The
 * organisation editor shows them in its "Special Rights" tab. Each is a list of
 * the groups that hold it.
 *
 * WHY THIS CLASS EXISTS. The four rights above were declared, stored and
 * assignable, and nothing read them. This is the one read. It was written for
 * `grant_manage` (a right over one record, {@see holdsOn()}) and answers since
 * decision 239 for the rights a caller USES, `agent_use` and `llm_use`
 * ({@see mayUse()}). `object_publish` and `dashboard_view` are read by nothing
 * yet: no action in this app matches them (see the change
 * enforce-organisation-special-rights).
 *
 * A RIGHT A CALLER USES. Read from the organisation the thing being used
 * belongs to: an agent's organisation for `agent_use`, a conversation's for
 * `llm_use`. A list of group ids, where `*` stands for every signed-in user.
 * An administrator always holds it. A key the organisation does not carry is
 * not narrowed, and holds for everybody: an upgrade must not refuse a caller
 * nobody decided to refuse. The repair step
 * {@see \OCA\OpenRegister\Repair\OpenSpecialRightsToEveryone} writes that `*`
 * into every organisation once, so an administrator sees it and can narrow it.
 *
 * `grant_manage` lets its holder list, add and remove the grants on a record
 * and name the record's owning group, on records the holder does not own. It is
 * for a service identity that keeps grants in step with a rule (dossiq's
 * background service account, decision 234). It opens nothing else: holding it
 * does not let the holder read or change the record's contents, and the two
 * services that honour it refuse a holder who names itself or one of its own
 * groups, so the right cannot be turned into a read.
 *
 * AN ENTRY. Either a group id, which holds the right on every record of the
 * organisation, as the four existing rights are written. Or an object naming a
 * group and narrowing it:
 *
 *   {"group": "dossiq-background-service", "register": "dossiq", "schemas": ["reviewBatch"]}
 *
 * `register` and `schemas` name a slug, a uuid or an id. A narrowing that is
 * present and unreadable admits nobody.
 *
 * FAIL-CLOSED. A record whose organisation cannot be found, a block that is not
 * a list, an entry that is neither shape, a register or schema that cannot be
 * loaded: each answers false. `*` is not a group here. A right that lets its
 * holder decide who reads a record is not given to everybody by a wildcard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use InvalidArgumentException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one reader, and the one in-process writer, of an organisation's special rights.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Decision 239 asks for ONE reader of every special
 *                                                   right; a right over a record and a right a caller
 *                                                   uses are answered here so they cannot drift apart.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Same reason: the record, register, schema and
 *                                                   organisation lookups plus the group manager.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class OrganisationSpecialRights {

	/**
	 * The special right to manage the grants and the owning group of a record.
	 *
	 * @var string
	 */
	public const GRANT_MANAGE = 'grant_manage';

	/**
	 * The special right to publish and depublish objects. Read by nothing: see the class comment.
	 *
	 * @var string
	 */
	public const OBJECT_PUBLISH = 'object_publish';

	/**
	 * The special right to run an agent.
	 *
	 * @var string
	 */
	public const AGENT_USE = 'agent_use';

	/**
	 * The special right to view the dashboard. Read by nothing: see the class comment.
	 *
	 * @var string
	 */
	public const DASHBOARD_VIEW = 'dashboard_view';

	/**
	 * The special right to have a language model called on one's behalf.
	 *
	 * @var string
	 */
	public const LLM_USE = 'llm_use';

	/**
	 * Every special right an organisation carries, in the order the editor shows them.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::OBJECT_PUBLISH,
		self::AGENT_USE,
		self::DASHBOARD_VIEW,
		self::LLM_USE,
		self::GRANT_MANAGE,
	];

	/**
	 * The rights where `*` means every signed-in user, and a missing key means nobody narrowed it.
	 *
	 * Not `grant_manage`: a right that decides who reads a record is never given
	 * to everybody by a wildcard or by leaving it out.
	 *
	 * @var string[]
	 */
	public const OPEN_BY_DEFAULT = [
		self::OBJECT_PUBLISH,
		self::AGENT_USE,
		self::DASHBOARD_VIEW,
		self::LLM_USE,
	];

	/**
	 * The entry that gives a right to every signed-in user.
	 *
	 * @var string
	 */
	public const EVERYONE = '*';

	/**
	 * Organisations read per page when giving a right in all of them.
	 *
	 * @var int
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param OrganisationMapper $organisations Loads the organisation a record belongs to.
	 * @param RegisterMapper $registers Loads a record's register, for a narrowed entry.
	 * @param SchemaMapper $schemas Loads a record's schema, for a narrowed entry.
	 * @param LoggerInterface $logger Logger.
	 * @param IGroupManager $groupManager Says whether a caller is an administrator, and which groups it is in.
	 */
	public function __construct(
		private readonly OrganisationMapper $organisations,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a caller holds a special right on one record.
	 *
	 * The right is read from the organisation the RECORD belongs to, not from
	 * the caller's active organisation: a service identity has no active
	 * organisation, and a right over a record is the business of whoever the
	 * record belongs to. A record that names no organisation is read against
	 * the instance's default organisation, which is where the tenancy layer
	 * puts it too.
	 *
	 * @param string $right The special right's key.
	 * @param string[] $userGroups The caller's group ids.
	 * @param ObjectEntity $object The record.
	 *
	 * @return bool True when one of the caller's groups holds the right on this record.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function holdsOn(string $right, array $userGroups, ObjectEntity $object): bool {
		if ($userGroups === []) {
			return false;
		}

		$organisation = $this->organisationOf(object: $object);
		if ($organisation === null) {
			return false;
		}

		$entries = ($organisation->getAuthorization()[$right] ?? null);
		if (is_array($entries) === false) {
			return false;
		}

		foreach ($entries as $entry) {
			if ($this->entryAdmits(entry: $entry, userGroups: $userGroups, object: $object) === true) {
				return true;
			}
		}

		return false;
	}//end holdsOn()

	/**
	 * Whether a caller may use something a special right guards, in one organisation.
	 *
	 * For the rights in {@see OPEN_BY_DEFAULT}; any other right answers false.
	 *
	 * - An administrator may.
	 * - The organisation is the one the used thing belongs to. When it names
	 *   none, the instance's default organisation is read. When there is no
	 *   default either, no organisation could have narrowed the right, and the
	 *   caller may: that is how the instance behaved before the right was read.
	 * - An organisation that is named and cannot be loaded refuses.
	 * - A key the organisation does not carry is not narrowed: the caller may.
	 * - A value that is not a list refuses. A list holding `*` admits every
	 *   signed-in user. Otherwise the caller must be in one of the listed groups.
	 *   An empty list admits only administrators.
	 *
	 * @param string $right The special right's key.
	 * @param string $userId The caller.
	 * @param string|null $organisationUuid The organisation the used thing belongs to, or null.
	 *
	 * @return bool True when the caller may.
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function mayUse(string $right, string $userId, ?string $organisationUuid): bool {
		if ($userId === '' || in_array(needle: $right, haystack: self::OPEN_BY_DEFAULT, strict: true) === false) {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$entries = $this->entriesInUse(right: $right, organisationUuid: $organisationUuid);
		if ($entries === null) {
			return true;
		}

		return $this->entriesAdmit(entries: $entries, userId: $userId);
	}//end mayUse()

	/**
	 * Whether a list of a right a caller uses admits the caller: `*`, or one of its groups.
	 *
	 * @param array<int|string, mixed> $entries The stored entries.
	 * @param string $userId The caller.
	 *
	 * @return bool True when it does.
	 */
	private function entriesAdmit(array $entries, string $userId): bool {
		foreach ($entries as $entry) {
			if ($entry === self::EVERYONE) {
				return true;
			}

			if (is_string($entry) === true && $entry !== '' && $this->groupManager->isInGroup($userId, $entry) === true) {
				return true;
			}
		}

		return false;
	}//end entriesAdmit()

	/**
	 * The list a right a caller uses is read from, or null when nothing narrows it.
	 *
	 * @param string $right The special right's key.
	 * @param string|null $organisationUuid The organisation the used thing belongs to, or null.
	 *
	 * @return array<int|string, mixed>|null The stored entries; empty when the organisation
	 *                                       cannot be loaded or the value is not a list.
	 */
	private function entriesInUse(string $right, ?string $organisationUuid): ?array {
		$uuid = $organisationUuid;
		if (is_string($uuid) === false || $uuid === '') {
			$uuid = $this->organisations->getDefaultOrganisationFromConfig();
		}

		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			$authorization = $this->organisations->findByUuid($uuid)->getAuthorization();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OrganisationSpecialRights] Could not load the organisation a special right is read from; refused',
				context: ['file' => __FILE__, 'line' => __LINE__, 'right' => $right, 'organisation' => $uuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (array_key_exists($right, $authorization) === false) {
			return null;
		}

		if (is_array($authorization[$right]) === false) {
			return [];
		}

		return $authorization[$right];
	}//end entriesInUse()

	/**
	 * Give every signed-in user a special right in every organisation that does not already.
	 *
	 * For the upgrade that starts reading a right: it writes down what the
	 * instance did before, so nothing is refused until an administrator
	 * narrows it. Adds `*` to the list and keeps whatever groups it names. An
	 * organisation whose list already holds `*` is not written.
	 *
	 * @param string $right The special right's key, one of {@see OPEN_BY_DEFAULT}.
	 *
	 * @return array{organisations: int, changed: int} How many organisations were seen and written.
	 *
	 * @throws InvalidArgumentException When the right may not be given to everybody.
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function openToEveryoneInEveryOrganisation(string $right): array {
		if (in_array(needle: $right, haystack: self::OPEN_BY_DEFAULT, strict: true) === false) {
			throw new InvalidArgumentException(message: 'The special right ' . $right . ' is never given to everybody');
		}

		$answer = ['organisations' => 0, 'changed' => 0];
		$offset = 0;
		do {
			$page = $this->organisations->findAll(limit: self::PAGE, offset: $offset);
			$offset += self::PAGE;
			$read = count($page);
			foreach ($page as $organisation) {
				$answer['organisations']++;
				$authorization = $organisation->getAuthorization();
				$entries = [];
				if (is_array($authorization[$right] ?? null) === true) {
					$entries = array_values($authorization[$right]);
				}

				if (in_array(needle: self::EVERYONE, haystack: $entries, strict: true) === true) {
					continue;
				}

				$entries[] = self::EVERYONE;
				$authorization[$right] = $entries;
				$organisation->setAuthorization($authorization);
				$this->organisations->update($organisation);
				$answer['changed']++;
			}
		} while ($read === self::PAGE);

		return $answer;
	}//end openToEveryoneInEveryOrganisation()

	/**
	 * Give a group a special right in one organisation, or narrow or widen what it has.
	 *
	 * For code shipped with an app: an app that brings a service identity names
	 * what that identity may do when the app is installed. It is not reachable
	 * over HTTP; the organisation endpoint is, and it lets only an administrator
	 * or the organisation's owner change this right.
	 *
	 * One entry per group. Giving a group the right again replaces its entry, so
	 * an app that changes its list of schemas does not leave the old one behind.
	 *
	 * @param string $organisationUuid The organisation.
	 * @param string $right The special right's key.
	 * @param string $group The group that holds it.
	 * @param string|null $register The register it is narrowed to, or null for all.
	 * @param string[]|null $schemas The schemas it is narrowed to, or null for all.
	 *
	 * @return bool True when the stored block changed.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When there is no such organisation.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function give(
		string $organisationUuid,
		string $right,
		string $group,
		?string $register = null,
		?array $schemas = null,
	): bool {
		$organisation = $this->organisations->findByUuid($organisationUuid);
		$authorization = $organisation->getAuthorization();

		$entry = $group;
		if ($register !== null || $schemas !== null) {
			$entry = ['group' => $group];
			if ($register !== null) {
				$entry['register'] = $register;
			}

			if ($schemas !== null) {
				$entry['schemas'] = array_values($schemas);
			}
		}

		$before = [];
		if (is_array($authorization[$right] ?? null) === true) {
			$before = array_values($authorization[$right]);
		}

		$after = [];
		foreach ($before as $existing) {
			if ($this->groupOf(entry: $existing) !== $group) {
				$after[] = $existing;
			}
		}

		$after[] = $entry;

		if ($after === $before) {
			return false;
		}

		$authorization[$right] = $after;
		$organisation->setAuthorization($authorization);
		$this->organisations->update($organisation);

		return true;
	}//end give()

	/**
	 * Give a group a special right in every organisation of the instance.
	 *
	 * The right is read from the organisation a record belongs to, so an app
	 * whose service identity works on records of every organisation names it in
	 * each. Run again on every upgrade: an organisation made since is covered
	 * then, and one that already has the entry is not written.
	 *
	 * @param string $right The special right's key.
	 * @param string $group The group that holds it.
	 * @param string|null $register The register it is narrowed to, or null for all.
	 * @param string[]|null $schemas The schemas it is narrowed to, or null for all.
	 *
	 * @return array{organisations: int, changed: int} How many organisations were seen and written.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function giveInEveryOrganisation(
		string $right,
		string $group,
		?string $register = null,
		?array $schemas = null,
	): array {
		$answer = ['organisations' => 0, 'changed' => 0];
		$offset = 0;
		do {
			$page = $this->organisations->findAll(limit: self::PAGE, offset: $offset);
			$offset += self::PAGE;
			$read = count($page);
			foreach ($page as $organisation) {
				$answer['organisations']++;
				if ($this->give(
					organisationUuid: (string)$organisation->getUuid(),
					right: $right,
					group: $group,
					register: $register,
					schemas: $schemas
				) === true
				) {
					$answer['changed']++;
				}
			}

		} while ($read === self::PAGE);

		return $answer;
	}//end giveInEveryOrganisation()

	/**
	 * The organisation a record belongs to, or null when it cannot be found.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return Organisation|null The organisation, or null.
	 */
	private function organisationOf(ObjectEntity $object): ?Organisation {
		$uuid = $object->getOrganisation();
		if (is_string($uuid) === false || $uuid === '') {
			$uuid = $this->organisations->getDefaultOrganisationFromConfig();
		}

		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			return $this->organisations->findByUuid($uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OrganisationSpecialRights] Could not load the organisation of a record; no special right is read',
				context: ['file' => __FILE__, 'line' => __LINE__, 'organisation' => $uuid, 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end organisationOf()

	/**
	 * The group one entry names, or null when the entry is neither shape.
	 *
	 * @param mixed $entry The entry as stored.
	 *
	 * @return string|null The group id, or null.
	 */
	private function groupOf(mixed $entry): ?string {
		if (is_array($entry) === true) {
			$entry = ($entry['group'] ?? null);
		}

		if (is_string($entry) === false || $entry === '' || $entry === '*') {
			return null;
		}

		return $entry;
	}//end groupOf()

	/**
	 * Whether one entry gives one of the caller's groups the right on this record.
	 *
	 * @param mixed $entry The entry as stored.
	 * @param string[] $userGroups The caller's group ids.
	 * @param ObjectEntity $object The record.
	 *
	 * @return bool True when it does.
	 */
	private function entryAdmits(mixed $entry, array $userGroups, ObjectEntity $object): bool {
		$group = $this->groupOf(entry: $entry);
		if ($group === null || in_array(needle: $group, haystack: $userGroups, strict: true) === false) {
			return false;
		}

		if (is_array($entry) === false) {
			return true;
		}

		if (array_key_exists('register', $entry) === true
			&& $this->names(wanted: [$entry['register']], names: $this->namesOf(
				id: (string)$object->getRegister(),
				load: fn (string $id): Register => $this->registers->find(id: $id, _rbac: false, _multitenancy: false)
			)) === false
		) {
			return false;
		}

		if (array_key_exists('schemas', $entry) === true) {
			if (is_array($entry['schemas']) === false) {
				return false;
			}

			return $this->names(wanted: $entry['schemas'], names: $this->namesOf(
				id: (string)$object->getSchema(),
				load: fn (string $id): Schema => $this->schemas->find(id: $id, _rbac: false, _multitenancy: false)
			));
		}

		return true;
	}//end entryAdmits()

	/**
	 * Whether one of the wanted references is among the names a record answers to.
	 *
	 * @param array<int|string, mixed> $wanted The references an entry lists.
	 * @param string[] $names The slug, uuid and id of the record's register or schema.
	 *
	 * @return bool True on a match. An empty list on either side matches nothing.
	 */
	private function names(array $wanted, array $names): bool {
		foreach ($wanted as $reference) {
			if ((is_string($reference) === true || is_int($reference) === true)
				&& (string)$reference !== ''
				&& in_array(needle: (string)$reference, haystack: $names, strict: true) === true
			) {
				return true;
			}
		}

		return false;
	}//end names()

	/**
	 * The id, slug and uuid a record's register or schema answers to.
	 *
	 * @param string $id The id the record carries.
	 * @param callable(string): (Register|Schema) $load Loads it, without the caller's own rules.
	 *
	 * @return string[] The names, empty when it cannot be loaded.
	 */
	private function namesOf(string $id, callable $load): array {
		if ($id === '') {
			return [];
		}

		try {
			$entity = $load($id);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OrganisationSpecialRights] Could not load the register or schema of a record; a narrowed entry admits nobody',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'error' => $e->getMessage()]
			);
			return [];
		}

		return array_values(
			array_filter(
				[$id, (string)$entity->getSlug(), (string)$entity->getUuid()],
				static fn (string $name): bool => $name !== ''
			)
		);
	}//end namesOf()
}//end class
