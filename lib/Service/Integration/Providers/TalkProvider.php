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

class TalkProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'spreed';

    public function __construct(
        private IAppManager $appManager,
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

    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
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
