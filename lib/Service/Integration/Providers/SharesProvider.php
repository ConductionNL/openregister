<?php

/**
 * SharesProvider — exposes NC Shares entities linked to an OpenRegister
 * object via a `[or:{objectUuid}]` marker in the entity's `note`
 * field.
 *
 * Storage strategy is `link-table` — the marker lives in the upstream
 * app's own table (`share`), not in OR.
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

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;

class SharesProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const MARKER_PREFIX = '[or:';

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'shares';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Shares');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Share';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'core';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List linked Shares entities for an OR object.
     *
     * Linking convention: the entity's `note` field contains
     * the marker `[or:{objectUuid}]`. The trait runs the LIKE query;
     * rows are normalised into the registry leaf row shape.
     */
    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $marker = self::MARKER_PREFIX . $objectId . ']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'share',
            markerColumn: 'note',
            marker: $marker,
            extraColumns: ['share_type', 'share_with', 'uid_owner', 'file_target'],
            idColumn: 'id',
        );

        return array_map(static function (array $row): array {
            return [
                'id'    => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['note'] ?? ''),
                'url'   => '/index.php/apps/files/?fileid=' . (string) ($row['id'] ?? ''),
                'data'  => $row,
            ];
        }, $rows);
    }//end list()

    public function health(): array
    {
        $available = $this->isEnabled();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Shares app is not installed',
        ];
    }//end health()
}//end class
