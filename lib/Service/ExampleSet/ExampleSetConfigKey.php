<?php

/**
 * OpenRegister Example Set Config Key
 *
 * Where one app config value of an example set lives: its app and its key.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * An app config address: the app and the key.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-config-and-undo-exactly-that-req-exs-015
 */
final class ExampleSetConfigKey {
	/**
	 * Constructor.
	 *
	 * @param string $app The app.
	 * @param string $key The key.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
	) {
	}//end __construct()
}//end class
