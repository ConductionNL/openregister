<?php

/**
 * CollectivesProvider — exposes NC Collectives knowledge pages linked
 * to an OpenRegister object via the IntegrationProvider contract.
 *
 * Storage strategy is `link-table` — the link is encoded as a
 * `[or:{objectUuid}]` marker that lives in the page's slug field on
 * the NC Collectives side. Looking up linked pages therefore goes
 * through the upstream app's own service + mapper classes
 * (`OCA\Collectives\Service\CollectiveService` and
 * `OCA\Collectives\Db\PageMapper`) rather than a raw LIKE query — that
 * way slug normalisation, soft-deletes (`trash_timestamp`), and the
 * Page entity's full attribute surface (emoji, last_user_id, …) are
 * honoured per Collectives' own contract.
 *
 * Resolution is lazy via `\OCP\Server::get()` and guarded by
 * `class_exists()` so the provider remains constructible when NC
 * Collectives is not installed — `isEnabled()` short-circuits the
 * `list()` call to an empty array in that case (AD-23).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\Collectives\Db\Page as CollectivesPage;
use OCA\Collectives\Db\PageMapper as CollectivesPageMapper;
use OCA\Collectives\Service\CollectiveService;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collectives (Knowledge) integration provider.
 *
 * Lists Collectives pages whose `slug` carries the OR marker for
 * the owning object. `OCA\Collectives\Service\CollectiveService` is
 * imported (and lazily resolved on read paths) so the provider stays
 * inside the upstream app's service contract — no raw LIKE queries
 * against `collectives_pages` — and dies-gracefully when Collectives
 * is uninstalled.
 */
class CollectivesProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'collectives';

    /**
     * Marker prefix encoded into a Collectives page's slug field to
     * associate the page with the owning OR object.
     *
     * @var string
     */
    private const MARKER_PREFIX = '[or:';

    /**
     * Constructor.
     *
     * The `(db, appManager, l10n)` shape mirrors the canonical
     * link-table-backed leaf signature and is what
     * `LeafProvidersMetadataTest::buildGreenfieldProvider` constructs.
     * Real upstream classes (`CollectivesPageMapper`, `CollectiveService`)
     * are resolved lazily inside `list()` so the provider remains
     * constructible when NC Collectives is not installed.
     *
     * @param IDBConnection $db         NC DB connection (kept for fallback / introspection).
     * @param IAppManager   $appManager NC app manager (drives `isEnabled()`).
     * @param IL10N         $l10n       Localisation.
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'collectives';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Knowledge');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'BookOpenPageVariant';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'docs';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List Collectives pages linked to an OR object.
     *
     * Linking convention: the page's `slug` field contains the
     * `[or:{objectUuid}]` marker. Resolution flows through the
     * upstream `OCA\Collectives\Db\PageMapper` (NOT a raw LIKE on
     * `collectives_pages`) so soft-deleted pages and slug
     * normalisation are honoured by the source-of-truth class.
     *
     * Returns an empty list — never throws — when:
     *   - NC Collectives is not installed,
     *   - the upstream classes can't be resolved (e.g. unit-test
     *     environment without a real DI container),
     *   - or no pages match the marker.
     *
     * @param string              $register Register slug or numeric id (unused — pages resolve by marker).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId UUID of the OR object whose linked pages to list.
     * @param array<string,mixed> $filters  Optional registry filters (currently ignored).
     *
     * @return array<int,array<string,mixed>> List of registry leaf rows.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Lazy-resolve so the provider is constructible without
        // Collectives installed. class_exists() is the safety net for
        // unit-test environments that mock IAppManager but don't load
        // the optional app's source. We probe both the Service and the
        // Mapper to assert the full upstream contract is loadable
        // before any read attempt — partial installs degrade to empty.
        if (class_exists(CollectivesPageMapper::class) === false
            || class_exists(CollectiveService::class) === false
        ) {
            return [];
        }

        $pageMapper = $this->resolvePageMapper();
        if ($pageMapper === null) {
            return [];
        }

        $marker = self::MARKER_PREFIX.$objectId.']';

        try {
            $allPages = $pageMapper->getAll();
        } catch (Throwable $e) {
            // Schema mismatch, DI container missing in unit tests, or
            // upstream service crashed — degrade to empty list (AD-23).
            Server::get(LoggerInterface::class)->debug(
                '[CollectivesProvider] page lookup failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }

        $rows = [];
        foreach ($allPages as $page) {
            /** @var CollectivesPage $page */
            $slug = (string) ($page->getSlug() ?? '');
            if ($slug === '' || str_contains($slug, $marker) === false) {
                continue;
            }

            // Soft-deleted page — skip; the link record stays valid but
            // the page is in trash, so the registry surface treats it
            // as absent.
            if ($page->getTrashTimestamp() !== null) {
                continue;
            }

            $rows[] = [
                'id'    => (string) $page->getId(),
                'title' => $slug,
                'url'   => '/index.php/apps/collectives/p/'.(string) $page->getId(),
                'data'  => [
                    'id'           => $page->getId(),
                    'fileId'       => $page->getFileId(),
                    'slug'         => $slug,
                    'lastUserId'   => $page->getLastUserId(),
                    'emoji'        => $page->getEmoji(),
                ],
            ];
        }

        return $rows;
    }//end list()

    /**
     * Resolve the Collectives PageMapper via the NC DI container.
     *
     * Extracted as a protected seam so unit tests can override it with
     * a stub mapper without needing the full NC DI container — see
     * {@see \OCA\OpenRegister\Tests\Unit\Service\Integration\Providers\CollectivesProviderTest}.
     *
     * Returns null on any resolution failure so `list()` degrades to
     * an empty array per AD-23.
     *
     * @return CollectivesPageMapper|null Resolved mapper or null when unreachable.
     */
    protected function resolvePageMapper(): ?CollectivesPageMapper
    {
        try {
            /** @var CollectivesPageMapper $mapper */
            $mapper = Server::get(CollectivesPageMapper::class);
            return $mapper;
        } catch (Throwable $e) {
            Server::get(LoggerInterface::class)->debug(
                '[CollectivesProvider] PageMapper resolution failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }
    }//end resolvePageMapper()

    /**
     * Health descriptor.
     *
     * Mirrors the DeckProvider / FormsProvider shape: `ok` when NC
     * Collectives is installed, `unavailable` otherwise. `authStatus`
     * is always `configured` because Collectives uses inherited NC
     * user auth — no provider-level credentials.
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Collectives app is not installed',
        ];
    }//end health()
}//end class
