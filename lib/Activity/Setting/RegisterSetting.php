<?php

/**
 * OpenRegister RegisterSetting.
 *
 * Activity setting for register CRUD notifications.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity\Setting
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Activity
 * @package  OCA\OpenRegister\Activity\Setting
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity\Setting;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Activity setting for register events.
 */
class RegisterSetting extends ActivitySettings
{
    /**
     * Constructor.
     *
     * @param IL10N $l The localization service.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(
        private IL10N $l,
    ) {
    }//end __construct()

    /**
     * Get the identifier for this setting.
     *
     * @return string The setting identifier.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getIdentifier(): string
    {
        return 'openregister_registers';
    }//end getIdentifier()

    /**
     * Get the name for this setting.
     *
     * @return string The setting name.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getName(): string
    {
        return $this->l->t('Register changes');
    }//end getName()

    /**
     * Get the group identifier for this setting.
     *
     * @return string The group identifier.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getGroupIdentifier(): string
    {
        return 'openregister';
    }//end getGroupIdentifier()

    /**
     * Get the group name for this setting.
     *
     * @return string The group name.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getGroupName(): string
    {
        return $this->l->t('Open Register');
    }//end getGroupName()

    /**
     * Get the priority for this setting.
     *
     * @return int The priority.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getPriority(): int
    {
        return 52;
    }//end getPriority()

    /**
     * Whether the user can change the stream setting.
     *
     * @return bool True if changeable.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function canChangeStream(): bool
    {
        return true;
    }//end canChangeStream()

    /**
     * Whether the stream is enabled by default.
     *
     * @return bool True if enabled by default.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function isDefaultEnabledStream(): bool
    {
        return true;
    }//end isDefaultEnabledStream()

    /**
     * Whether the user can change the mail setting.
     *
     * @return bool True if changeable.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function canChangeMail(): bool
    {
        return true;
    }//end canChangeMail()

    /**
     * Whether mail is enabled by default.
     *
     * @return bool True if enabled by default.
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-activity-provider/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function isDefaultEnabledMail(): bool
    {
        return false;
    }//end isDefaultEnabledMail()
}//end class
