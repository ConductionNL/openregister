<?php

/**
 * SecurityTxtBuilderTest — nothing administered means no file.
 *
 * The test that matters is the empty one. A `security.txt` naming
 * `security@example.com` reads as a working disclosure channel and swallows the
 * report, which is strictly worse than the 404 that at least tells a researcher
 * to find another way in.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\WellKnown
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\WellKnown;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder
 */
class SecurityTxtBuilderTest extends TestCase {

	/**
	 * Build the builder over administered values.
	 *
	 * @param array<string, string> $values The administered configuration.
	 *
	 * @return SecurityTxtBuilder The builder.
	 */
	private function builder(array $values = []): SecurityTxtBuilder {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		return new SecurityTxtBuilder($appConfig);
	}//end builder()

	/**
	 * A fixed moment, so `Expires` is checkable.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private static function moment(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-16T00:00:00', new DateTimeZone('UTC'));
	}//end moment()

	public function testAnUnadministeredInstanceServesNoFile(): void {
		$this->assertNull(
			$this->builder()->build(),
			'A file naming security@example.com reads as a working channel and swallows the report.'
		);
		$this->assertFalse($this->builder()->isConfigured());
	}//end testAnUnadministeredInstanceServesNoFile()

	public function testAResponsibleDisclosureContactIsFindable(): void {
		$body = $this->builder(
			[SecurityTxtBuilder::CONTACT_KEY => 'mailto:security@gemeente.test']
		)->build(now: self::moment());

		$this->assertStringContainsString('Contact: mailto:security@gemeente.test', $body);
	}//end testAResponsibleDisclosureContactIsFindable()

	public function testABareAddressBecomesAMailtoUri(): void {
		$body = $this->builder(
			[SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test']
		)->build(now: self::moment());

		$this->assertStringContainsString(
			'Contact: mailto:security@gemeente.test',
			$body,
			'Refusing an administrator over a scheme is how the field ends up empty.'
		);
	}//end testABareAddressBecomesAMailtoUri()

	public function testAnHttpsFormIsAcceptedAsAContact(): void {
		$body = $this->builder(
			[SecurityTxtBuilder::CONTACT_KEY => 'https://gemeente.test/melden']
		)->build(now: self::moment());

		$this->assertStringContainsString('Contact: https://gemeente.test/melden', $body);
	}//end testAnHttpsFormIsAcceptedAsAContact()

	public function testGarbageIsNotAContact(): void {
		$this->assertNull(
			$this->builder([SecurityTxtBuilder::CONTACT_KEY => 'ask Jan'])->build(),
			'A contact nobody can reach is the failure mode this file exists to avoid.'
		);
	}//end testGarbageIsNotAContact()

	public function testExpiresIsMandatoryAndComputedForward(): void {
		$body = $this->builder(
			[SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test']
		)->build(now: self::moment());

		$this->assertStringContainsString(
			'Expires: 2026-12-15T00:00:00Z',
			$body,
			'RFC 9116 requires Expires, and a stored date is a date nobody remembers to move.'
		);
	}//end testExpiresIsMandatoryAndComputedForward()

	public function testExpiresMovesWithTheClock(): void {
		$builder = $this->builder([SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test']);

		$first = $builder->build(now: self::moment());
		$later = $builder->build(now: self::moment()->modify('+200 days'));

		$this->assertNotSame(
			$first,
			$later,
			'A file that never moves its Expires is publishing, correctly, that it should not be trusted.'
		);
	}//end testExpiresMovesWithTheClock()

	public function testThePolicyUrlIsIncludedWhenItIsOne(): void {
		$body = $this->builder(
			[
				SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test',
				SecurityTxtBuilder::POLICY_KEY => 'https://gemeente.test/responsible-disclosure',
			]
		)->build(now: self::moment());

		$this->assertStringContainsString('Policy: https://gemeente.test/responsible-disclosure', $body);
	}//end testThePolicyUrlIsIncludedWhenItIsOne()

	public function testANonUrlPolicyIsLeftOutRatherThanShippedBroken(): void {
		$body = $this->builder(
			[
				SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test',
				SecurityTxtBuilder::POLICY_KEY => 'see the intranet',
			]
		)->build(now: self::moment());

		$this->assertStringNotContainsString('Policy:', $body);
	}//end testANonUrlPolicyIsLeftOutRatherThanShippedBroken()

	public function testPreferredLanguagesAreValidatedAsTags(): void {
		$body = $this->builder(
			[
				SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test',
				SecurityTxtBuilder::LANGUAGES_KEY => 'nl, en-GB, not a tag, fy',
			]
		)->build(now: self::moment());

		$this->assertStringContainsString('Preferred-Languages: nl, en-GB, fy', $body);
	}//end testPreferredLanguagesAreValidatedAsTags()

	public function testTheFileEndsWithANewline(): void {
		$body = $this->builder(
			[SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test']
		)->build(now: self::moment());

		$this->assertStringEndsWith("\n", $body);
	}//end testTheFileEndsWithANewline()
}//end class
