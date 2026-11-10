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

	/**
	 * var_export, json_encode, an array cast and the raw object vars leave the secret out.
	 *
	 * @return void
	 */
	public function testNoExportCastOrEncodingCarriesTheSecret(): void {
		$options = new CredentialMintOptions(allowedApps: ['openconnector'], secret: self::SECRET, scope: 'organisation', organisation: 'org-1');

		$outputs = [
			'var_export'  => var_export($options, true),
			'json_encode' => (string) json_encode($options),
			'array cast'  => var_export((array) $options, true),
			'object vars' => var_export(get_mangled_object_vars($options), true),
		];
		foreach ($outputs as $how => $output) {
			self::assertStringNotContainsString(needle: self::SECRET, haystack: $output, message: $how . ' shows the secret');
			self::assertStringContainsString(needle: 'org-1', haystack: $output, message: $how . ' lost the other fields');
		}
	}//end testNoExportCastOrEncodingCarriesTheSecret()

	/**
	 * serialize() is refused, and the refusal does not carry the secret.
	 *
	 * @return void
	 */
	public function testSerialisationIsRefusedWithoutTheSecret(): void {
		$options = new CredentialMintOptions(secret: self::SECRET);

		try {
			$serialised = serialize($options);
			self::fail(message: 'serialize() returned ' . $serialised);
		} catch (\LogicException $e) {
			self::assertStringNotContainsString(needle: self::SECRET, haystack: $e->getMessage());
		}
	}//end testSerialisationIsRefusedWithoutTheSecret()

	/**
	 * Each instance hands back its own secret.
	 *
	 * @return void
	 */
	public function testTheAccessorReturnsTheSecretOfEachInstance(): void {
		$first  = new CredentialMintOptions(secret: self::SECRET);
		$second = new CredentialMintOptions(secret: 'other');

		self::assertSame(expected: self::SECRET, actual: $first->secret());
		self::assertSame(expected: 'other', actual: $second->secret());
		self::assertNull(actual: (new CredentialMintOptions())->secret());
	}//end testTheAccessorReturnsTheSecretOfEachInstance()

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
