<?php

/**
 * Catalogue and claim values of the wrong shape in the OAuth2 flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2FlowClaims;
use OCA\OpenRegister\Service\Credential\OAuth2ProviderConfig;
use PHPUnit\Framework\TestCase;

/**
 * A value of the wrong shape yields nothing rather than a type error.
 *
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2FlowClaims
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2ProviderConfig
 */
final class OAuth2FlowShapeTest extends TestCase {

	/**
	 * Claimed scopes win; without them the provider's defaults apply; non-list defaults give none.
	 *
	 * @return void
	 */
	public function testScopesFallBackToDefaultsAndRefuseNonLists(): void {
		$this->assertSame(['read', '7'], (new OAuth2FlowClaims(claims: ['sc' => ['read', 7]]))->scopes(provider: ['oauth2' => ['defaultScopes' => ['x']]]));
		$this->assertSame(['x'], (new OAuth2FlowClaims(claims: ['sc' => []]))->scopes(provider: ['oauth2' => ['defaultScopes' => ['x']]]));
		$this->assertSame([], (new OAuth2FlowClaims(claims: []))->scopes(provider: ['oauth2' => ['defaultScopes' => 'read write']]));
	}//end testScopesFallBackToDefaultsAndRefuseNonLists()

	/**
	 * Authorization parameters are passed through when a map, and dropped otherwise.
	 *
	 * @return void
	 */
	public function testAuthorizationParametersMustBeAMap(): void {
		$this->assertSame(['prompt' => 'consent'], (new OAuth2ProviderConfig(provider: ['oauth2' => ['authorizationParameters' => ['prompt' => 'consent']]]))->authorizationParameters());
		$this->assertSame([], (new OAuth2ProviderConfig(provider: ['oauth2' => ['authorizationParameters' => 'prompt=consent']]))->authorizationParameters());
		$this->assertSame([], (new OAuth2ProviderConfig(provider: []))->authorizationParameters());
	}//end testAuthorizationParametersMustBeAMap()
}//end class
