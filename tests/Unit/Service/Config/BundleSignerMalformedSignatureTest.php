<?php

/**
 * Behaviour tests for BundleSigner::verify() with malformed provenance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BundleSigner;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Config\BundleSigner
 */
class BundleSignerMalformedSignatureTest extends TestCase
{
    private function signer(string $trustedKeys): BundleSigner
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key): string => match ($key) {
                'federated_config_signing_secret' => base64_encode($secret),
                'federated_config_trusted_keys'   => $trustedKeys,
                default                            => '',
            }
        );
        return new BundleSigner($config);
    }//end signer()

    public function testAKeyOfTheWrongLengthIsInvalidInsteadOfThrowing(): void
    {
        $signer = $this->signer('');
        $bundle = $signer->sign(['schemas' => ['a' => 1]]);
        $bundle['provenance']['publicKey'] = base64_encode('short');

        $result = $signer->verify($bundle);

        $this->assertSame(['signed' => true, 'valid' => false, 'trusted' => true, 'publicKey' => base64_encode('short')], $result);
    }//end testAKeyOfTheWrongLengthIsInvalidInsteadOfThrowing()

    public function testASignatureThatIsNotBase64IsInvalidAndAnUnlistedKeyUntrusted(): void
    {
        $signer = $this->signer(' someone-else , ');
        $bundle = $signer->sign(['schemas' => []]);
        $bundle['provenance']['signature'] = '***not base64***';

        $result = $signer->verify($bundle);

        $this->assertTrue($result['signed']);
        $this->assertFalse($result['valid']);
        $this->assertFalse($result['trusted']);
    }//end testASignatureThatIsNotBase64IsInvalidAndAnUnlistedKeyUntrusted()

    public function testAnIntactSignatureFromAListedKeyIsValidAndTrusted(): void
    {
        $signer = $this->signer('');
        $key    = $signer->publicKey();
        $signer = $this->signerWithSecretAndTrust($signer, $key);

        $this->assertSame(['signed' => true, 'valid' => true, 'trusted' => true, 'publicKey' => $key], $signer->verify($signer->sign(['b' => 2, 'a' => 1])));
    }//end testAnIntactSignatureFromAListedKeyIsValidAndTrusted()

    /**
     * A signer over the same secret whose trust list names exactly that key.
     */
    private function signerWithSecretAndTrust(BundleSigner $signer, string $key): BundleSigner
    {
        $secret = (fn(): string => $this->secretKey())->call($signer);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $name): string => match ($name) {
                'federated_config_signing_secret' => base64_encode($secret),
                'federated_config_trusted_keys'   => 'other-key, '.$key,
                default                            => '',
            }
        );
        return new BundleSigner($config);
    }//end signerWithSecretAndTrust()
}//end class
