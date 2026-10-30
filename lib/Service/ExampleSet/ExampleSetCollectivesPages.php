<?php

/**
 * OpenRegister Example Set Collectives Pages
 *
 * The Collectives pages an example set writes: a fragment's
 * `collectivesPages` part names the collective (found by name or created,
 * with the groups that may read it in its team), the page title, its
 * Markdown and the account that writes it. A work instruction a case type
 * names (`knowledgeBasePage`, "<collective>/<page>") then opens a page that
 * exists. Remove deletes the page the set wrote, and the collective when
 * the set created it.
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
 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the Collectives pages of an example set.
 *
 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
class ExampleSetCollectivesPages extends ExampleSetEngineRows {

	/**
	 * The page fields a fragment may set.
	 */
	public const FIELDS = ['collective', 'title', 'content', 'owner', 'shareWith'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the Collectives store on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part pages live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function part(): string {
		return 'collectivesPages';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function noun(): string {
		return 'collectives page';
	}//end noun()

	/**
	 * Why a row cannot be written, '' when it can.
	 *
	 * @param array<string, mixed> $data  The row's fields, placeholders filled.
	 * @param array<int, string>   $users The accounts the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a collectives page field a set may write';
		}

		$required = ['collective' => 'a collective', 'title' => 'a title', 'content' => 'its content', 'owner' => 'an owner'];
		foreach ($required as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'a collectives page needs '.$what.' ('.$field.')';
			}
		}

		if (in_array((string) $data['owner'], $users, true) === false) {
			return '"'.(string) $data['owner'].'" is not an account the set creates or adopts';
		}

		if (array_is_list((array) ($data['shareWith'] ?? [])) === false) {
			return 'shareWith is a list of groups';
		}

		return '';
	}//end refusal()

	/**
	 * Write one page, in a collective found by name or created.
	 *
	 * @param string               $app  The fragment's app.
	 * @param string               $key  The row key.
	 * @param array<string, mixed> $data The row's fields, placeholders filled.
	 *
	 * @return array{
	 *     error: string,
	 *     entry: array{collective: int, createdCollective: bool, page: int, createdPage: bool, owner: string}|null
	 * } The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function write(string $app, string $key, array $data): array {
		$store = $this->store();
		if ($store->available() === false) {
			return ['error' => 'Collectives is not installed', 'entry' => null];
		}

		$owner = (string) $data['owner'];
		try {
			$collective = $store->collective(
				owner: $owner,
				name: (string) $data['collective'],
				groups: array_map('strval', (array) ($data['shareWith'] ?? []))
			);
			$page       = $store->page(collective: $collective['id'], owner: $owner, title: (string) $data['title'], content: (string) $data['content']);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return [
			'error' => '',
			'entry' => [
				'collective'        => $collective['id'],
				'createdCollective' => $collective['created'],
				'page'              => $page['id'],
				'createdPage'       => $page['created'],
				'owner'             => $owner,
			],
		];
	}//end write()

	/**
	 * Whether a written page is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function lost(array $entry): bool {
		try {
			return $this->store()->pageExists(collective: (int) $entry['collective'], page: (int) $entry['page'], owner: (string) $entry['owner']) === false;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Delete what the set wrote: the page when it created it, and the collective when it created that.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/changes/example-set-collectives-pages/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function revert(array $entry): string {
		$store = $this->store();
		$owner = (string) $entry['owner'];
		try {
			if (($entry['createdCollective'] ?? false) === true) {
				$store->deleteCollective(collective: (int) $entry['collective'], owner: $owner);
				return 'removed';
			}

			if (($entry['createdPage'] ?? false) === true) {
				$store->deletePage(collective: (int) $entry['collective'], page: (int) $entry['page'], owner: $owner);
			}
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The Collectives store.
	 *
	 * @return ExampleSetCollectivesStore
	 */
	private function store(): ExampleSetCollectivesStore {
		return $this->container->get(ExampleSetCollectivesStore::class);
	}//end store()
}//end class
