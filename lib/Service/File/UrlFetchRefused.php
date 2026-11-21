<?php

/**
 * UrlFetchRefused
 *
 * Why a URL fetch produced no bytes (REQ-FCU-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use RuntimeException;

/**
 * A fetch that produced no file, with the reason a caller can answer with.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
class UrlFetchRefused extends RuntimeException {

	/**
	 * The host may not be fetched.
	 *
	 * @var string
	 */
	public const REFUSED_ADDRESS = 'refused-address';

	/**
	 * No answer arrived in time, or the connection failed.
	 *
	 * @var string
	 */
	public const TIMEOUT = 'timeout';

	/**
	 * The answer was not a 2xx; a redirect is one of these, and is not followed.
	 *
	 * @var string
	 */
	public const STATUS = 'status';

	/**
	 * The body passed the size ceiling.
	 *
	 * @var string
	 */
	public const TOO_LARGE = 'too-large';

	/**
	 * The body was empty.
	 *
	 * @var string
	 */
	public const EMPTY = 'empty';

	/**
	 * Constructor.
	 *
	 * @param string $reason  One of the reason constants.
	 * @param string $message What happened, for the caller.
	 */
	public function __construct(public readonly string $reason, string $message) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
