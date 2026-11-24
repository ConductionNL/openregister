<?php

/**
 * TwigSandboxListsGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AuthenticationService;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Pins the Twig sandbox allowlists of the JWT payload templates and of computed
 * fields, so sharing their common filters cannot widen or narrow either sandbox.
 */
class TwigSandboxListsGoldenTest extends TestCase {

	/**
	 * Read one private allowlist of a security policy.
	 *
	 * @param SecurityPolicy $policy The policy.
	 * @param string         $name   The property name.
	 *
	 * @return array<mixed> The allowlist.
	 */
	private function allowed(SecurityPolicy $policy, string $name): array {
		return (new ReflectionProperty(SecurityPolicy::class, $name))->getValue($policy);
	}

	/**
	 * The sandbox policy the authentication service compiles JWT payloads under.
	 *
	 * @return SecurityPolicy The policy.
	 */
	private function jwtPolicy(): SecurityPolicy {
		$service = new AuthenticationService(new ArrayLoader());
		$twig    = (new ReflectionProperty(AuthenticationService::class, 'twig'))->getValue($service);
		$this->assertInstanceOf(Environment::class, $twig);
		$sandbox = $twig->getExtension(SandboxExtension::class);
		$this->assertTrue($sandbox->isSandboxed());

		return $sandbox->getSecurityPolicy();
	}

	public function testJwtSandboxAllowlistsArePinned(): void {
		$policy = $this->jwtPolicy();

		$this->assertSame(['if', 'for', 'set', 'apply'], $this->allowed($policy, 'allowedTags'));
		$this->assertSame(
			[
				'date', 'date_modify', 'upper', 'lower', 'trim', 'length', 'default', 'number_format', 'round', 'abs',
				'split', 'join', 'slice', 'first', 'last', 'replace', 'format',
				'merge', 'keys', 'escape', 'raw', 'json_encode',
			],
			$this->allowed($policy, 'allowedFilters')
		);
		$this->assertSame([], $this->allowed($policy, 'allowedMethods'));
		$this->assertSame([], $this->allowed($policy, 'allowedProperties'));
		$this->assertSame(['max', 'min', 'range', 'date'], $this->allowed($policy, 'allowedFunctions'));
	}

	public function testComputedFieldSandboxAllowlistsArePinned(): void {
		$filters   = (new ReflectionClassConstant(ComputedFieldHandler::class, 'SANDBOX_FILTERS'))->getValue();
		$functions = (new ReflectionClassConstant(ComputedFieldHandler::class, 'SANDBOX_FUNCTIONS'))->getValue();

		$this->assertSame(
			[
				'date', 'date_modify', 'upper', 'lower', 'trim', 'length', 'default', 'number_format', 'round', 'abs',
				'split', 'join', 'slice', 'first', 'last', 'replace', 'format',
				'b64enc', 'b64dec', 'json_decode', 'zgw_enum', 'zgw_enum_reverse', 'zgw_extract_uuid',
			],
			$filters
		);
		$this->assertSame(['max', 'min', 'range', 'generateUuid'], $functions);
	}
}//end class
