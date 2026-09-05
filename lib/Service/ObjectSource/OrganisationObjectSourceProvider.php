<?php

/**
 * OrganisationObjectSourceProvider — serves the `nc-organisation` virtual
 * schema's objects live from OpenRegister's own organisations.
 *
 * WHY THIS EXISTS
 * ---------------
 * Several apps grew their own `organization` SCHEMA because they needed to
 * reference an organisation from a property, and OpenRegister's Organisation is
 * an ENTITY with no object projection. A schema property written as
 * `{"$ref": "organization"}` resolves against a schema, so there was nothing for
 * it to point at, and each app declared its own copy instead. The slug is global
 * per organisation, so those copies collide.
 *
 * Projecting the organisation as a virtual object gives that `$ref` a target,
 * which is the one thing standing between the leaf copies and retirement.
 *
 * This follows {@see GroupObjectSourceProvider} and
 * {@see UserDirectoryObjectSourceProvider} exactly: OpenRegister already
 * projects identity entities this way, read-only, on the always-available
 * `directory` register, with the schema `nc-`-prefixed so it cannot collide
 * with a leaf app's own `organization`.
 *
 * WRITABLE, THROUGH THE LIFECYCLE RATHER THAN AROUND IT
 * -----------------------------------------------------
 * This was read-only, on the reasoning that a write path here would be a second
 * way to mutate a tenant, bypassing `OrganisationService`'s lifecycle. That
 * reasoning holds and the conclusion did not: a read-only projection cannot
 * replace the leaf copies, because the apps that declared them CREATE
 * organisations. Stackiq's setup walkthrough says "Click New and save an
 * organisation" and advances on `object-created`. Migrating that onto a
 * read-only schema would retire a working flow rather than move it.
 *
 * So the write path exists and goes THROUGH `OrganisationService::createOrganisation()`,
 * which is what makes it safe: slug generation, owner assignment, the admin-group
 * RBAC grant and slug-collision recovery all still happen. The provider adds only
 * the identity fields on top.
 *
 * `remove()` REFUSES. An organisation is the tenant boundary, so deleting one
 * through the object API would orphan every object scoped to it, from a caller
 * that thinks it is deleting a reference record. Merging is the operation that
 * exists for retiring an organisation, and it keeps the references pointing
 * somewhere real.
 *
 * The write is still gated twice over: the schema annotation must carry
 * `readOnly: false` (which `SeedDirectoryVirtualSchemas` sets for this schema and
 * no other), and `mayWrite()` below re-checks the acting user at write time.
 *
 * SCOPING
 * -------
 * An organisation IS the tenant boundary, so the projection is scoped to the
 * organisations the acting user actually belongs to. An admin sees all of them.
 * Absent and denied both return null, so the projection is not an enumeration
 * oracle for the instance's tenants.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Projects each organisation as a virtual object, readable by its members and
 * writable by its owner.
 *
 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
 */
class OrganisationObjectSourceProvider implements WritableObjectSourceProvider {

	/**
	 * The properties the projection exposes.
	 *
	 * Deliberately the identity facet and nothing else. The quota, authorization
	 * and lifecycle columns are tenancy administration: an object projection is
	 * for referencing an organisation from another record, not for managing one,
	 * and exposing them here would put tenant configuration behind the object API.
	 *
	 * @var array<int, string>
	 */
	private const PROJECTED = [
		'name',
		'description',
		'summary',
		'oin',
		'tooi',
		'rsin',
		'kvk',
		'pki',
		'image',
		'type',
		'registrationStatus',
	];

	/**
	 * Wire the mapper and the acting-user services.
	 *
	 * @param OrganisationMapper  $organisationMapper  The organisation mapper.
	 * @param IUserSession        $userSession         The acting user's session.
	 * @param IGroupManager       $groupManager        Group manager, for the admin check.
	 * @param LoggerInterface     $logger              Logger.
	 * @param OrganisationService $organisationService The organisation lifecycle,
	 *                                                 which owns slug generation,
	 *                                                 owner assignment and the
	 *                                                 admin-group RBAC grant.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrganisationMapper $organisationMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly OrganisationService $organisationService,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	public function getId(): string {
		return 'organisation-source';
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * Organisations are OpenRegister's own, so this provider is always available.
	 *
	 * @return bool Always true.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * {@inheritDoc}
	 *
	 * Returns null when the organisation is absent OR the acting user may not
	 * read it, so the two are indistinguishable and the projection cannot be used
	 * to enumerate the instance's tenants.
	 *
	 * A merged-away organisation resolves to its survivor, so a reference stored
	 * before a merge keeps pointing at a real record.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param string               $id       The organisation uuid.
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent or denied.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		try {
			$organisation = $this->organisationMapper->findByUuidFollowingMerge(uuid: $id);
		} catch (Throwable $e) {
			$this->logger->debug('[ObjectSource:organisation-source] could not read organisation: ' . $e->getMessage());
			return null;
		}

		if ($this->mayRead(organisation: $organisation) === false) {
			return null;
		}

		return $this->toObjectEntity(register: $register, schema: $schema, organisation: $organisation);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * Honours `filters.search` / `_search`, `limit` and `offset`. An admin sees
	 * every organisation; anyone else sees only the ones they belong to.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $query    Query (filters/search/limit/offset).
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity[] The matching virtual objects.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$objects = [];
		foreach ($this->readOrganisations(query: $query) as $organisation) {
			$objects[] = $this->toObjectEntity(register: $register, schema: $schema, organisation: $organisation);
		}

		return $objects;
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $query    Query (filters/search).
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
	}//end count()

	/**
	 * {@inheritDoc}
	 *
	 * Creates the organisation through {@see OrganisationService::createOrganisation()}
	 * rather than through the mapper, so the slug, the owner, the admin users and
	 * the admin-group RBAC grant are all assigned the way they are for an
	 * organisation created anywhere else. A row written straight through the
	 * mapper would be a tenant nobody administers.
	 *
	 * The remaining identity fields are applied afterwards, because
	 * `createOrganisation()` takes only a name and a description.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $data     The validated object data.
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity The created organisation, projected.
	 *
	 * @throws RuntimeException When there is no acting user, or `name` is missing.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-an-organisation-can-be-created-through-the-projection-req-orp-104
	 */
	public function insert(Register $register, Schema $schema, array $data, array $config = []): ObjectEntity {
		if ($this->userSession->getUser() === null) {
			throw new RuntimeException('An organisation cannot be created without an acting user.');
		}

		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			// Refused rather than defaulted. `createOrganisation()` derives the
			// slug from the name, so an empty one would produce a tenant with an
			// empty slug that the next create then collides with.
			throw new RuntimeException('An organisation needs a name.');
		}

		$organisation = $this->organisationService->createOrganisation(
			name: $name,
			description: (string)($data['description'] ?? '')
		);

		$organisation = $this->applyProjectedFields(organisation: $organisation, data: $data, skip: ['name', 'description']);

		return $this->toObjectEntity(register: $register, schema: $schema, organisation: $organisation);
	}//end insert()

	/**
	 * {@inheritDoc}
	 *
	 * Only the projected identity fields are written. Tenancy administration
	 * (quota, users, groups, authorization) is not projected and so cannot be
	 * reached here, which is the same boundary the read side draws.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param string               $id       The organisation uuid.
	 * @param array<string, mixed> $data     The validated object data.
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity The updated organisation, projected.
	 *
	 * @throws RuntimeException When the organisation is absent, merged away, or the
	 *                          acting user does not administer it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config reserved for future scoping options.
	 *
	 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-only-an-organisations-administrator-may-write-it-req-orp-105
	 */
	public function update(Register $register, Schema $schema, string $id, array $data, array $config = []): ObjectEntity {
		try {
			$organisation = $this->organisationMapper->findByUuid(uuid: $id);
		} catch (Throwable $e) {
			throw new RuntimeException(sprintf('Organisation "%s" does not exist.', $id), 0, $e);
		}

		// NOT `findByUuidFollowingMerge()`, which the read side uses. Following a
		// merge on a WRITE would silently edit the survivor while the caller
		// believes it is editing the record it addressed.
		if ($organisation->isMerged() === true) {
			throw new RuntimeException(
				sprintf('Organisation "%s" has been merged away and cannot be written.', $id)
			);
		}

		if ($this->mayWrite(organisation: $organisation) === false) {
			// Deliberately the same message shape as the absent case above would
			// give an outsider nothing to distinguish them by — but an
			// administrator reading the log needs the difference, so it is logged.
			$this->logger->info(
				'[ObjectSource:organisation-source] write on organisation ' . $id . ' refused: acting user is not its administrator'
			);
			throw new RuntimeException(sprintf('Organisation "%s" does not exist.', $id));
		}

		$organisation = $this->applyProjectedFields(organisation: $organisation, data: $data, skip: []);

		return $this->toObjectEntity(register: $register, schema: $schema, organisation: $organisation);
	}//end update()

	/**
	 * {@inheritDoc}
	 *
	 * REFUSES, always. An organisation is the tenant boundary: every object,
	 * register and schema on the instance is scoped to one. Deleting it through
	 * the object API would orphan all of that, from a caller that believes it is
	 * removing a reference record.
	 *
	 * Merging is the operation that exists for retiring an organisation, and it
	 * keeps every stored reference pointing at a record that still owns something.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param string               $id       The organisation uuid.
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return bool Never returns.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the interface's.
	 *
	 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-an-organisation-cannot-be-deleted-through-the-projection-req-orp-106
	 */
	public function remove(Register $register, Schema $schema, string $id, array $config = []): bool {
		throw new RuntimeException(
			sprintf(
				'Organisation "%s" cannot be deleted through the object API: it is a tenant boundary, '
				.'and every object scoped to it would be orphaned. Merge it instead.',
				$id
			)
		);
	}//end remove()

	/**
	 * Write the projected identity fields onto an organisation and persist it.
	 *
	 * Only keys in {@see PROJECTED} are written. A key outside it is ignored
	 * rather than rejected: the store already discards unprojected properties on
	 * the way in, so rejecting here would fail a request over a field the caller
	 * cannot see anyway.
	 *
	 * Organisation's accessors are magic (`Entity::__call`), so the setter is
	 * called dynamically. `method_exists()` would answer false for every one of
	 * them, which is exactly how {@see project()} once produced an object
	 * carrying nothing but an id.
	 *
	 * @param Organisation         $organisation The organisation to write.
	 * @param array<string, mixed> $data         The object data.
	 * @param array<int, string>   $skip         Properties already applied.
	 *
	 * @return Organisation The saved organisation.
	 *
	 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-only-the-identity-facet-is-writable-req-orp-107
	 */
	private function applyProjectedFields(Organisation $organisation, array $data, array $skip): Organisation {
		$changed = false;

		foreach (self::PROJECTED as $property) {
			if (in_array($property, $skip, true) === true || array_key_exists($property, $data) === false) {
				continue;
			}

			$value = $data[$property];
			if ($value !== null && is_scalar($value) === false) {
				continue;
			}

			if ($value !== null) {
				$value = (string)$value;
			}

			$organisation->{'set'.ucfirst($property)}($value);
			$changed = true;
		}

		if ($changed === false) {
			return $organisation;
		}

		return $this->organisationMapper->save($organisation);
	}//end applyProjectedFields()

	/**
	 * Whether the acting user may write this organisation.
	 *
	 * Membership is enough to READ the projection; writing needs ownership.
	 * `isOrganisationAdmin()` is OpenRegister's own answer to that question
	 * (instance admin, or the organisation's owner), so the projection and the
	 * rest of the app agree on who administers an organisation.
	 *
	 * @param Organisation $organisation The organisation being written.
	 *
	 * @return bool True when the acting user administers it.
	 *
	 * @spec openspec/changes/the-organisation-projection-is-writable/specs/organisation-projection/spec.md#requirement-only-an-organisations-administrator-may-write-it-req-orp-105
	 */
	private function mayWrite(Organisation $organisation): bool {
		if ($this->userSession->getUser() === null) {
			return false;
		}

		try {
			return $this->organisationService->isOrganisationAdmin(
				organisationUuid: (string)$organisation->getUuid()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ObjectSource:organisation-source] could not check write authority: ' . $e->getMessage()
			);
			return false;
		}
	}//end mayWrite()

	/**
	 * The projected fields of one organisation.
	 *
	 * Empty values are omitted rather than written as null, so a consumer can
	 * tell "this organisation has no OIN" from "this projection does not carry
	 * OINs".
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return array<string, mixed> The object body.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-the-projection-carries-the-identity-facet-only-req-orp-102
	 */
	public static function project(Organisation $organisation): array {
		// Read the SERIALISED entity, not `method_exists()` + a derived getter.
		// Organisation's accessors are magic (`Entity::__call`), so
		// `method_exists($organisation, 'getName')` is FALSE and every field
		// would have been skipped, leaving a projection carrying nothing but an
		// id. That shipped-looking-fine failure is what the tests caught.
		$serialised = $organisation->jsonSerialize();

		$data = ['id' => (string)$organisation->getUuid()];

		foreach (self::PROJECTED as $property) {
			$value = ($serialised[$property] ?? null);
			if ($value === null || $value === '') {
				continue;
			}

			$data[$property] = $value;
		}

		return $data;
	}//end project()

	/**
	 * Whether the acting user may read this organisation's projection.
	 *
	 * @param Organisation $organisation The organisation being read.
	 *
	 * @return bool True when the acting user may read it.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-the-projection-is-not-an-enumeration-oracle-req-orp-103
	 */
	private function mayRead(Organisation $organisation): bool {
		$acting = $this->userSession->getUser();
		if ($acting === null) {
			return false;
		}

		try {
			if ($this->groupManager->isAdmin($acting->getUID()) === true) {
				return true;
			}

			return $organisation->hasUser($acting->getUID());
		} catch (Throwable $e) {
			return false;
		}
	}//end mayRead()

	/**
	 * The organisations visible to the acting user, failing closed to an empty list.
	 *
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 *
	 * @return array<int, Organisation> The visible organisations.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-the-projection-is-not-an-enumeration-oracle-req-orp-103
	 */
	private function readOrganisations(array $query): array {
		$acting = $this->userSession->getUser();
		if ($acting === null) {
			return [];
		}

		$search = (string)($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
		$limit = (int)($query['limit'] ?? 200);
		$offset = (int)($query['offset'] ?? 0);

		try {
			if ($this->groupManager->isAdmin($acting->getUID()) === true) {
				$organisations = $this->organisationMapper->findAll(limit: $limit, offset: $offset, filters: []);
				return array_values(self::matching(organisations: $organisations, search: $search));
			}

			$organisations = $this->organisationMapper->findByUserId($acting->getUID());
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:organisation-source] could not list organisations: ' . $e->getMessage());
			return [];
		}

		return array_values(self::matching(organisations: $organisations, search: $search));
	}//end readOrganisations()

	/**
	 * Filter organisations by a search term over the fields a person would type.
	 *
	 * A merged-away organisation is excluded: it is not a usable reference target,
	 * and offering it in a picker invites a reference to a record that no longer
	 * owns anything.
	 *
	 * @param array<int, Organisation> $organisations The candidates.
	 * @param string                   $search        The search term, or ''.
	 *
	 * @return array<int, Organisation> The matches.
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-the-projection-carries-the-identity-facet-only-req-orp-102
	 */
	public static function matching(array $organisations, string $search): array {
		$live = array_filter(
			$organisations,
			static function ($organisation) {
				return ($organisation instanceof Organisation && $organisation->isMerged() === false);
			}
		);

		if ($search === '') {
			return $live;
		}

		$needle = strtolower($search);

		return array_filter(
			$live,
			static function (Organisation $organisation) use ($needle) {
				foreach ([$organisation->getName(), $organisation->getOin(), $organisation->getRsin(), $organisation->getKvk()] as $field) {
					if ($field !== null && str_contains(strtolower((string)$field), $needle) === true) {
						return true;
					}
				}

				return false;
			}
		);
	}//end matching()

	/**
	 * Map an organisation onto a non-persisted virtual ObjectEntity.
	 *
	 * @param Register     $register     The register.
	 * @param Schema       $schema       The sourced schema.
	 * @param Organisation $organisation The organisation.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 *
	 * @spec openspec/changes/organisations-are-objects/specs/organisation-projection/spec.md#requirement-an-organisation-is-addressable-as-an-object-req-orp-101
	 */
	private function toObjectEntity(Register $register, Schema $schema, Organisation $organisation): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)$organisation->getUuid());
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject(self::project(organisation: $organisation));

		return $entity;
	}//end toObjectEntity()
}//end class
