<?php

/**
 * The administered outbound allowlist admits, refuses and fails closed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Outbound
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Outbound;

use OCA\OpenRegister\Service\Outbound\OutboundAllowlist;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Outbound\OutboundAllowlist
 */
class OutboundAllowlistTest extends TestCase {

	private function allowlist(string $value): OutboundAllowlist {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->atLeastOnce())->method('getValueString')
			->with('openregister', OutboundAllowlist::KEY, '')
			->willReturn($value);

		return new OutboundAllowlist(appConfig: $config);
	}//end allowlist()

	public function testAnUnsetListAdmitsEveryDestination(): void {
		$this->assertNull($this->allowlist('')->refusal(url: 'https://anywhere.example/hook'));
	}//end testAnUnsetListAdmitsEveryDestination()

	public function testAnExactHostIsAdmittedWhateverItsCaseAndPath(): void {
		$list = $this->allowlist('["Hooks.Supplier.nl"]');

		$this->assertNull($list->refusal(url: 'https://hooks.supplier.nl:8443/in?x=1'));
	}//end testAnExactHostIsAdmittedWhateverItsCaseAndPath()

	public function testAHostOffTheListIsRefusedNamingTheHostAndTheAllowlist(): void {
		$refusal = $this->allowlist('["hooks.supplier.nl"]')->refusal(url: 'https://evil.example/hook');

		$this->assertSame('the destination host "evil.example" is not on the outbound allowlist (outboundAllowlist)', $refusal);
	}//end testAHostOffTheListIsRefusedNamingTheHostAndTheAllowlist()

	public function testAWildcardAdmitsSubdomainsButNotTheApexOrALookalike(): void {
		$list = $this->allowlist('*.supplier.nl');

		$this->assertNull($list->refusal(url: 'https://a.b.supplier.nl/x'));
		$this->assertNotNull($list->refusal(url: 'https://supplier.nl/x'));
		$this->assertNotNull($list->refusal(url: 'https://evilsupplier.nl/x'));
	}//end testAWildcardAdmitsSubdomainsButNotTheApexOrALookalike()

	public function testAHostOnlyInThePathIsRefused(): void {
		$this->assertNotNull($this->allowlist('hooks.supplier.nl')->refusal(url: 'https://evil.example/hooks.supplier.nl'));
	}//end testAHostOnlyInThePathIsRefused()

	public function testADestinationWithoutAHostIsRefusedWhenAListIsSet(): void {
		$this->assertSame(
			'the destination names no host, so it is not on the outbound allowlist (outboundAllowlist)',
			$this->allowlist('hooks.supplier.nl')->refusal(url: 'not a url')
		);
	}//end testADestinationWithoutAHostIsRefusedWhenAListIsSet()

	public function testCommaAndLineSeparatedEntriesAreReadAndDeduplicated(): void {
		$this->assertSame(['a.nl', 'b.nl', '*.c.nl'], $this->allowlist("A.nl, b.nl\n*.c.nl\na.nl")->entries());
	}//end testCommaAndLineSeparatedEntriesAreReadAndDeduplicated()

	public function testAListThatCannotBeReadRefusesEveryDestination(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('config down'));

		$refusal = (new OutboundAllowlist(appConfig: $config))->refusal(url: 'https://hooks.supplier.nl/in');

		$this->assertSame('the outbound allowlist (outboundAllowlist) could not be read, so no destination is admitted', $refusal);
	}//end testAListThatCannotBeReadRefusesEveryDestination()
}//end class
