<?php

/**
 * CollectivesPageService wraps Nextcloud Collectives REST API for OpenRegister integration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * CollectivesPageService manages Collectives page-to-object links.
 *
 * Wraps the Collectives REST API to list collectives, list pages, and fetch
 * page content. Links are stored in the openregister_collective_links table.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class CollectivesPageService
{
    /**
     * Constructor.
     *
     * @param CollectiveLinkMapper $collectiveLinkMapper Collective link mapper
     * @param IAppManager          $appManager           Nextcloud app manager
     * @param IClientService       $clientService        HTTP client service
     * @param IURLGenerator        $urlGenerator         URL generator
     * @param IUserSession         $userSession          User session
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        private readonly CollectiveLinkMapper $collectiveLinkMapper,
        private readonly IAppManager $appManager,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether the Collectives app is installed and enabled.
     *
     * @return bool True when Collectives is available.
     */
    public function isCollectivesAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('collectives');
    }//end isCollectivesAvailable()

    /**
     * Get all collective links for an object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array, total: int}
     */
    public function getLinksForObject(string $objectUuid): array
    {
        $links = $this->collectiveLinkMapper->findByObjectUuid($objectUuid);

        $results = array_map(
            static function (CollectiveLink $link): array {
                return $link->jsonSerialize();
            },
            $links
        );

        return ['results' => $results, 'total' => count($results)];
    }//end getLinksForObject()

    /**
     * List collectives accessible to the current user via internal API.
     *
     * Returns an array of collective descriptors (name, title) using the Collectives
     * internal PHP service when available; falls back to an empty list with a warning.
     *
     * @return array Array of collective info arrays.
     */
    public function listCollectives(): array
    {
        if ($this->isCollectivesAvailable() === false) {
            return [];
        }

        try {
            if (class_exists('OCA\Collectives\Service\CollectiveService') === true) {
                $service     = \OC::$server->get('OCA\Collectives\Service\CollectiveService');
                $user        = $this->userSession->getUser();
                $collectives = $service->getCollectives($user?->getUID() ?? '');

                return array_map(
                    static function ($collective): array {
                        $id    = method_exists($collective, 'getId') === true ? $collective->getId() : 0;
                        $name  = method_exists($collective, 'getName') === true ? $collective->getName() : '';
                        $emoji = method_exists($collective, 'getEmoji') === true ? ($collective->getEmoji() ?? '') : '';
                        $title = ($emoji !== '' ? $emoji.' ' : '').$name;
                        return ['id' => $id, 'name' => $name, 'title' => $title];
                    },
                    $collectives
                );
            }
        } catch (Exception $e) {
            $this->logger->warning(
                'CollectivesPageService: listCollectives failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

        return [];
    }//end listCollectives()

    /**
     * List pages within a collective accessible to the current user.
     *
     * @param string $collectiveName The collective name (slug).
     *
     * @return array Array of page info arrays.
     */
    public function listPages(string $collectiveName): array
    {
        if ($this->isCollectivesAvailable() === false) {
            return [];
        }

        try {
            if (class_exists('OCA\Collectives\Service\PageService') === true) {
                $pageService = \OC::$server->get('OCA\Collectives\Service\PageService');
                $user        = $this->userSession->getUser();
                $pages       = $pageService->findAll($user?->getUID() ?? '', $collectiveName);

                return array_map(
                    function ($page) use ($collectiveName): array {
                        $pageId = method_exists($page, 'getId') === true ? $page->getId() : 0;
                        return [
                            'id'             => $pageId,
                            'title'          => method_exists($page, 'getTitle') === true ? $page->getTitle() : '',
                            'collectiveName' => $collectiveName,
                            'url'            => $this->buildPageUrl(collectiveName: $collectiveName, pageId: $pageId),
                        ];
                    },
                    $pages
                );
            }
        } catch (Exception $e) {
            $this->logger->warning(
                'CollectivesPageService: listPages failed for collective "'.$collectiveName.'": '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

        return [];
    }//end listPages()

    /**
     * Fetch the markdown content of a page.
     *
     * Returns null when the page is inaccessible (ACL denied or app unavailable).
     *
     * @param string $collectiveName The collective name (slug).
     * @param int    $pageId         The page ID.
     *
     * @return string|null Markdown content or null on access failure.
     */
    public function getPageContent(string $collectiveName, int $pageId): ?string
    {
        if ($this->isCollectivesAvailable() === false) {
            return null;
        }

        try {
            if (class_exists('OCA\Collectives\Service\PageService') === true) {
                $pageService = \OC::$server->get('OCA\Collectives\Service\PageService');
                $user        = $this->userSession->getUser();
                $page        = $pageService->find($user?->getUID() ?? '', $collectiveName, $pageId);

                if ($page === null) {
                    return null;
                }

                return method_exists($page, 'getContent') === true ? $page->getContent() : null;
            }
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        } catch (Exception $e) {
            $this->logger->info(
                'CollectivesPageService: getPageContent denied for page '.$pageId.': '.$e->getMessage()
            );
        }

        return null;
    }//end getPageContent()

    /**
     * Link an existing Collectives page to an object.
     *
     * @param string $objectUuid     The object UUID.
     * @param string $collectiveName The collective name (slug).
     * @param int    $pageId         The page ID.
     * @param string $pageTitle      Cached page title.
     *
     * @return CollectiveLink The created link.
     *
     * @throws Exception When a duplicate link exists or the user is not logged in.
     */
    public function linkPage(
        string $objectUuid,
        string $collectiveName,
        int $pageId,
        string $pageTitle=''
    ): CollectiveLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $existing = $this->collectiveLinkMapper->findByObjectAndPage($objectUuid, $pageId);
        if ($existing !== null) {
            throw new Exception('Page already linked to this object', 409);
        }

        $link = new CollectiveLink();
        $link->setObjectUuid($objectUuid);
        $link->setCollectiveName($collectiveName);
        $link->setPageId($pageId);
        $link->setPageTitle($pageTitle !== '' ? $pageTitle : null);
        $link->setPageUrl($this->buildPageUrl(collectiveName: $collectiveName, pageId: $pageId));
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->collectiveLinkMapper->insert($link);
    }//end linkPage()

    /**
     * Remove a collective link by link ID.
     *
     * @param int $linkId The link table row ID.
     *
     * @return void
     *
     * @throws Exception When the link is not found.
     */
    public function unlinkPage(int $linkId): void
    {
        try {
            $link = $this->collectiveLinkMapper->find($linkId);
            $this->collectiveLinkMapper->delete($link);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new Exception('Collective link not found', 404);
        }
    }//end unlinkPage()

    /**
     * Remove all collective links for an object (cleanup on object deletion).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted links.
     */
    public function deleteLinksForObject(string $objectUuid): int
    {
        return $this->collectiveLinkMapper->deleteByObjectUuid($objectUuid);
    }//end deleteLinksForObject()

    /**
     * Build a Nextcloud-relative URL to open a page in the Collectives app.
     *
     * @param string $collectiveName The collective name.
     * @param int    $pageId         The page ID.
     *
     * @return string Absolute URL to the page.
     */
    private function buildPageUrl(string $collectiveName, int $pageId): string
    {
        return $this->urlGenerator->linkToRouteAbsolute(
            routeName: 'collectives.start.index'
        ).'@'.$collectiveName.'/'.$pageId;
    }//end buildPageUrl()
}//end class
