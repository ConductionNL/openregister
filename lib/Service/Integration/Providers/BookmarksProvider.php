<?php

/**
 * BookmarksProvider — exposes NC Bookmarks linked to an OpenRegister
 * object via a tag convention.
 *
 * Bookmarks are linked by tagging them `or:{objectUuid}` in NC
 * Bookmarks. The provider queries BookmarkMapper::findAll filtered by
 * that tag; rows are normalised into the shared leaf row contract.
 *
 * `link-table` storage strategy — the link lives in NC Bookmarks' own
 * tag table, not in OR.
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
 * @spec openspec/changes/integration-bookmarks/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

class BookmarksProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'bookmarks';
    private const TAG_PREFIX   = 'or:';

    public function __construct(
        private ContainerInterface $container,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'bookmarks';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Bookmarks');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Bookmark';
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
     * List bookmarks tagged with `or:{objectId}` for the current user.
     *
     * BookmarkMapper::findAll honours QueryParameters::setTags; we
     * inject the per-object tag and normalise the response rows into
     * the registry leaf row shape (id, title, description, url, tags).
     *
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (unused).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $userId = $user->getUID();
        $tag    = self::TAG_PREFIX.$objectId;

        try {
            $mapper          = $this->container->get('OCA\\Bookmarks\\Db\\BookmarkMapper');
            $queryParameters = new \OCA\Bookmarks\QueryParameters();
            $queryParameters->setTags([$tag]);
            $bookmarks = $mapper->findAll($userId, $queryParameters);
        } catch (Throwable $e) {
            // Bookmarks app schema mismatch or missing tag column on
            // older installs degrades to an empty list — AD-23.
            return [];
        }

        return array_map(static function ($bookmark): array {
            $arr = method_exists($bookmark, 'toArray') === true ? $bookmark->toArray() : (array) $bookmark;
            return [
                'id'          => (string) ($arr['id'] ?? ''),
                'title'       => (string) ($arr['title'] ?? ($arr['url'] ?? '')),
                'description' => (string) ($arr['description'] ?? ''),
                'url'         => (string) ($arr['url'] ?? ''),
                'tags'        => $arr['tags'] ?? [],
                'added'       => isset($arr['added']) === true ? (int) $arr['added'] : null,
            ];
        }, $bookmarks);
    }//end list()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Bookmarks app is not installed',
        ];
    }//end health()
}//end class
