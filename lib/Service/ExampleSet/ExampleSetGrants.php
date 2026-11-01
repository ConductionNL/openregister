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

use DateTime;
use OCA\OpenRegister\Service\Party\PartyService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\Share\IManager;
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

		$permissions = ($data['permissions'] ?? 1);
		if (is_int($permissions) === false || $permissions < 1 || $permissions > 31) {
			return 'permissions '.(string) json_encode($permissions).' is not a number from 1 to 31';
		}

		foreach ((array) ($data['verbs'] ?? []) as $verb) {
			if (is_string($verb) === false || trim($verb) === '') {
				return 'verb '.(string) json_encode($verb).' is not a word';
			}
		}

		return '';
	}//end refusal()

	/**
	 * Why a filled grant cannot be made in this run, or '': refusal(), then the object, the principal and the dates.
	 *
	 * @param array<string, mixed> $data    The filled grant fields.
	 * @param array<int, string>   $users   The account ids the set creates or adopts.
	 * @param array<string, mixed> $context The context: the set's `objects`, its `cast` and the install day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function refusalIn(array $data, array $users, array $context): string {
		$why = $this->refusal(data: $data, users: $users);
		if ($why !== '') {
			return $why;
		}

		if ($this->isObjectOfSet(uuid: ($data['object'] ?? null), context: $context) === false) {
			return 'object '.(string) json_encode($data['object'] ?? null).' is not an object the set writes';
		}

		$principals = $users;
		if ($data['type'] === 'group') {
			$principals = $this->castGroups(context: $context);
		}

		if (is_string($data['shareWith'] ?? null) === false || in_array($data['shareWith'], $principals, true) === false) {
			return 'shareWith '.(string) json_encode($data['shareWith'] ?? null).' is no '.$data['type'].' of the set';
		}

		foreach (['sharedAt' => false, 'expiresAt' => true] as $field => $isEnd) {
			if (isset($data[$field]) === true) {
				$why = $this->dateRefusal(field: $field, value: $data[$field], context: $context, isEnd: $isEnd);
			}

			if ($why !== '') {
				return $why;
			}
		}

		return '';
	}//end refusalIn()

	/**
	 * Share the object's folder with the principal, with the declared account as the active user.
	 *
	 * @param string               $app  The app that ships the fragment (unused: the share is the account's).
	 * @param string               $key  The grant's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled grant fields.
	 *
	 * @return array{error: string, entry: array{id: string, object: string}|null} The record entry, or why the grant failed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $app and $key are the contract of every kind.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$uuid   = (string) $data['object'];
		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: $uuid);
		if ($object === null) {
			return ['error' => 'no object "'.$uuid.'" to share', 'entry' => null];
		}

		try {
			$grant = $this->actingAs()->run(
				uid: (string) $data['sharedBy'],
				operation: fn (): array => $this->sharing()->grant(
					object: $object,
					type: (string) $data['type'],
					shareWith: (string) $data['shareWith'],
					permissions: (int) ($data['permissions'] ?? 1),
					verbs: array_values((array) ($data['verbs'] ?? [])),
					expiresAt: $this->moment(value: ($data['expiresAt'] ?? null)),
					sharedAt: $this->moment(value: ($data['sharedAt'] ?? null))
				)
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['id' => (string) $grant['id'], 'object' => $uuid]];
	}//end write()

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
		try {
			$this->shares()->getShareById((string) ($entry['id'] ?? ''));
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a share the set made.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$share = $this->shares()->getShareById((string) ($entry['id'] ?? ''));
		} catch (Throwable) {
			return 'removed';
		}

		try {
			$this->shares()->deleteShare($share);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The gids of the cast's groups.
	 *
	 * @param array<string, mixed> $context The context; `cast` holds every cast entry by key.
	 *
	 * @return array<int, string>
	 */
	private function castGroups(array $context): array {
		$gids = [];
		foreach ((array) ($context['cast'] ?? []) as $entry) {
			if (is_array($entry) === true && is_string($entry['gid'] ?? null) === true && $entry['gid'] !== '') {
				$gids[] = $entry['gid'];
			}
		}

		return $gids;
	}//end castGroups()

	/**
	 * A checked date as a moment, or null when it is not declared.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return DateTime|null
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return new DateTime($value);
	}//end moment()

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
