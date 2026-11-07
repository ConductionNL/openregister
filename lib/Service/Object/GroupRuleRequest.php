<?php

/**
 * GroupRuleRequest - one question put to an authorization block
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The inputs of {@see PermissionHandler::hasGroupPermission()}.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GroupRuleRequest {

	/**
	 * The group the question is asked for.
	 *
	 * @var string
	 */
	public string $groupId = '';

	/**
	 * A further group of the caller, read for the administrator bypass.
	 *
	 * @var string|null
	 */
	public ?string $userGroup = null;

	/**
	 * The caller, null when anonymous.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The object's owner.
	 *
	 * @var string|null
	 */
	public ?string $objectOwner = null;

	/**
	 * The object data a `match` clause reads.
	 *
	 * @var array|null
	 */
	public ?array $objectData = null;

	/**
	 * The record's own organisation.
	 *
	 * @var string|null
	 */
	public ?string $objectOrganisation = null;

	/**
	 * Create the request.
	 *
	 * @param array|null $authorization The authorization block.
	 * @param string     $action        The action asked for.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly ?array $authorization,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Name the groups asked about.
	 *
	 * @param string      $groupId   The group the question is asked for.
	 * @param string|null $userGroup A further group of the caller.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function forGroup(string $groupId, ?string $userGroup): self {
		$this->groupId   = $groupId;
		$this->userGroup = $userGroup;

		return $this;
	}//end forGroup()

	/**
	 * Name the caller and the object's owner.
	 *
	 * @param string|null $userId      The caller.
	 * @param string|null $objectOwner The object's owner.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function forUser(?string $userId, ?string $objectOwner): self {
		$this->userId      = $userId;
		$this->objectOwner = $objectOwner;

		return $this;
	}//end forUser()

	/**
	 * Name the object a `match` clause reads.
	 *
	 * @param array|null  $data         The object data.
	 * @param string|null $organisation The record's own organisation.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function onObject(?array $data, ?string $organisation): self {
		$this->objectData         = $data;
		$this->objectOrganisation = $organisation;

		return $this;
	}//end onObject()

	/**
	 * Whether the block opts in to public access with `public: true`.
	 *
	 * @return bool True for exactly the boolean true.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function publicOptIn(): bool {
		return (($this->authorization['public'] ?? null) === true);
	}//end publicOptIn()

	/**
	 * The object as a `match` clause reads it.
	 *
	 * ConditionMatcher::getObjectValue() resolves `_organisation` and any other
	 * `_`-prefixed field through `@self`. The record's own organisation wins over
	 * an `@self.organisation` inside the object data, which a request body can carry.
	 *
	 * @return array The envelope.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function matchEnvelope(): array {
		$envelope = ($this->objectData ?? []);
		if ($this->objectOrganisation !== null) {
			$envelope['@self'] = array_replace(($envelope['@self'] ?? []), ['organisation' => $this->objectOrganisation]);
		}

		return $envelope;
	}//end matchEnvelope()
}//end class
