<?php

/**
 * TalkProvider — exposes NC Talk (spreed) conversations linked to an OR
 * object via the IntegrationProvider contract.
 *
 * `link-table` storage (a future `openregister_talk_links` pairs
 * object ↔ conversation token); the wrapping TalkService lands in a
 * follow-up — this provider registers the registry surface today.
 *
 * NB: NC Talk's internal app id is `spreed`, not `talk` — that's what
 * IAppManager resolves against.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-talk/tasks.md
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

class TalkProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'spreed';
    private const ROOM_TAG     = '[or:';

    public function __construct(
        private ContainerInterface $container,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'talk';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Chat');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ChatOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'comms';
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
     * List Talk rooms linked to an OR object.
     *
     * Linking convention: a Talk room whose display name contains the
     * marker `[or:{objectUuid}]`. The provider calls
     * `OCA\Talk\Manager::getRoomsForUser` (the current user's rooms),
     * filters by the marker, and normalises rows into the registry leaf
     * row contract.
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

        $marker = self::ROOM_TAG.$objectId.']';

        try {
            $manager = $this->container->get('OCA\\Talk\\Manager');
            $rooms   = $manager->getRoomsForUser($user->getUID());
        } catch (Throwable $e) {
            // Talk app schema mismatch / un-installed-during-runtime
            // degrades to empty list — AD-23.
            return [];
        }

        $out = [];
        foreach ($rooms as $room) {
            $name = method_exists($room, 'getName') === true ? (string) ($room->getName() ?? '') : '';
            if (method_exists($room, 'getDisplayName') === true) {
                $displayName = (string) $room->getDisplayName($user->getUID());
                if ($displayName !== '') {
                    $name = $displayName;
                }
            }
            if (str_contains($name, $marker) === false) {
                continue;
            }
            $out[] = [
                'id'           => method_exists($room, 'getToken') === true ? (string) $room->getToken() : '',
                'title'        => $name,
                'type'         => method_exists($room, 'getType') === true ? (int) $room->getType() : null,
                'participants' => method_exists($room, 'getActiveSince') === true ? null : null,
                'lastActivity' => method_exists($room, 'getLastActivity') === true && $room->getLastActivity() !== null ? $room->getLastActivity()->getTimestamp() : null,
                'url'          => '/index.php/call/'.(method_exists($room, 'getToken') === true ? $room->getToken() : ''),
            ];
        }

        return $out;
    }//end list()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Talk (spreed) is not installed',
        ];
    }//end health()
}//end class
