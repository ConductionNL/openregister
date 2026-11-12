<?php

/**
 * A Nextcloud HTTP client service whose POST really goes out, over Guzzle.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use GuzzleHttp\Client;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * For tests that run a stand-in backend on a local PHP server: the handler talks
 * to it through IClientService, the way it does in Nextcloud.
 */
trait GuzzleClientServiceTrait {

	/**
	 * An IClientService whose client sends POSTs through Guzzle and records the options.
	 *
	 * @param array<int, array{0: string, 1: array}> $calls Receives each POST's URL and options.
	 *
	 * @return IClientService The client service.
	 */
	private function guzzleClientService(array &$calls = []): IClientService {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $uri, array $options) use (&$calls): IResponse {
				$calls[] = [$uri, $options];
				$answer = (new Client())->post($uri, $options);
				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($answer->getStatusCode());
				$response->method('getBody')->willReturn((string)$answer->getBody());

				return $response;
			}
		);

		$service = $this->createMock(originalClassName: IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}//end guzzleClientService()
}//end trait
