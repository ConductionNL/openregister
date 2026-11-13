<?php

/**
 * Thrown when the disclosure record of an external call cannot be written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * The call was not made, because its disclosure record could not be written.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */
class TextDisclosureNotRecordedException extends Exception {
	/**
	 * Name the service the text was not sent to.
	 *
	 * @param string         $service  The service the call was meant for.
	 * @param Throwable|null $previous Why the record could not be written.
	 */
	public function __construct(string $service, ?Throwable $previous = null) {
		parent::__construct(
			message: 'The text disclosure record could not be written, so the call to ' . $service . ' was not made',
			code: 0,
			previous: $previous
		);
	}//end __construct()
}//end class
