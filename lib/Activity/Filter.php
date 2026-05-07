<?php

/**
 * OpenRegister Activity Filter.
 *
 * Filter for OpenRegister activity events in the activity stream.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity;

use OCA\OpenRegister\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Activity filter for OpenRegister events.
 */
class Filter implements IFilter
{
    /**
     * Constructor.
     *
     * @param IL10N         $l            The localization service.
     * @param IURLGenerator $urlGenerator The URL generator.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the unique identifier of the filter.
     *
     * @return string The filter identifier.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function getIdentifier(): string
    {
        return Application::APP_ID;
    }//end getIdentifier()

    /**
     * Get the human-readable name of the filter.
     *
     * @return string The filter name.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function getName(): string
    {
        return $this->l->t('Open Register');
    }//end getName()

    /**
     * Get the priority of the filter.
     *
     * @return int The filter priority.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function getPriority(): int
    {
        return 50;
    }//end getPriority()

    /**
     * Get the icon URL for the filter.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
        );
    }//end getIcon()

    /**
     * Filter the activity types to show.
     *
     * @param array $types The available types.
     *
     * @return array<array-key, string> The filtered types.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $types required by IFilter interface
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function filterTypes(array $types): array
    {
        return ['openregister_objects', 'openregister_registers', 'openregister_schemas'];
    }//end filterTypes()

    /**
     * Get the allowed apps for this filter.
     *
     * @return array<array-key, string> The allowed app IDs.
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-2
     */
    public function allowedApps(): array
    {
        return [Application::APP_ID];
    }//end allowedApps()
}//end class
