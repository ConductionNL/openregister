<?php

/**
 * OpenRegister UserProfileUpdatedEvent
 *
 * This file contains the event class dispatched when a user profile is updated
 * via the OpenRegister /me endpoint.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;
use OCP\IUser;

/**
 * Event dispatched when a user profile is updated via the /me endpoint.
 *
 * This event allows other apps to react to user profile changes,
 * such as syncing name fields to related objects.
 */
class UserProfileUpdatedEvent extends Event
{
    /**
     * Constructor for UserProfileUpdatedEvent.
     *
     * @param IUser $user     The user whose profile was updated.
     * @param array $oldData  The user data before the update.
     * @param array $newData  The user data after the update.
     * @param array $changes  Array of field names that were changed.
     *
     * @return void
     */
    public function __construct(
        private readonly IUser $user,
        private readonly array $oldData,
        private readonly array $newData,
        private readonly array $changes
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Get the user whose profile was updated.
     *
     * @return IUser The user object.
     */
    public function getUser(): IUser
    {
        return $this->user;
    }//end getUser()

    /**
     * Get the user ID.
     *
     * @return string The user ID.
     */
    public function getUserId(): string
    {
        return $this->user->getUID();
    }//end getUserId()

    /**
     * Get the old user data before the update.
     *
     * @return array The old user data.
     */
    public function getOldData(): array
    {
        return $this->oldData;
    }//end getOldData()

    /**
     * Get the new user data after the update.
     *
     * @return array The new user data.
     */
    public function getNewData(): array
    {
        return $this->newData;
    }//end getNewData()

    /**
     * Get the list of changed field names.
     *
     * @return array Array of field names that were changed.
     */
    public function getChanges(): array
    {
        return $this->changes;
    }//end getChanges()

    /**
     * Check if a specific field was changed.
     *
     * @param string $fieldName The field name to check.
     *
     * @return bool True if the field was changed, false otherwise.
     */
    public function hasChanged(string $fieldName): bool
    {
        return in_array($fieldName, $this->changes, true);
    }//end hasChanged()

    /**
     * Check if any name fields were changed.
     *
     * @return bool True if firstName, lastName, middleName, or displayName was changed.
     */
    public function hasNameChanges(): bool
    {
        $nameFields = ['firstName', 'lastName', 'middleName', 'displayName'];
        foreach ($nameFields as $field) {
            if ($this->hasChanged($field) === true) {
                return true;
            }
        }

        return false;
    }//end hasNameChanges()
}//end class
