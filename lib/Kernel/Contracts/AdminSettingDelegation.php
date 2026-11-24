<?php

/**
 * AdminSettingDelegation: the admin setting that admin-only endpoints are delegated through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The id of the delegated admin setting, for `#[AuthorizedAdminSetting(settings: ...)]`.
 *
 * Nextcloud's security middleware matches the attribute's `settings` argument, a class
 * name string, against the settings an administrator delegated to a group. Controllers
 * of any area name the setting by this id, so they do not depend on the settings panel
 * class itself. The value is exactly `OCA\OpenRegister\Settings\OpenRegisterAdmin::class`;
 * a unit test pins that.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-admin-settings-page-must-render-in-the-nextcloud-admin-panel
 */
final class AdminSettingDelegation {

	/**
	 * The class name of the OpenRegister admin settings panel, as Nextcloud records delegations.
	 *
	 * @var string
	 */
	public const OPEN_REGISTER_ADMIN = 'OCA\\OpenRegister\\Settings\\OpenRegisterAdmin';
}//end class
