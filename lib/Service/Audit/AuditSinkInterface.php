<?php

/**
 * AuditSinkInterface: what callers outside the component use of AuditSink.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * The audit sink settings the audit sink endpoint reports.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AuditSinkInterface {

	/**
	 * The format the sink writes in.
	 *
	 * An unrecognised format falls back to JSON Lines rather than refusing to
	 * ship. A typo in a format name must not be a reason the trail stops
	 * leaving the instance.
	 *
	 * @return string One of {@see AuditSink::FORMATS}.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function format(): string;

	/**
	 * Whether a sink is configured at all.
	 *
	 * @return bool True when the instance ships its trail.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function isConfigured(): bool;

	/**
	 * Where the sink writes, or null when none is configured.
	 *
	 * @return string|null The configured path.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function path(): ?string;
}//end interface
