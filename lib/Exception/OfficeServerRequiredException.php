<?php

/**
 * Class OfficeServerRequiredException
 *
 * Thrown when a PDF is asked for and no office server can convert to PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * openregister writes PDFs by converting an ODT through Nextcloud's file
 * conversion API, which an office server (Collabora through richdocuments, or
 * Euro-Office) provides. Without one, PDF output is refused with this exception.
 *
 * Controllers map it to {@see self::HTTP_STATUS} with the error code
 * {@see self::ERROR_CODE}, so the UI can say what is missing.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version GIT: <git-id>
 * @link https://OpenRegister.app
 */
class OfficeServerRequiredException extends RuntimeException {

	/**
	 * HTTP status for this refusal: the server does not offer the feature.
	 *
	 * @var int
	 */
	public const HTTP_STATUS = 501;

	/**
	 * Machine-readable error code in JSON responses.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'office_server_required';

	/**
	 * The explanation given to the user.
	 *
	 * @var string
	 */
	public const MESSAGE = 'PDF output requires an office server (Collabora or Euro-Office) that converts documents to PDF. '
		. 'Ask your administrator to install one, or choose another format.';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(message: self::MESSAGE);
	}//end __construct()

	/**
	 * The JSON body controllers return for this refusal.
	 *
	 * @return array{error: string, message: string}
	 */
	public function toResponseData(): array {
		return ['error' => self::ERROR_CODE, 'message' => $this->getMessage()];
	}//end toResponseData()
}//end class
