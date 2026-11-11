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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the Collectives pages of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
class ExampleSetCollectivesPages extends ExampleSetEngineRows {

	/**
	 * The page fields a fragment may set.
	 */
	public const FIELDS = ['collective', 'title', 'content', 'owner', 'shareWith'];

	/**
	 * The fields a page needs, with what each holds.
	 */
	private const REQUIRED = ['collective' => 'a collective', 'title' => 'a title', 'content' => 'its content', 'owner' => 'an owner'];

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function part(): string {
		return 'collectivesPages';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function refusal(array $data, array $users): string {
		$rules = new ExampleSetFieldRules();
		return $rules->firstRefusal(
			checks: [
				fn (): string => $rules->unknownRefusal(data: $data, shape: new ExampleSetRowShape(row: 'a collectives page', fields: self::FIELDS)),
				fn (): string => $rules->missingRefusal(data: $data, shape: new ExampleSetRowShape(row: 'a collectives page', required: self::REQUIRED)),
				fn (): string => $rules->strangerRefusal(people: [(string) $data['owner']], users: $users),
				fn (): string => $this->shareRefusal(shareWith: ($data['shareWith'] ?? [])),
			]
		);
	}//end refusal()

	/**
	 * Why the groups a page is shared with are not a list, or ''.
	 *
	 * @param mixed $shareWith The declared groups.
	 *
	 * @return string
	 */
	private function shareRefusal(mixed $shareWith): string {
		if (array_is_list((array) $shareWith) === false) {
			return 'shareWith is a list of groups';
		}

		return '';
	}//end shareRefusal()

	/**
	 * Write one page, in a collective found by name or created.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{
	 *     error: string,
	 *     entry: array{collective: int, createdCollective: bool, page: int, createdPage: bool, owner: string}|null
	 * } The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function write(ExampleSetRow $row): array {
		$data = $row->data;
		$store = $this->store();
		if ($store->available() === false) {
			return ['error' => 'Collectives is not installed', 'entry' => null];
		}

		try {
			$entry = $this->writePage(store: $store, data: $data);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end write()

	/**
	 * Find or write the collective, then the page in it.
	 *
	 * @param ExampleSetCollectivesStore $store The Collectives store.
	 * @param array<string, mixed>       $data  The filled page fields.
	 *
	 * @return array{collective: int, createdCollective: bool, page: int, createdPage: bool, owner: string}
	 */
	private function writePage(ExampleSetCollectivesStore $store, array $data): array {
		$owner      = (string) $data['owner'];
		$collective = $store->collectiveOf(
			team: new CollectiveTeam(owner: $owner, groups: array_map('strval', (array) ($data['shareWith'] ?? []))),
			name: (string) $data['collective']
		);
		$page       = $store->page(
			seat: new CollectiveSeat(collective: $collective['id'], owner: $owner),
			text: new CollectivePageText(title: (string) $data['title'], content: (string) $data['content'])
		);

		return [
			'collective' => $collective['id'], 'createdCollective' => $collective['created'],
			'page'       => $page['id'], 'createdPage' => $page['created'], 'owner' => $owner,
		];
	}//end writePage()

	/**
	 * Whether a written page is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function lost(array $entry): bool {
		try {
			$seat = new CollectiveSeat(collective: (int) $entry['collective'], owner: (string) $entry['owner']);
			return $this->store()->pageFound(seat: $seat, page: (int) $entry['page']) === false;
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function revert(array $entry): string {
		$store = $this->store();
		try {
			$this->deleteWritten(store: $store, entry: $entry);
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Delete the collective when the set wrote it (its pages go with it), else the page when the set wrote that.
	 *
	 * @param ExampleSetCollectivesStore $store The Collectives store.
	 * @param array<string, mixed>       $entry A record entry from write().
	 *
	 * @return void
	 */
	private function deleteWritten(ExampleSetCollectivesStore $store, array $entry): void {
		$owner = (string) $entry['owner'];
		if (($entry['createdCollective'] ?? false) === true) {
			$store->deleteCollective(collective: (int) $entry['collective'], owner: $owner);
			return;
		}

		if (($entry['createdPage'] ?? false) === true) {
			$store->deletePage(seat: new CollectiveSeat(collective: (int) $entry['collective'], owner: $owner), page: (int) $entry['page']);
		}
	}//end deleteWritten()

	/**
	 * The Collectives store.
	 *
	 * @return ExampleSetCollectivesStore
	 */
	private function store(): ExampleSetCollectivesStore {
		return $this->container->get(ExampleSetCollectivesStore::class);
	}//end store()
}//end class
