<?php

/**
 * OpenRegister User Preference Key
 *
 * Names one per-user preference an example set reads or writes: the account,
 * the app and the key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The account, app and key of one user preference.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
 */
final class UserPreferenceKey {

	/**
	 * The account id.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * The app.
	 *
	 * @var string
	 */
	public readonly string $app;

	/**
	 * The key.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $entry The preference as a record entry from ExampleSetUserPreferences::apply()
	 *                                    names it: `user`, `app` and `key`.
	 */
	public function __construct(array $entry) {
		$this->uid = (string) $entry['user'];
		$this->app = (string) $entry['app'];
		$this->key = (string) $entry['key'];
	}//end __construct()
}//end class
