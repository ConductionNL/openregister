<?php

/**
 * OpenRegister SanitizationException
 *
 * Exception thrown by the Office document sanitiser (DOCX / ODT) when it
 * cannot produce a sanitised derivative. Carries a structured `reason`
 * (one of a fixed enum) so the caller can map the failure to the correct
 * behaviour without exposing PII in the message (per ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Exception thrown by the Office document sanitiser.
 *
 * The `reason` field is a stable, structured code:
 *
 *   - `REASON_UNSUPPORTED_MIME` → no strategy supports the file MIME type.
 *   - `REASON_ENCRYPTED`        → the ZIP container is password-protected.
 *   - `REASON_CORRUPT_ZIP`      → the ZIP container could not be opened.
 *   - `REASON_INTERNAL`         → unexpected surgery failure.
 *
 * Per ADR-005 the message MUST NOT contain a filename or document content —
 * only the reason code and structural detail (part path, element name).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
class SanitizationException extends \Exception {

	/**
	 * No registered strategy supports the input MIME type.
	 *
	 * @var string
	 */
	public const REASON_UNSUPPORTED_MIME = 'unsupported-mime';

	/**
	 * The ZIP container is encrypted / password-protected.
	 *
	 * @var string
	 */
	public const REASON_ENCRYPTED = 'encrypted';

	/**
	 * The ZIP container is corrupt or not a valid Office package.
	 *
	 * @var string
	 */
	public const REASON_CORRUPT_ZIP = 'corrupt-zip';

	/**
	 * Unexpected internal sanitiser failure.
	 *
	 * @var string
	 */
	public const REASON_INTERNAL = 'internal';

	/**
	 * The structured reason code (one of the REASON_* constants).
	 *
	 * MUST NOT carry filename or content (ADR-005).
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @param string $message PII-free human-readable detail.
	 * @param Throwable|null $previous Previous exception.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		string $reason,
		string $message = '',
		?Throwable $previous = null,
	) {
		$this->reason = $reason;

		$finalMessage = $message;
		if ($finalMessage === '') {
			$finalMessage = sprintf('Office document sanitisation failed: %s', $reason);
		}

		parent::__construct(message: $finalMessage, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * Get the structured reason code.
	 *
	 * @return string One of the REASON_* constants.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
