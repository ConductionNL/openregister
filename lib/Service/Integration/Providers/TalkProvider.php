<?php

/**
 * TalkProvider — NC Talk integration for the OpenRegister integration registry.
 *
 * Routes both conversation-listing (ConversationController logic) and
 * chat/message operations (ChatService logic) through a single provider so
 * the registry sees exactly ONE 'talk' integration (AD-1 of the design).
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
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TalkProvider integrates NC Talk (Spreed) with OpenRegister objects.
 *
 * A single provider with id='talk' routes both conversation-listing and
 * chat-message operations. It is only active when the 'spreed' NC app is
 * installed (requiredApp = 'spreed'). Permission enforcement is delegated
 * entirely to Talk's own room-ACL layer (requiresPermission = null).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */
class TalkProvider extends IntegrationProvider
{

    /**
     * Integration identifier — must match the frontend registration.
     */
    private const ID = 'talk';

    /**
     * Talk's NC app ID — the provider is invisible when this app is absent.
     */
    private const REQUIRED_APP = 'spreed';

    /**
     * TalkLink mapper for the link table.
     *
     * @var TalkLinkMapper
     */
    private readonly TalkLinkMapper $talkLinkMapper;

    /**
     * Chat service — provides message-sending and history retrieval.
     *
     * @var ChatService
     */
    private readonly ChatService $chatService;

    /**
     * App manager — used to verify the 'spreed' dependency.
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * User session — identifies the acting user for link ownership.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param TalkLinkMapper  $talkLinkMapper Talk link mapper.
     * @param ChatService     $chatService    Chat service for message operations.
     * @param IAppManager     $appManager     App manager for dependency checks.
     * @param IUserSession    $userSession    User session for current-user context.
     * @param LoggerInterface $logger         Logger.
     *
     * @return void
     */
    public function __construct(
        TalkLinkMapper $talkLinkMapper,
        ChatService $chatService,
        IAppManager $appManager,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->talkLinkMapper = $talkLinkMapper;
        $this->chatService    = $chatService;
        $this->appManager     = $appManager;
        $this->userSession    = $userSession;
        $this->logger         = $logger;
    }//end __construct()

    /**
     * Returns the unique identifier for this integration.
     *
     * @return string Always 'talk'.
     */
    public function getId(): string
    {
        return self::ID;
    }//end getId()

    /**
     * Returns the human-readable label shown in the UI.
     *
     * @return string 'Chat'
     */
    public function getLabel(): string
    {
        return 'Chat';
    }//end getLabel()

    /**
     * Returns the MDI icon name for this integration.
     *
     * @return string 'ChatOutline'
     */
    public function getIcon(): string
    {
        return 'ChatOutline';
    }//end getIcon()

    /**
     * Returns the logical grouping key used for tab/widget ordering.
     *
     * @return string 'comms'
     */
    public function getGroup(): string
    {
        return 'comms';
    }//end getGroup()

    /**
     * Returns the NC app that must be installed for this provider to appear.
     *
     * @return string 'spreed'
     */
    public function getRequiredApp(): string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Returns the storage strategy; Talk uses the link-table approach.
     *
     * @return string 'link-table'
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * Returns null — Talk's own room ACL governs visibility transitively.
     *
     * @return null Always null.
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Returns whether Nextcloud Talk (spreed) is installed and enabled.
     *
     * @return bool True when spreed is available.
     */
    public function isTalkAvailable(): bool
    {
        return $this->appManager->isInstalled(appId: self::REQUIRED_APP);
    }//end isTalkAvailable()

    /**
     * List Talk conversations linked to a specific OR object.
     *
     * Delegates to TalkLinkMapper (conversation-listing path per AD-1).
     * Conversations that are missing or inaccessible in Talk are filtered
     * out rather than surfaced as errors (graceful-degradation contract).
     *
     * @param string $objectUuid The OR object UUID.
     * @param int    $limit      Maximum items to return (default: 20).
     * @param int    $offset     Pagination offset (default: 0).
     *
     * @return array<int,array<string,mixed>> Serialised TalkLink records.
     *
     * @spec openspec/changes/integration-talk/tasks.md#task-1
     */
    public function listForObject(string $objectUuid, int $limit = 20, int $offset = 0): array
    {
        if ($this->isTalkAvailable() === false) {
            return [];
        }

        try {
            $links = $this->talkLinkMapper->findByObjectUuid(
                objectUuid: $objectUuid,
                limit: $limit,
                offset: $offset
            );

            return array_values(array_map(
                static fn(TalkLink $link) => $link->jsonSerialize(),
                $links
            ));
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[TalkProvider] Failed to list conversations for object',
                context: [
                    'objectUuid' => $objectUuid,
                    'error'      => $e->getMessage(),
                ]
            );

            return [];
        }//end try
    }//end listForObject()

    /**
     * Link a Talk conversation to an OR object.
     *
     * Delegates to the link-table storage (chat-operation path per AD-1).
     * Expects $data to contain at least 'conversationToken'; 'conversationName'
     * is optional and will be cached for display purposes.
     *
     * @param string               $objectUuid The OR object UUID.
     * @param array<string,mixed>  $data       Must include 'conversationToken'; 'conversationName' optional.
     *
     * @return array<string,mixed> The persisted TalkLink serialised.
     *
     * @throws \InvalidArgumentException When 'conversationToken' is missing.
     *
     * @spec openspec/changes/integration-talk/tasks.md#task-1
     */
    public function linkToObject(string $objectUuid, array $data): array
    {
        if (empty($data['conversationToken']) === true) {
            throw new \InvalidArgumentException('conversationToken is required');
        }

        $token = (string) $data['conversationToken'];

        // Idempotent: return existing link when one already exists.
        $existing = $this->talkLinkMapper->findByObjectAndToken(
            objectUuid: $objectUuid,
            conversationToken: $token
        );
        if ($existing !== null) {
            return $existing->jsonSerialize();
        }

        $user = $this->userSession->getUser();

        $link = new TalkLink();
        $link->setObjectUuid(objectUuid: $objectUuid);
        $link->setConversationToken(conversationToken: $token);
        $link->setConversationName(conversationName: (string) ($data['conversationName'] ?? ''));
        $link->setLinkedBy(linkedBy: $user?->getUID() ?? 'system');
        $link->setLinkedAt(linkedAt: new DateTime());

        $created = $this->talkLinkMapper->insert(entity: $link);

        return $created->jsonSerialize();
    }//end linkToObject()

    /**
     * Remove a Talk conversation link from an OR object.
     *
     * @param string $objectUuid The OR object UUID.
     * @param string $linkId     The TalkLink primary-key ID (as string).
     *
     * @return bool True when a link was removed, false when not found.
     *
     * @spec openspec/changes/integration-talk/tasks.md#task-1
     */
    public function unlinkFromObject(string $objectUuid, string $linkId): bool
    {
        try {
            $link = $this->talkLinkMapper->find(id: (int) $linkId);

            // Guard: only remove links that actually belong to the claimed object.
            if ($link->getObjectUuid() !== $objectUuid) {
                return false;
            }

            $this->talkLinkMapper->delete(entity: $link);
            return true;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return false;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[TalkProvider] Failed to unlink conversation',
                context: [
                    'objectUuid' => $objectUuid,
                    'linkId'     => $linkId,
                    'error'      => $e->getMessage(),
                ]
            );

            return false;
        }//end try
    }//end unlinkFromObject()

}//end class
