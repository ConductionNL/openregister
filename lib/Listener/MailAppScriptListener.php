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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Injects the OpenRegister mail sidebar script when the Mail app renders.
 *
 * Conditions for injection:
 * 1. The event is BeforeTemplateRenderedEvent from the Mail app.
 * 2. The Mail app is installed and enabled for the current user.
 * 3. At least one schema declares 'mail' in its linkedTypes configuration.
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
     * @param IAppManager     $appManager    The app manager.
     * @param IUserSession    $userSession   The user session.
     * @param SchemaMapper    $schemaMapper  The schema mapper.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
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
        // Only handle BeforeTemplateRenderedEvent from the Mail app.
        $eventClass = get_class($event);
        if (str_contains($eventClass, 'OCA\\Mail\\') === false) {
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

        $this->logger->debug('Mail sidebar script injected for user {user}', [
            'user' => $user->getUID(),
        ]);
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
