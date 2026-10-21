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
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
class ExampleSetViews extends ExampleSetEngineRows {
	/**
	 * The view fields a fragment may set.
	 */
	public const FIELDS = ['name', 'description', 'owner', 'isPublic', 'query', 'presentation', 'alert'];

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
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function part(): string {
		return 'views';
	}//end part()

	/**
	 * The word for one view, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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

		return '';
	}//end refusal()

	/**
	 * Write a view through the view service.
	 *
	 * @param string               $app  The app that ships the fragment (unused: a view names its owner).
	 * @param string               $key  The view's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled view fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function write(string $app, string $key, array $data): array {
		$presentation = null;
		if (isset($data['presentation']) === true) {
			$presentation = (array) $data['presentation'];
		}

		$alert = null;
		if (isset($data['alert']) === true) {
			$alert = (array) $data['alert'];
		}

		try {
			$view = $this->service()->create(
				name: (string) $data['name'],
				description: (string) ($data['description'] ?? ''),
				owner: (string) $data['owner'],
				isPublic: ($data['isPublic'] ?? false) === true,
				isDefault: false,
				query: (array) ($data['query'] ?? []),
				presentation: $presentation,
				alert: $alert,
			);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['uuid' => (string) $view->getUuid()]];
	}//end write()

	/**
	 * Whether a written view is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote
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
