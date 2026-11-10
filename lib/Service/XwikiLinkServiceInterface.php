<?php

/**
 * XwikiLinkServiceInterface: the part of XwikiLinkService that its controller and the relations aggregation use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\XwikiLink;

/**
 * The methods of XwikiLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface XwikiLinkServiceInterface {

	/**
	 * Whether OpenConnector (which carries the `xwiki` source + credentials)
	 * is installed + enabled. The source itself can still be missing — that
	 * surfaces as `openconnector-source-missing` at call time.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function isOpenConnectorAvailable(): bool;

	/**
	 * Link an existing remote xWiki page to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Page metadata
	 * (title/space/url) is resolved from xWiki via OpenConnector and cached
	 * at link time; when the upstream is unreachable the caller-supplied
	 * reference is stored with best-effort metadata so the link survives.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $pageReference Remote xWiki page reference (URL or `Space.Page`).
	 *
	 * @return XwikiLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty reference (400),
	 *                   duplicate (409), source unconfigured/upstream down (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkPage(LinkTarget $target, string $pageReference): XwikiLink;

	/**
	 * Create a new page in remote xWiki via OpenConnector and link it.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param XwikiPageDraft $draft The target xWiki space and the new page title.
	 *
	 * @return XwikiLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty title/space (400),
	 *                   source unconfigured/upstream down (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkPage(LinkTarget $target, XwikiPageDraft $draft): XwikiLink;

	/**
	 * Unlink a page from an object.
	 *
	 * Does NOT delete the page itself — it stays in the remote xWiki
	 * instance for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $pageReference Canonical xWiki page reference.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkPage(string $objectUuid, string $pageReference): void;

	/**
	 * Return the linked pages for an object, refreshing cached
	 * title/space/url from remote xWiki when a source is configured and a
	 * row's cache is older than 24h.
	 *
	 * Never throws on an upstream failure — stale rows are returned as-is
	 * so historical references survive even when xWiki is down (AD-23).
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedPages(string $objectUuid): array;

	/**
	 * Browse the remote xWiki pages available to link (picker source).
	 *
	 * Delegates to the XwikiProvider's list/browse path through
	 * OpenConnector. Returns the unconfigured-source descriptor when no
	 * `xwiki` source exists (or the upstream is unreachable) so the picker
	 * can render its Configure-XWiki CTA rather than crash (AD-23).
	 *
	 * @param string|null $search Optional title/reference substring filter.
	 *
	 * @return array<string,mixed> `{ results, total }` on success, or
	 *                             `{ unavailable, cause, results, total }`
	 *                             when the source is unconfigured/down.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailablePages(?string $search = null): array;

	/**
	 * Free-text search of the remote xWiki knowledge base — object-independent.
	 *
	 * This is the query-first surface a consuming app (e.g. a dashboard widget)
	 * uses to find pages by free text, without an OR object context. It shares
	 * the XwikiProvider browse path with {@see getAvailablePages()} but exposes
	 * a paginated `{ results, total, limit, offset }` envelope and resolves the
	 * xWiki base URL exclusively from the OpenConnector `xwiki` source (AD-4) —
	 * the consumer never holds a direct xWiki URL.
	 *
	 * Read-only and null-safe: when no `xwiki` source is configured (or the
	 * upstream is unreachable) it returns the unconfigured-source descriptor
	 * `{ unavailable, cause, results: [], total: 0, limit, offset }` rather than
	 * throwing, so the caller degrades to an empty/Configure state and never a
	 * fatal (AD-23).
	 *
	 * @param ListQuery $query Free-text search (title/content; none lists the most recent / available pages),
	 *                         limit (clamped 1..100) and offset (clamped >= 0; none is 0).
	 *
	 * @return array<string,mixed> `{ results, total, limit, offset }` on
	 *                             success, or `{ unavailable, cause, results,
	 *                             total, limit, offset }` when the source is
	 *                             unconfigured/down.
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function searchPages(ListQuery $query): array;
}//end interface
