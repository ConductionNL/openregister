<?php

/**
 * OpenRegister statement publication
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * An administrator's request to publish a statement, or a new version of one; {@see self::trimmed()} is the form stored.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */
final class StatementPublication {
	/**
	 * Hold the publication.
	 *
	 * @param string $version     The version, as the administrator writes it.
	 * @param string $body        The text a user reads.
	 * @param string $title       The heading above it.
	 * @param string $publishedBy Who published it.
	 */
	public function __construct(
		public readonly string $version,
		public readonly string $body,
		public readonly string $title = '',
		public readonly string $publishedBy = '',
	) {
	}//end __construct()

	/**
	 * The same publication with the version, the text and the heading trimmed.
	 *
	 * @return self The trimmed publication.
	 */
	public function trimmed(): self {
		return new self(version: trim($this->version), body: trim($this->body), title: trim($this->title), publishedBy: $this->publishedBy);
	}//end trimmed()
}//end class
