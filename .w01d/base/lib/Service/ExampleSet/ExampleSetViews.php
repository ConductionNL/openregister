<?php

/**
 * OpenRegister Example Set Views
 *
 * The saved views an example set declares, such as a saved search with a
 * count alert that a demo employee owns. A view lives in OpenRegister's view
 * table, not in a register, so the set writes it through the view service,
 * which validates its presentation and alert, and records its uuid. A view
 * belongs to one of the set's own accounts; public, others see it as well.
 * Remove deletes the views the record holds.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ViewService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the saved views of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
class ExampleSetViews extends ExampleSetEngineRows {
	/**
	 * The view fields a fragment may set.
	 */
	public const FIELDS = ['name', 'description', 'owner', 'isPublic', 'query', 'presentation', 'alert', 'sharedWith'];

	/**
	 * The modes a share may carry: read (may use) and write (may update).
	 */
	public const SHARE_MODES = ['read', 'write'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the view service and mapper on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part views live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function part(): string {
		return 'views';
	}//end part()

	/**
	 * The word for one view, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function noun(): string {
		return 'view';
	}//end noun()

	/**
	 * Why a filled view cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled view fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a view field a set may write';
		}

		if (is_string($data['name'] ?? null) === false || trim($data['name']) === '') {
			return 'a view needs a name';
		}

		$owner = (string) ($data['owner'] ?? '');
		if (in_array($owner, $users, true) === false) {
			return 'owner "'.$owner.'" is not an account the set creates or adopts';
		}

		foreach (['query', 'presentation', 'alert'] as $field) {
			if (isset($data[$field]) === true && is_array($data[$field]) === false) {
				return $field.' must be an object';
			}
		}

		return $this->shareRefusal(shares: ($data['sharedWith'] ?? []), users: $users);
	}//end refusal()

	/**
	 * Why a view's shares cannot be written, or ''.
	 *
	 * A share names one user or one group and a mode. A user must be an
	 * account the set creates or adopts, as an owner must.
	 *
	 * @param mixed              $shares The declared shares.
	 * @param array<int, string> $users  The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	private function shareRefusal(mixed $shares, array $users): string {
		if (is_array($shares) === false || array_is_list($shares) === false) {
			return 'sharedWith must be a list';
		}

		foreach ($shares as $share) {
			$user  = (string) (((array) $share)['user'] ?? '');
			$group = (string) (((array) $share)['group'] ?? '');
			if (($user === '') === ($group === '')) {
				return 'a share names one user or one group';
			}

			if ($user !== '' && in_array($user, $users, true) === false) {
				return 'share to "'.$user.'" is not an account the set creates or adopts';
			}

			$mode = (string) (((array) $share)['mode'] ?? 'read');
			if (in_array($mode, self::SHARE_MODES, true) === false) {
				return 'share mode "'.$mode.'" is not one of '.implode(', ', self::SHARE_MODES);
			}
		}

		return '';
	}//end shareRefusal()

	/**
	 * Write a view through the view service, under the uuid its app and key give it.
	 *
	 * A view the set wrote before under that uuid (an earlier install that was removed without its views, or a record
	 * that was lost) is updated where it is, so the uuid every link, menu entry and route names stays valid.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The view's key in the fragment.
	 * @param array<string, mixed> $data The filled view fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function write(string $app, string $key, array $data): array {
		$uuid = self::uuidFor(app: $app, key: $key);
		if ($this->exists(uuid: $uuid) === true) {
			return $this->update(entry: ['uuid' => $uuid], app: $app, key: $key, data: $data);
		}

		try {
			$view = $this->service()->create(
				name: (string) $data['name'],
				description: (string) ($data['description'] ?? ''),
				owner: (string) $data['owner'],
				isPublic: ($data['isPublic'] ?? false) === true,
				isDefault: false,
				query: (array) ($data['query'] ?? []),
				presentation: $this->optional(data: $data, field: 'presentation'),
				sharedWith: $this->shares(declared: ($data['sharedWith'] ?? [])),
				alert: $this->optional(data: $data, field: 'alert'),
			);
			$view->setUuid($uuid);
			$this->mapper()->update($view);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => $uuid]];
	}//end write()

	/**
	 * A refresh updates a view where it is.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function updatesInPlace(): bool {
		return true;
	}//end updatesInPlace()

	/**
	 * Update the view the record holds to its declaration; a view that is gone is written again.
	 *
	 * @param array<string, mixed> $entry The record entry, with the view's uuid.
	 * @param string               $app   The app that ships the fragment.
	 * @param string               $key   The view's key in the fragment.
	 * @param array<string, mixed> $data  The filled view fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the update failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function update(array $entry, string $app, string $key, array $data): array {
		$uuid = (string) ($entry['uuid'] ?? '');
		if ($this->exists(uuid: $uuid) === false) {
			return $this->write(app: $app, key: $key, data: $data);
		}

		try {
			$this->service()->update(
				id: $uuid,
				name: (string) $data['name'],
				description: (string) ($data['description'] ?? ''),
				owner: (string) $data['owner'],
				isPublic: ($data['isPublic'] ?? false) === true,
				isDefault: false,
				query: (array) ($data['query'] ?? []),
				presentation: $this->optional(data: $data, field: 'presentation'),
				sharedWith: $this->shares(declared: ($data['sharedWith'] ?? [])),
				alert: $this->optional(data: $data, field: 'alert'),
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => $uuid]];
	}//end update()

	/**
	 * The uuid a seeded view has: the same for the same app and key, on every instance and every install.
	 *
	 * @param string $app The app that ships the fragment.
	 * @param string $key The view's key in the fragment.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public static function uuidFor(string $app, string $key): string {
		$hash = md5('openregister-example-set-view:'.$app.'/'.$key);

		return sprintf(
			'%s-%s-4%s-8%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);
	}//end uuidFor()

	/**
	 * Whether a view with this uuid exists.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return bool
	 */
	private function exists(string $uuid): bool {
		if ($uuid === '') {
			return false;
		}

		try {
			$this->mapper()->find(id: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end exists()

	/**
	 * An optional structured field of the view, or null when it is not declared.
	 *
	 * @param array<string, mixed> $data  The filled view fields.
	 * @param string               $field The field.
	 *
	 * @return array<mixed>|null
	 */
	private function optional(array $data, string $field): ?array {
		if (isset($data[$field]) === false) {
			return null;
		}

		return (array) $data[$field];
	}//end optional()

	/**
	 * The declared shares as the view stores them: `{user|group, mode}`.
	 *
	 * @param array<int, array<string, mixed>> $declared The checked shares.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function shares(array $declared): array {
		$out = [];
		foreach ($declared as $share) {
			$target = 'group';
			if (isset($share['user']) === true) {
				$target = 'user';
			}

			$out[]  = [$target => (string) $share[$target], 'mode' => (string) ($share['mode'] ?? 'read')];
		}

		return $out;
	}//end shares()

	/**
	 * Whether a written view is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function lost(array $entry): bool {
		try {
			$this->mapper()->find(id: (string) $entry['uuid'], _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return true;
		}

		return false;
	}//end lost()

	/**
	 * Delete a view the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote
	 */
	public function revert(array $entry): string {
		try {
			$view = $this->mapper()->find(id: (string) $entry['uuid'], _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return 'removed';
		}

		try {
			$this->mapper()->delete($view);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The view service.
	 *
	 * @return ViewService
	 */
	private function service(): ViewService {
		return $this->container->get(ViewService::class);
	}//end service()

	/**
	 * The view mapper.
	 *
	 * @return ViewMapper
	 */
	private function mapper(): ViewMapper {
		return $this->container->get(ViewMapper::class);
	}//end mapper()
}//end class
