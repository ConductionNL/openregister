<?php

/**
 * OpenRegister SinkAppendRun
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
 * One attempt to append a rendered line to the audit sink file.
 *
 * The state AuditSink's append helpers share: the target path, the line, and
 * the last failure seen. It never leaves AuditSink.
 */
final class SinkAppendRun {

	/**
	 * The last failure, when an attempt fell short.
	 *
	 * @var string|null
	 */
	public ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $path The sink file.
	 * @param string $line The rendered line, newline included.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $line,
	) {
	}//end __construct()
}//end class
