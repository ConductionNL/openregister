<?php

/**
 * OpenRegisterPersonal
 *
 * Personal settings page for OpenRegister — hosts the per-user browser
 * Web Push opt-in toggle (openregister-web-push-engine). Surfaced under the
 * user's "Additional settings" personal section.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

namespace OCA\OpenRegister\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * OpenRegisterPersonal
 *
 * Personal settings implementation for OpenRegister — the browser-notifications
 * opt-in toggle.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
 */
class OpenRegisterPersonal implements ISettings
{
    /**
     * Get the personal settings form.
     *
     * Renders the personal-settings template, which mounts the Vue
     * browser-notifications opt-in toggle.
     *
     * @return TemplateResponse The personal settings template response.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(
            appName: 'openregister',
            templateName: 'settings/personal'
        );

    }//end getForm()

    /**
     * Get the personal settings section identifier.
     *
     * Uses Nextcloud's built-in "additional" personal section, which is always
     * present, so the toggle needs no custom section registration.
     *
     * @return string The section id.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function getSection(): string
    {
        return 'additional';

    }//end getSection()

    /**
     * Get the settings priority within the section.
     *
     * @return int Priority (0-100; lower renders earlier).
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function getPriority(): int
    {
        return 50;

    }//end getPriority()
}//end class
