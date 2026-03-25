<?php

/**
 * OpenRegister Contacts Controller
 *
 * API controller for the contact matching endpoint.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ContactsController handles the contact matching API endpoint.
 *
 * Provides GET /api/contacts/match for matching contact metadata
 * against OpenRegister entities, reusable by the mail-sidebar feature.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class ContactsController extends Controller
{

    /**
     * The contact matching service.
     *
     * @var ContactMatchingService
     */
    private readonly ContactMatchingService $matchingService;

    /**
     * The deep link registry service.
     *
     * @var DeepLinkRegistryService
     */
    private readonly DeepLinkRegistryService $deepLinkRegistry;

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private readonly IL10N $l10n;

    /**
     * Logger for debugging.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor for ContactsController.
     *
     * @param string                  $appName          The app name
     * @param IRequest                $request          The HTTP request
     * @param ContactMatchingService  $matchingService  The contact matching service
     * @param DeepLinkRegistryService $deepLinkRegistry The deep link registry
     * @param IL10N                   $l10n             The localization service
     * @param LoggerInterface         $logger           The logger
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ContactMatchingService $matchingService,
        DeepLinkRegistryService $deepLinkRegistry,
        IL10N $l10n,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->matchingService  = $matchingService;
        $this->deepLinkRegistry = $deepLinkRegistry;
        $this->l10n             = $l10n;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * Match contacts against OpenRegister entities.
     *
     * GET /api/contacts/match?email={email}&name={name}&organization={organization}
     *
     * At least one of email or name must be provided.
     *
     * @return JSONResponse The match results
     *
     * @NoAdminRequired
     */
    public function match(): JSONResponse
    {
        $email        = $this->request->getParam('email', '');
        $name         = $this->request->getParam('name', '');
        $organization = $this->request->getParam('organization', '');

        // Validate input: at least email or name must be provided.
        if (empty($email) === true && empty($name) === true) {
            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('At least email or name must be provided'),
                    'matches' => [],
                    'total'   => 0,
                    'cached'  => false,
                ],
                400
            );
        }

        try {
            $matches = $this->matchingService->matchContact(
                (string) $email,
                empty($name) === false ? (string) $name : null,
                empty($organization) === false ? (string) $organization : null
            );

            // Enrich matches with URL and icon from deep link registry.
            $enrichedMatches = $this->enrichMatches($matches);

            // Determine if any results came from cache.
            $anyCached = false;
            foreach ($enrichedMatches as $match) {
                if (($match['cached'] ?? false) === true) {
                    $anyCached = true;
                    break;
                }
            }

            return new JSONResponse(
                [
                    'matches' => $enrichedMatches,
                    'total'   => count($enrichedMatches),
                    'cached'  => $anyCached,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[ContactsAPI] Match failed: {error}',
                [
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]
            );

            return new JSONResponse(
                [
                    'error'   => $this->l10n->t('Internal server error'),
                    'matches' => [],
                    'total'   => 0,
                    'cached'  => false,
                ],
                500
            );
        }
    }//end match()

    /**
     * Enrich match results with URL and icon from deep link registry.
     *
     * @param array $matches The match results
     *
     * @return array The enriched match results
     */
    private function enrichMatches(array $matches): array
    {
        return array_map(
            function (array $match): array {
                $registerId = (int) ($match['register']['id'] ?? 0);
                $schemaId   = (int) ($match['schema']['id'] ?? 0);

                $url  = $this->deepLinkRegistry->resolveUrl(
                    $registerId,
                    $schemaId,
                    $match
                );
                $icon = $this->deepLinkRegistry->resolveIcon(
                    $registerId,
                    $schemaId
                );

                $match['url']  = $url;
                $match['icon'] = $icon;

                return $match;
            },
            $matches
        );
    }//end enrichMatches()
}//end class
