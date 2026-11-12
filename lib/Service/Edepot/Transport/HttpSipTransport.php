<?php

/**
 * HttpSipTransport: the base of the e-Depot transports that send over HTTP.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot\Transport;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * An HTTP client and a logger, shared by the REST API and connector transports.
 */
abstract class HttpSipTransport implements TransportInterface {

	/**
	 * Constructor.
	 *
	 * @param Client          $httpClient The HTTP client.
	 * @param LoggerInterface $logger     Logger.
	 */
	public function __construct(
		protected readonly Client $httpClient,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
