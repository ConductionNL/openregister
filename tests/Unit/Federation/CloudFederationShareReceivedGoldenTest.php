<?php

/**
 * Characterisation of OpenRegisterCloudFederationProvider::shareReceived(): the incoming
 * share it records from the OCM share and its protocol options (with their defaults), the
 * local id it answers, and the refusal when recording fails.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider;
use OCA\OpenRegister\Service\FederationShareService;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden recording of inbound federated shares.
 *
 * @covers \OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider
 */
final class CloudFederationShareReceivedGoldenTest extends TestCase {

	/**
	 * An OCM share.
	 *
	 * @param array<string, mixed> $protocol The protocol block.
	 *
	 * @return ICloudFederationShare
	 */
	private function share(array $protocol): ICloudFederationShare {
		$share = $this->createMock(ICloudFederationShare::class);
		$share->method('getProtocol')->willReturn($protocol);
		$share->method('getProviderId')->willReturn('remote-9');
		$share->method('getShareSecret')->willReturn('secret-1');
		$share->method('getShareWith')->willReturn('anna');

		return $share;
	}//end share()

	/**
	 * Every option is carried over and the local id answered.
	 *
	 * @return void
	 */
	public function testFullOptionsAreRecorded(): void {
		$recorded = new FederatedShare();
		$recorded->setId(41);
		$service = $this->createMock(FederationShareService::class);
		$service->expects($this->once())->method('recordIncomingShare')->willReturnCallback(
			function (array $params) use ($recorded): FederatedShare {
				$this->assertSame(
					[
						'remoteInstanceUrl' => 'https://remote.example/api',
						'remoteProviderId' => 'remote-9',
						'shareToken' => 'tok',
						'scope' => 'object',
						'register' => 'reg',
						'schema' => 'person',
						'objectUri' => 'or://o/1',
						'permissions' => 'write',
						'sharedWith' => 'anna',
					],
					$params
				);
				return $recorded;
			}
		);

		$options = ['apiUrl' => 'https://remote.example/api', 'token' => 'tok', 'scope' => 'object', 'register' => 'reg', 'schema' => 'person', 'objectUri' => 'or://o/1', 'permissions' => 'write'];
		$id = (new OpenRegisterCloudFederationProvider($service, $this->createMock(LoggerInterface::class)))->shareReceived($this->share(protocol: ['options' => $options]));

		$this->assertSame('41', $id);
	}//end testFullOptionsAreRecorded()

	/**
	 * Without options the share secret is the token, the scope is schema and the permission read.
	 *
	 * @return void
	 */
	public function testMissingOptionsDefault(): void {
		$recorded = new FederatedShare();
		$recorded->setId(42);
		$service = $this->createMock(FederationShareService::class);
		$service->expects($this->once())->method('recordIncomingShare')->willReturnCallback(
			function (array $params) use ($recorded): FederatedShare {
				$this->assertSame(
					[
						'remoteInstanceUrl' => null,
						'remoteProviderId' => 'remote-9',
						'shareToken' => 'secret-1',
						'scope' => 'schema',
						'register' => null,
						'schema' => null,
						'objectUri' => null,
						'permissions' => 'read',
						'sharedWith' => 'anna',
					],
					$params
				);
				return $recorded;
			}
		);

		$this->assertSame('42', (new OpenRegisterCloudFederationProvider($service, $this->createMock(LoggerInterface::class)))->shareReceived($this->share(protocol: [])));
	}//end testMissingOptionsDefault()

	/**
	 * A share that cannot be recorded is refused with the provider exception, and the cause logged.
	 *
	 * @return void
	 */
	public function testFailureIsRefused(): void {
		$service = $this->createMock(FederationShareService::class);
		$service->method('recordIncomingShare')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('[Federation] shareReceived failed: db down');

		try {
			(new OpenRegisterCloudFederationProvider($service, $logger))->shareReceived($this->share(protocol: ['options' => []]));
			$this->fail('The share was accepted.');
		} catch (ProviderCouldNotAddShareException $e) {
			$this->assertSame('Could not record OpenRegister federated share', $e->getMessage());
		}
	}//end testFailureIsRefused()
}//end class
