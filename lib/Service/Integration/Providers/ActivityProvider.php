<?php

/**
 * ActivityProvider — surfaces NC Activity events related to an OR
 * object through the IntegrationProvider contract.
 *
 * Storage strategy is `query-time` (AD-22): activity events are
 * transient — they live in NC's activity stream and are queried live
 * per render, never stored locally. Mutation methods therefore throw
 * NotImplementedException via the AbstractIntegrationProvider default;
 * activity feeds are read-only by design.
 *
 * The wrapped read-path (filtering NC's activity stream by the OR
 * object's associated actors/events) is implemented in a follow-up
 * leaf — the provider registers as a registry surface so the tab/widget
 * slot exists and the admin UI can advertise availability gated on the
 * Activity app, but `list()` returns an empty result until the
 * filter service is wired (see openspec/changes/integration-activity).
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
 * @spec openspec/changes/integration-activity/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * Activity (read-only event stream) integration provider.
 */
class ActivityProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'activity';

    /**
     * Constructor.
     *
     * @param IAppManager $appManager NC app manager.
     * @param IL10N       $l10n       Localisation.
     *
     * @return void
     */
    public function __construct(
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'activity';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Activity');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Timeline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List activity events relevant to an OR object.
     *
     * Backing read-path (filtering NC's activity stream by linked
     * actors/files/objects) lands in a follow-up. Stubbed to an empty
     * list so the tab/widget slot renders an empty state rather than
     * a 500.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (ignored).
     *
     * @return array<int,array<string,mixed>>
     */
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
            'message'    => $installed === true ? null : 'NC Activity app is not installed',
        ];
    }//end health()
}//end class
