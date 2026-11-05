<?php

/**
 * The exact refusals of an incomplete REST API transport config.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use GuzzleHttp\Client;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Each incomplete config is refused with its exact message, before any request.
 */
class RestApiTransportConfigRefusalTest extends TestCase {

	/**
	 * Config and the refusal it gets.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function configs(): array {
		$url = 'https://e/x';
		$type = 'Missing or unsupported REST API config: authenticationType must be one of api_key, oauth2, certificate'
			.' — refusing to transfer a SIP package unauthenticated';
		return [
			'no endpoint' => [['authenticationType' => 'api_key', 'apiKey' => 'k'], 'Missing required REST API config: endpointUrl'],
			'no auth type' => [['endpointUrl' => $url], $type],
			'unknown auth type' => [['endpointUrl' => $url, 'authenticationType' => 'basic'], $type],
			'api key empty' => [['endpointUrl' => $url, 'authenticationType' => 'api_key', 'apiKey' => ''], 'Missing required REST API config: apiKey is empty while authenticationType is api_key'],
			'bearer empty' => [['endpointUrl' => $url, 'authenticationType' => 'oauth2'], 'Missing required REST API config: bearerToken is empty while authenticationType is oauth2'],
			'certificate needs no credential' => [['endpointUrl' => $url, 'authenticationType' => 'certificate'], 'SIP file not found: /nonexistent/sip.zip'],
		];
	}//end configs()

	/**
	 * The send fails with the exact refusal and sends nothing.
	 *
	 * @param array<string, mixed> $config  The config.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('configs')]
	public function testRefusal(array $config, string $message): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('post');

		$result = (new RestApiTransport($client, new NullLogger()))->send('/nonexistent/sip.zip', $config);

		$this->assertFalse($result->isSuccess());
		$this->assertSame($message, $result->getErrorMessage());
	}//end testRefusal()
}//end class
