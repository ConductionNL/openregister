<?php

/**
 * Resolves a party ROLE on a case object to the party's reference.
 *
 * The node names a role (`initiator` by default); the case says who holds it.
 * This class reads the case's own data for that answer and refuses to guess:
 * a case that names nobody for the role throws, naming the role and the case,
 * so the firing fails instead of parking an unperformable ask.
 *
 * TWO SHAPES, NO SEMANTICS. A case may carry the party as a field named for
 * the role (`initiator: "…"` or `initiator: {subjectRef: "…"}`), or as an
 * entry in a party list (`rollen`, `roles`, `parties`, `betrokkenen`) whose
 * role marker matches. Within a party value the reference is read from the
 * first of a fixed, published key order (`subjectRef`, `portalSubject`,
 * `identificatie`, `bsn`, `kvk`, `rsin`, `uuid`, `id`, `ref`). That order is
 * the whole of what this class knows about cases; nothing in it is about
 * what a specific app's case MEANS (design D-2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\AbstractObjectMapper;
use OCA\OpenRegister\Exception\PortalPartyNotFoundException;
use Throwable;

/**
 * Party matching against the subject case object.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PortalSubject::partyReferenceFor is a
 * stateless helper over a value; a factory to call it would add a dependency
 * to say the same thing.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
 */
class PortalPartyResolver {

	/**
	 * The default role.
	 *
	 * @var string
	 */
	public const DEFAULT_ROLE = 'initiator';

	/**
	 * Where a party value keeps its reference, in order of preference.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCE_KEYS = ['subjectRef', 'portalSubject', 'identificatie', 'bsn', 'kvk', 'rsin', 'uuid', 'id', 'ref'];

	/**
	 * Nested containers a party value may wrap its identification in.
	 *
	 * @var array<int, string>
	 */
	private const NESTED_KEYS = ['betrokkeneIdentificatie', 'identification', 'party', 'betrokkene', 'subject'];

	/**
	 * Where a case keeps its party list.
	 *
	 * @var array<int, string>
	 */
	private const LIST_KEYS = ['rollen', 'roles', 'parties', 'betrokkenen'];

	/**
	 * Which entry keys mark an entry's role.
	 *
	 * @var array<int, string>
	 */
	private const ROLE_MARKERS = ['role', 'rol', 'roltype', 'rolType', 'type', 'omschrijvingGeneriek'];

	/**
	 * Constructor.
	 *
	 * @param AbstractObjectMapper|null $objects Reads the case object. Nullable
	 *                                           so the resolver stays
	 *                                           constructible bare; ABSENT, a
	 *                                           resolution by uuid throws, so
	 *                                           the firing fails rather than
	 *                                           matching nobody quietly.
	 */
	public function __construct(
		private readonly ?AbstractObjectMapper $objects = null,
	) {

	}//end __construct()

	/**
	 * Resolve a role on the case object with this uuid to a party reference.
	 *
	 * @param string $objectUuid The subject case object.
	 * @param string $role The party role.
	 *
	 * @return string The party reference, `party:<ref>`.
	 *
	 * @throws PortalPartyNotFoundException When the case cannot be read or names nobody.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function resolveFromObject(string $objectUuid, string $role): string {
		if ($this->objects === null) {
			throw new PortalPartyNotFoundException(
				message: sprintf("Cannot resolve party role '%s' on case '%s': no object store is available.", $role, $objectUuid)
			);
		}

		try {
			$object = $this->objects->find(identifier: $objectUuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $failure) {
			throw new PortalPartyNotFoundException(
				message: sprintf("Cannot resolve party role '%s': case '%s' could not be read (%s).", $role, $objectUuid, $failure->getMessage()),
				code: 0,
				previous: $failure
			);
		}

		return $this->resolve(case: $object->getObject(), role: $role, caseUuid: $objectUuid);
	}//end resolveFromObject()

	/**
	 * Resolve a role against a case's data.
	 *
	 * @param array<string, mixed> $case The case object's data.
	 * @param string $role The party role.
	 * @param string $caseUuid The case, for the refusal message.
	 *
	 * @return string The party reference, `party:<ref>`.
	 *
	 * @throws PortalPartyNotFoundException When the case names nobody for the role.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-the-matched-party-comes-from-the-case-and-is-frozen-at-creation
	 */
	public function resolve(array $case, string $role, string $caseUuid = ''): string {
		$role = trim($role);
		if ($role === '') {
			$role = self::DEFAULT_ROLE;
		}

		$reference = $this->referenceOf(value: ($case[$role] ?? null));
		if ($reference === '') {
			$reference = $this->fromPartyLists(case: $case, role: $role);
		}

		if ($reference === '') {
			throw new PortalPartyNotFoundException(
				message: sprintf("Case '%s' names no party for role '%s'; the portal task cannot be addressed.", $caseUuid, $role)
			);
		}

		return PortalSubject::partyReferenceFor(reference: $reference);
	}//end resolve()

	/**
	 * Look the role up in the case's party lists.
	 *
	 * @param array<string, mixed> $case The case data.
	 * @param string $role The role.
	 *
	 * @return string The raw reference, or ''.
	 */
	private function fromPartyLists(array $case, string $role): string {
		foreach (self::LIST_KEYS as $listKey) {
			$list = ($case[$listKey] ?? null);
			if (is_array($list) === false) {
				continue;
			}

			foreach ($list as $entry) {
				if (is_array($entry) === false || $this->entryHasRole(entry: $entry, role: $role) === false) {
					continue;
				}

				$reference = $this->referenceOf(value: $entry);
				if ($reference !== '') {
					return $reference;
				}
			}
		}

		return '';
	}//end fromPartyLists()

	/**
	 * Whether a list entry is marked with the role, case-insensitively.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param string $role The role.
	 *
	 * @return bool True when one of the role markers equals the role.
	 */
	private function entryHasRole(array $entry, string $role): bool {
		foreach (self::ROLE_MARKERS as $marker) {
			$value = ($entry[$marker] ?? null);
			if (is_scalar($value) === true && strcasecmp(trim((string)$value), $role) === 0) {
				return true;
			}
		}

		return false;
	}//end entryHasRole()

	/**
	 * The reference inside a party value: a scalar is the reference itself; an
	 * array yields the first published key, looking one level into the known
	 * nesting containers.
	 *
	 * @param mixed $value The party value.
	 *
	 * @return string The raw reference, or ''.
	 */
	private function referenceOf(mixed $value): string {
		if (is_scalar($value) === true) {
			return trim((string)$value);
		}

		if (is_array($value) === false) {
			return '';
		}

		foreach (self::REFERENCE_KEYS as $key) {
			$candidate = ($value[$key] ?? null);
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return $this->nestedReferenceOf(value: $value);
	}//end referenceOf()

	/**
	 * The reference one level down, inside the known nesting containers.
	 *
	 * @param array<string, mixed> $value The party value.
	 *
	 * @return string The raw reference, or ''.
	 */
	private function nestedReferenceOf(array $value): string {
		foreach (self::NESTED_KEYS as $key) {
			$nested = ($value[$key] ?? null);
			if (is_array($nested) === true || is_scalar($nested) === true) {
				$reference = $this->referenceOf(value: $nested);
				if ($reference !== '') {
					return $reference;
				}
			}
		}

		return '';
	}//end nestedReferenceOf()
}//end class
