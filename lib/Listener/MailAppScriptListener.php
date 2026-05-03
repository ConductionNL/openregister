<?php

/**
 * Listener that injects the mail sidebar script into the Nextcloud Mail app.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-48
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-52
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\RegisterMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Injects the OpenRegister mail sidebar script when the Mail app renders.
 *
 * Conditions for injection:
 * 1. The event is BeforeTemplateRenderedEvent from the Mail app.
 * 2. The Mail app is installed and enabled for the current user.
 * 3. The user has access to at least one OpenRegister register.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-suppress UnusedClass
 */
class MailAppScriptListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param IAppManager     $appManager     The app manager.
     * @param IUserSession    $userSession    The user session.
     * @param RegisterMapper  $registerMapper The register mapper.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle the event.
     *
     * @param Event $event The event.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-48
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-52
     */
    public function handle(Event $event): void
    {
        /*
         * Handle two shapes of "mail app is rendering" events:
         * 1. An OCA\Mail\* custom event (legacy Mail-app-specific events).
         * 2. Nextcloud core BeforeTemplateRenderedEvent whose response->getApp()
         *    is 'mail' (what Mail actually dispatches today).
         */

        if ($this->isMailRenderEvent(event: $event) === false) {
            return;
        }

        // Check Mail app is enabled.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        if ($this->appManager->isEnabledForUser('mail', $user) === false) {
            return;
        }

        // Check user has access to at least one register.
        if ($this->userHasRegisterAccess() === false) {
            return;
        }

        // Inject the sidebar script.
        Util::addScript('openregister', 'openregister-mail-sidebar');
        Util::addStyle('openregister', 'mail-sidebar');

        $this->logger->debug(
                'Mail sidebar script injected for user {user}',
                [
                    'user' => $user->getUID(),
                ]
                );
    }//end handle()

    /**
     * Check if the current user has access to any OpenRegister register.
     *
     * @return bool True if the user has register access.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-48
     */
    private function userHasRegisterAccess(): bool
    {
        try {
            $registers = $this->registerMapper->findAll(1, 0);
            return count($registers) > 0;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not check register access for mail sidebar: {error}',
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }//end userHasRegisterAccess()

    /**
     * Whether $event signals that the Mail app is about to render a template.
     *
     * Accepts OCA\Mail\* custom events (legacy) AND Nextcloud core
     * BeforeTemplateRenderedEvent whose response app is 'mail'.
     *
     * @param Event $event The dispatched event.
     *
     * @return bool True if this is a mail-app render event.
     */
    private function isMailRenderEvent(Event $event): bool
    {
        if (str_contains(get_class($event), 'OCA\\Mail\\') === true) {
            return true;
        }

        if ($event instanceof BeforeTemplateRenderedEvent) {
            $response = $event->getResponse();
            if ($response instanceof TemplateResponse && $response->getApp() === 'mail') {
                return true;
            }
        }

        return false;
    }//end isMailRenderEvent()
}//end class
