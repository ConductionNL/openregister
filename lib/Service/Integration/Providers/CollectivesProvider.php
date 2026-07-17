<?php

/**
 * CollectivesProvider — exposes NC Knowledge pages linked to an
 * OpenRegister object via the Tier-2 `openregister_collective_links`
 * table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the page's `slug` field. Tier-2 (this file) reads the dedicated
 * link table instead — the marker convention is retained as a
 * backwards-compat fallback for pages that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `collectives_pages` table is only read for the legacy marker
 * fallback via the wrapping
 * {@see \OCA\OpenRegister\Service\CollectiveLinkService}.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class CollectivesProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const REQUIRED_APP = 'collectives';

    private const MARKER_PREFIX = '[or:';

    /**
     * Constructor.
     *
     * @param IDBConnection        $db                   NC DB connection.
     * @param IAppManager          $appManager           NC app manager.
     * @param IL10N                $l10n                 Localisation.
     * @param CollectiveLinkMapper $collectiveLinkMapper Collective-link mapper (Tier-2 link table).
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        private CollectiveLinkMapper $collectiveLinkMapper,
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
     * List linked Knowledge pages for an OR object.
     *
     * Reads the Tier-2 link table first; if no link rows exist it falls
     * back to the legacy `[or:{uuid}]` marker scan in
     * `collectives_pages.slug` so pages that pre-date the link table
     * still surface.
     *
     * @param string $register Register slug for the parent object.
     * @param string $schema   Schema slug for the parent object.
     * @param string $objectId UUID of the OR object whose rows we want.
     * @param array  $filters  Optional registry filters (unused).
     *
     * @return array<int,array<string,mixed>> List of registry leaf rows.
     *
     * @spec openspec/specs/integration-collectives/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Tier-2 path: read from the link table.
        try {
            $linkRows = $this->collectiveLinkMapper->findByObjectUuid($objectId);
        } catch (Throwable $e) {
            $linkRows = [];
        }

        if (count($linkRows) > 0) {
            return array_map(
                fn (CollectiveLink $link): array => $this->rowFromLink(link: $link),
                $linkRows
            );
        }

        // Backwards-compat fallback: scan the legacy `[or:{uuid}]`
        // marker in `collectives_pages.slug`.
        $marker = self::MARKER_PREFIX.$objectId.']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'collectives_pages',
            markerColumn: 'slug',
            marker: $marker,
            extraColumns: ['emoji', 'last_user_id'],
            idColumn: 'id',
        );

        return array_map(
                static function (array $row): array {
                    return [
                        'id'    => (string) ($row['id'] ?? ''),
                        'title' => (string) ($row['slug'] ?? ''),
                        'url'   => '/index.php/apps/collectives/'.(string) ($row['id'] ?? ''),
                        'data'  => $row,
                    ];
                },
                $rows
                );
    }//end list()

    /**
     * Convert a CollectiveLink row into the registry leaf-row shape.
     *
     * @param CollectiveLink $link Link row from the mapper.
     *
     * @return array<string,mixed>
     */
    private function rowFromLink(CollectiveLink $link): array
    {
        $pageId = (int) $link->getPageId();
        $data   = $link->jsonSerialize();
        $url    = $link->getUrl();
        if ($url === null || $url === '') {
            $url = '/index.php/apps/collectives/?fileId='.$pageId;
        }

        return [
            'id'             => (string) $pageId,
            'title'          => (string) $link->getPageTitle(),
            'url'            => $url,
            'emoji'          => $link->getEmoji(),
            'collectiveName' => $link->getCollectiveName(),
            'data'           => $data,
        ];
    }//end rowFromLink()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *              the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        $status    = 'unavailable';
        $message   = 'NC Knowledge app is not installed';
        if ($available === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class
