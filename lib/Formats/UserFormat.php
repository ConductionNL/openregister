<?php

/**
 * OpenRegister UserFormat
 *
 * Validates that a string is the id of a Nextcloud user that actually exists.
 *
 * A property declared `format: user` names a person, and the only useful thing
 * to check about such a value is whether that person is still there. A user id
 * is syntactically just a string, so a pattern can say nothing — the backend is
 * the only authority. Without this check a schema could carry the id of a
 * deleted account indefinitely and every consumer would resolve it to nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Formats;

use OCP\IUserManager;
use Opis\JsonSchema\Format;

/**
 * Format resolver asserting that a user id resolves to a real account.
 */
class UserFormat implements Format
{
    /**
     * Constructor.
     *
     * @param IUserManager $userManager The backend consulted for existence.
     */
    public function __construct(private readonly IUserManager $userManager)
    {
    }//end __construct()

    /**
     * Validate that the value names an existing Nextcloud user.
     *
     * @param mixed $data The value to validate.
     *
     * @inheritDoc
     *
     * @return bool True when the user exists.
     *
     * @spec openspec/specs/data-import-export/spec.md
     */
    public function validate(mixed $data): bool
    {
        if (is_string($data) === false) {
            return false;
        }

        $uid = trim($data);
        if ($uid === '') {
            return false;
        }

        // Existence is the cheaper question and the only one being asked —
        // userExists() rather than get(), since nothing here needs the object.
        return $this->userManager->userExists($uid);

    }//end validate()
}//end class
