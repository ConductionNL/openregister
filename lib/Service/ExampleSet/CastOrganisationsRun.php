<?php

/**
 * OpenRegister Cast Organisations Run
 *
 * One read of the organisations a cast declares.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The cast file an organisation is read from, for messages, and each cast person's account by key, which an organisation's members name.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
 */
final class CastOrganisationsRun {

	/**
	 * The cast file, for messages.
	 *
	 * @var string
	 */
	public readonly string $file;

	/**
	 * Each cast person's account, by key.
	 *
	 * @var array<string, string>
	 */
	public readonly array $accounts;

	/**
	 * Constructor.
	 *
	 * @param string                $file     The cast file, for messages.
	 * @param array<string, string> $accounts Each cast person's account, by key.
	 */
	public function __construct(string $file, array $accounts) {
		$this->file     = $file;
		$this->accounts = $accounts;
	}//end __construct()
}//end class
