<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One option of a poll PollLinkService creates, as it is written to polls_options.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class PollOptionRow {
	/**
	 * Constructor.
	 *
	 * @param int    $pollId    Created poll id.
	 * @param string $text      The trimmed option text.
	 * @param int    $order     The option's 1-based position.
	 * @param int    $timestamp The parsed date for a date poll, else 0.
	 */
	public function __construct(
		public readonly int $pollId,
		public readonly string $text,
		public readonly int $order,
		public readonly int $timestamp,
	) {
	}//end __construct()

	/**
	 * The option hash NC Polls keys the option on: 32 hex chars over poll id, text and position.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function hash(): string {
		return substr(hash('sha256', $this->pollId . '-' . $this->text . '-' . $this->order), 0, 32);
	}//end hash()
}//end class
