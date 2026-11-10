<?php

/**
 * CredentialMintOptionsRedactionTest — the mint options never show their secret.
 *
 * Pins that dumping a CredentialMintOptions masks the secret, and that every
 * broker method taking the options marks that parameter #[\SensitiveParameter]
 * so stack traces redact it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/credential-broker/spec.md#credential-metadata-schema
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;

class CredentialMintOptionsRedactionTest extends TestCase {
	private const SECRET = 'top-secret-value-1234';

	public function testPrintRAndVarDumpMaskTheSecret(): void {
		$options = new CredentialMintOptions(allowedApps: ['openconnector'], secret: self::SECRET, scope: 'organisation', organisation: 'org-1');

		$printed = print_r($options, true);
		ob_start();
		var_dump($options);
		$dumped = (string) ob_get_clean();

		foreach ([$printed, $dumped] as $output) {
			self::assertStringNotContainsString(self::SECRET, $output);
			self::assertStringContainsString('[redacted]', $output);
			self::assertStringContainsString('org-1', $output);
		}
	}//end testPrintRAndVarDumpMaskTheSecret()

	public function testAMissingSecretStaysNull(): void {
		self::assertNull((new CredentialMintOptions())->__debugInfo()['secret']);
	}//end testAMissingSecretStaysNull()

	public function testEveryMethodTakingTheOptionsMarksThemSensitive(): void {
		foreach (['mintCredential', 'mintData', 'mintScope'] as $method) {
			$marked = false;
			foreach ((new ReflectionMethod(CredentialBrokerService::class, $method))->getParameters() as $parameter) {
				if ($parameter->getType()?->getName() === CredentialMintOptions::class) {
					$marked = $parameter->getAttributes(SensitiveParameter::class) !== [];
				}
			}

			self::assertTrue($marked, $method . ' must mark its CredentialMintOptions parameter #[\SensitiveParameter]');
		}
	}//end testEveryMethodTakingTheOptionsMarksThemSensitive()
}//end class
