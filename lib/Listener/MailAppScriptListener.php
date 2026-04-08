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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Injects the OpenRegister mail sidebar script when the Mail app renders.
 *
 * Listens for BeforeTemplateRenderedEvent (fired for all app pages) and
 * only injects the sidebar script when:
 * 1. The current page is the Mail app.
 * 2. The user is logged in and has Mail enabled.
 * 3. At least one schema declares 'mail' in its linkedTypes configuration.
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 *
 * @psalm-suppress UnusedClass
 */
class MailAppScriptListener implements IEventListener
{
    /**
     * Constructor.
     *
<<<<<<< HEAD
     * @param IAppManager     $appManager     The app manager.
     * @param IUserSession    $userSession    The user session.
     * @param RegisterMapper  $registerMapper The register mapper.
     * @param LoggerInterface $logger         The logger.
=======
     * @param IAppManager     $appManager    The app manager.
     * @param IUserSession    $userSession   The user session.
     * @param IRequest        $request       The request object.
     * @param SchemaMapper    $schemaMapper  The schema mapper.
     * @param LoggerInterface $logger        The logger.
>>>>>>> origin/feature/linked-entity-types
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IRequest $request,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle the event.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof BeforeTemplateRenderedEvent) === false) {
            return;
        }

        // Only inject on the Mail app page.
        $requestUri = $this->request->getRequestUri();
        if (str_contains($requestUri, '/apps/mail') === false) {
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

        // Check if any schema declares 'mail' in linkedTypes.
        if ($this->hasLinkedType('mail') === false) {
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
     * Check if any schema has the given type in its linkedTypes configuration.
     *
     * @param string $type The linked type to check for
     *
     * @return bool True if at least one schema has this linked type.
     */
    private function hasLinkedType(string $type): bool
    {
        try {
            $schemas = $this->schemaMapper->findAll();
            foreach ($schemas as $schema) {
                if (in_array($type, $schema->getLinkedTypes(), true) === true) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not check linkedTypes for sidebar: {error}',
                ['error' => $e->getMessage()]
            );

            return false;
        }
    }//end hasLinkedType()
}//end class
