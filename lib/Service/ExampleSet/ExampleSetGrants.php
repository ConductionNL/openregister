<?php

/**
 * OpenRegister Example Set Grants
 *
 * The grants an example set gives on its objects: checked, made through
 * ObjectSharingService::grant() while the declared account is the active
 * user, so the sharing guard decides as it would for that account's own
 * click, proved and deleted.
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, makes, proves and deletes the grants of an example set.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */
class ExampleSetGrants extends ExampleSetEngineRows {
	/**
	 * The grant fields a fragment may set.
	 */
	public const FIELDS = ['object', 'type', 'shareWith', 'sharedBy', 'permissions', 'verbs', 'sharedAt', 'expiresAt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the sharing service, the share manager and the acting scope on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part grants live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function part(): string {
		return 'grants';
	}//end part()

	/**
	 * The word for one grant, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function noun(): string {
		return 'grant';
	}//end noun()

	/**
	 * Why a filled grant cannot be made, or '', without the set's objects and groups at hand.
	 *
	 * @param array<string, mixed> $data  The filled grant fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a grant field a set may write';
		}

		if (in_array(($data['type'] ?? null), ['user', 'group'], true) === false) {
			return 'type '.(string) json_encode($data['type'] ?? null).' is not user or group';
		}

		if (is_string($data['sharedBy'] ?? null) === false || in_array($data['sharedBy'], $users, true) === false) {
			return 'sharedBy '.(string) json_encode($data['sharedBy'] ?? null).' is no account the set creates or adopts';
		}

		return $this->rightsRefusal(data: $data);
	}//end refusal()

	/**
	 * Why the declared permissions or verbs cannot be granted, or ''.
	 *
	 * @param array<string, mixed> $data The filled grant fields.
	 *
	 * @return string
	 */
	private function rightsRefusal(array $data): string {
		$permissions = ($data['permissions'] ?? 1);
		if (is_int($permissions) === false || $permissions < 1 || $permissions > 31) {
			return 'permissions '.(string) json_encode($permissions).' is not a number from 1 to 31';
		}

		return $this->verbsRefusal(verbs: (array) ($data['verbs'] ?? []));
	}//end rightsRefusal()

	/**
	 * Why one of the declared verbs is not a word, or ''.
	 *
	 * @param array<int|string, mixed> $verbs The declared verbs.
	 *
	 * @return string
	 */
	private function verbsRefusal(array $verbs): string {
		foreach ($verbs as $verb) {
			if (is_string($verb) === false || trim($verb) === '') {
				return 'verb '.(string) json_encode($verb).' is not a word';
			}
		}

		return '';
	}//end verbsRefusal()

	/**
	 * Why a filled grant cannot be made in this run, or '': refusal(), then the object, the principal and the dates.
	 *
	 * @param FilledRow            $row     The filled grant fields and the account ids the set creates or adopts.
	 * @param array<string, mixed> $context The context: the set's `objects`, the groups it created (`setGroups`) and
	 *                                      the install day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	protected function refusalIn(FilledRow $row, array $context): string {
		$why = $this->refusal(data: $row->data, users: $row->users);
		if ($why !== '') {
			return $why;
		}

		if ($this->isObjectOfSet(uuid: ($row->data['object'] ?? null), context: $context) === false) {
			return 'object '.(string) json_encode($row->data['object'] ?? null).' is not an object the set writes';
		}

		$why = $this->principalRefusal(row: $row, context: $context);
		if ($why !== '') {
			return $why;
		}

		return $this->datesRefusal(data: $row->data, context: $context);
	}//end refusalIn()

	/**
	 * Why the grant's principal is no account of the set (a user grant) or no group the set created (a group
	 * grant), or ''.
	 *
	 * @param FilledRow            $row     The filled grant fields (`type` is user or group) and the account ids the
	 *                                      set creates or adopts.
	 * @param array<string, mixed> $context The context; `setGroups` holds the groups the set created.
	 *
	 * @return string
	 */
	private function principalRefusal(FilledRow $row, array $context): string {
		$data       = $row->data;
		$principals = $row->users;
		if ($data['type'] === 'group') {
			$principals = array_values(array_filter((array) ($context['setGroups'] ?? []), 'is_string'));
		}

		if (is_string($data['shareWith'] ?? null) === false || in_array($data['shareWith'], $principals, true) === false) {
			return 'shareWith '.(string) json_encode($data['shareWith'] ?? null).' is no '.$data['type'].' of the set';
		}

		return '';
	}//end principalRefusal()

	/**
	 * Why a declared share or expiry date cannot be used, or '': the share date first; an expiry may not lie before
	 * the install day.
	 *
	 * @param array<string, mixed> $data    The filled grant fields.
	 * @param array<string, mixed> $context The context; `today` is the install day.
	 *
	 * @return string
	 */
	private function datesRefusal(array $data, array $context): string {
		foreach (['sharedAt' => false, 'expiresAt' => true] as $field => $isEnd) {
			if (isset($data[$field]) === false) {
				continue;
			}

			$why = $this->dateRefusal(field: $field, value: $data[$field], context: $context, isEnd: $isEnd);
			if ($why !== '') {
				return $why;
			}
		}

		return '';
	}//end datesRefusal()

	/**
	 * Share the object's folder with the principal, with the declared account as the active user.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{id: string, object: string, node: int, sharedBy: string,
	 *         sharedWith: string, shareType: int}|null}
	 *         The record entry (the share's id, its object and the fields held() matches), or why the grant failed.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		$uuid   = (string) $data['object'];
		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: $uuid);
		if ($object === null) {
			return ['error' => 'no object "'.$uuid.'" to share', 'entry' => null];
		}

		try {
			$grant = $this->actingAs()->run(uid: (string) $data['sharedBy'], operation: fn (): array => $this->grant(object: $object, data: $data));
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => (new ExampleSetGrantIdentity())->entry(uuid: $uuid, grant: $grant)];
	}//end write()

	/**
	 * Share the object's folder through the sharing service, which holds the guard; run as the declared account.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The filled grant fields.
	 *
	 * @return array<string, mixed> The share the service made.
	 */
	private function grant(ObjectEntity $object, array $data): array {
		return $this->sharing()->grantWithTerms(
			object: $object,
			request: new GrantRequest(
				type: (string) $data['type'],
				shareWith: (string) $data['shareWith'],
				terms: (new GrantTerms(
					permissions: (int) ($data['permissions'] ?? 1),
					verbs: array_values((array) ($data['verbs'] ?? []))
				))->dated(expiresAt: ($data['expiresAt'] ?? null), sharedAt: ($data['sharedAt'] ?? null))
			)
		);
	}//end grant()

	/**
	 * The share a record entry holds, or null when there is none under its id or the share there is not the one the
	 * set made (another folder, sharer, principal or type).
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return IShare|null
	 */
	private function held(array $entry): ?IShare {
		if (isset($entry['node']) === false) {
			return null;
		}

		try {
			$share = $this->shares()->getShareById((string) ($entry['id'] ?? ''));
		} catch (Throwable) {
			return null;
		}

		$identity = new ExampleSetGrantIdentity();
		if ($identity->ofShare(share: $share) !== $identity->recorded(entry: $entry)) {
			return null;
		}

		return $share;
	}//end held()

	/**
	 * Whether a share the set made is gone.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function lost(array $entry): bool {
		return $this->held(entry: $entry) === null;
	}//end lost()

	/**
	 * Delete a share the set made; a share under its id that is not the one the set made counts as gone and stays.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed` (also for an entry without the share's
	 *                identity, so the share it names is reported rather than taken for gone).
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function revert(array $entry): string {
		if ($this->actingAs()->refusal() !== '') {
			return 'failed';
		}

		// An entry without the share's identity cannot be matched; the share it names may stay, so it is not removed.
		if (isset($entry['node']) === false) {
			return 'failed';
		}

		$share = $this->held(entry: $entry);
		if ($share === null) {
			return 'removed';
		}

		return $this->deleteShare(share: $share);
	}//end revert()

	/**
	 * Delete a share the set made, once revert() has found it is the set's.
	 *
	 * @param IShare $share The share.
	 *
	 * @return string `removed` or `failed`.
	 */
	private function deleteShare(IShare $share): string {
		try {
			$this->shares()->deleteShare($share);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end deleteShare()

	/**
	 * The object sharing service, which holds the guard.
	 *
	 * @return ObjectSharingService
	 */
	private function sharing(): ObjectSharingService {
		return $this->container->get(ObjectSharingService::class);
	}//end sharing()

	/**
	 * The core share manager.
	 *
	 * @return IManager
	 */
	private function shares(): IManager {
		return $this->container->get(IManager::class);
	}//end shares()

	/**
	 * The scope that runs a call as a cast account.
	 *
	 * @return ExampleSetActingAs
	 */
	private function actingAs(): ExampleSetActingAs {
		return $this->container->get(ExampleSetActingAs::class);
	}//end actingAs()
}//end class
