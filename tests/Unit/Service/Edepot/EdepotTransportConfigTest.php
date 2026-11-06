<?php

/**
 * The SFTP transport receives the configured host key fingerprint.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\EdepotTransferService
 */
class EdepotTransportConfigTest extends TestCase {

	/**
	 * The fingerprint comes from its app config key.
	 *
	 * @return void
	 */
	public function testTheFingerprintReachesTheTransportConfig(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'edepot_sftp_host_key_fingerprint') ? 'SHA256:pin' : $default
		);

		$class = new ReflectionClass(EdepotTransferService::class);
		$service = $class->newInstanceWithoutConstructor();
		$class->getProperty('appConfig')->setValue($service, $appConfig);
		$class->getProperty('logger')->setValue($service, new NullLogger());

		$config = $class->getMethod('getTransportConfig')->invoke($service);

		$this->assertSame('SHA256:pin', $config['hostKeyFingerprint']);
	}//end testTheFingerprintReachesTheTransportConfig()
}//end class
