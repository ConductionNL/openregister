<?php

/**
 * Mutation-killing tests for the validator that refuses an over-wide token grant.
 *
 * Written against the mutants Infection left alive in TokenGrantValidator:
 * which refusal wins when a grant is wrong in two ways, the missing and the
 * unreadable end date, an empty schemas axis when registers is absent, the
 * rate limit, and the boundaries of the "lapses soon" window. The clock is a
 * fixed UTC instant, never the wall clock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenGrantValidatorMutationTest extends TestCase {
	private const MISSING_END = 'a grant must carry an end date; a token without one is not issued';

	private const RATE = 'a rate limit must be a positive number of calls per minute';

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-09T12:00:00', new DateTimeZone('UTC'));
	}//end now()

	private function validator(): TokenGrantValidator {
		return new TokenGrantValidator(new PermissionCatalogue());
	}//end validator()

	/**
	 * @param array<string, mixed> $overrides Fields that replace the valid defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function grant(array $overrides=[]): array {
		return array_merge(['verbs' => ['read'], 'expiresAt' => '2026-12-31T00:00:00+00:00'], $overrides);
	}//end grant()

	private function refusal(array $grant): ?string {
		return $this->validator()->refusalFor(issue: new TokenGrantIssue($grant, ['read', 'update'], $this->now()));
	}//end refusal()

	public function testAValidGrantIsAccepted(): void {
		$this->assertNull($this->refusal($this->grant(['rateLimit' => 60, 'schemas' => ['zaken']])));
	}//end testAValidGrantIsAccepted()

	public function testVerbRefusalWinsOverAMissingEndDate(): void {
		$refusal = $this->refusal(['verbs' => ['update', 'delete']]);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('"delete" is wider than the issuer', $refusal);
	}//end testVerbRefusalWinsOverAMissingEndDate()

	public function testExpiryRefusalWinsOverAnEmptyAxis(): void {
		$this->assertSame(self::MISSING_END, $this->refusal(['verbs' => ['read'], 'registers' => []]));
	}//end testExpiryRefusalWinsOverAnEmptyAxis()

	public function testAxisRefusalWinsOverABadRateLimit(): void {
		$refusal = $this->refusal($this->grant(['registers' => [], 'rateLimit' => 0]));

		$this->assertNotNull($refusal);
		$this->assertStringStartsWith('"registers" is present but empty', $refusal);
	}//end testAxisRefusalWinsOverABadRateLimit()

	public function testAnEmptySchemasAxisIsRefusedWhenRegistersIsAbsent(): void {
		$refusal = $this->refusal($this->grant(['schemas' => []]));

		$this->assertNotNull($refusal);
		$this->assertStringStartsWith('"schemas" is present but empty', $refusal);
	}//end testAnEmptySchemasAxisIsRefusedWhenRegistersIsAbsent()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function missingEndDates(): array {
		return [
			'absent' => [null],
			'empty'  => [''],
			'number' => [20261231],
		];
	}//end missingEndDates()

	#[DataProvider('missingEndDates')]
	public function testAGrantWithoutAnEndDateIsRefused(mixed $expiresAt): void {
		$grant = $this->grant(['expiresAt' => $expiresAt]);
		if ($expiresAt === null) {
			unset($grant['expiresAt']);
		}

		$this->assertSame(self::MISSING_END, $this->refusal($grant));
	}//end testAGrantWithoutAnEndDateIsRefused()

	public function testAnUnreadableEndDateIsNamedAsUnreadable(): void {
		$this->assertSame(
			'the end date "next tuesday-ish" could not be read as a date',
			$this->refusal($this->grant(['expiresAt' => 'next tuesday-ish']))
		);
	}//end testAnUnreadableEndDateIsNamedAsUnreadable()

	/**
	 * @return array<string, array{0: mixed, 1: ?string}>
	 */
	public static function rateLimits(): array {
		return [
			'one call'         => [1, null],
			'numeric string'   => ['30', null],
			'zero'             => [0, self::RATE],
			'zero as string'   => ['0', self::RATE],
			'negative'         => [-5, self::RATE],
			'not a number'     => ['lots', self::RATE],
			'a list'           => [[60], self::RATE],
		];
	}//end rateLimits()

	#[DataProvider('rateLimits')]
	public function testRateLimit(mixed $rateLimit, ?string $expected): void {
		$this->assertSame($expected, $this->refusal($this->grant(['rateLimit' => $rateLimit])));
	}//end testRateLimit()

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function lapseWindow(): array {
		return [
			'lapses within the hour'     => ['2026-11-09T13:00:00+00:00', true],
			'exactly fourteen days left' => ['2026-11-23T12:00:00+00:00', true],
			'fifteen days left'          => ['2026-11-24T12:00:00+00:00', false],
			'thirteen days left'         => ['2026-11-22T12:00:00+00:00', true],
			'already lapsed'             => ['2026-11-08T12:00:00+00:00', false],
		];
	}//end lapseWindow()

	#[DataProvider('lapseWindow')]
	public function testLapsesSoonWindowDefaultsToFourteenDays(string $expiresAt, bool $expected): void {
		$grant = TokenGrant::fromStored(['verbs' => ['read'], 'expiresAt' => $expiresAt]);
		$this->assertNotNull($grant);

		$this->assertSame($expected, $this->validator()->lapsesSoon($grant, $this->now()));
	}//end testLapsesSoonWindowDefaultsToFourteenDays()

	public function testLapsesSoonHonoursANarrowerWindow(): void {
		$grant = TokenGrant::fromStored(['verbs' => ['read'], 'expiresAt' => '2026-11-16T12:00:00+00:00']);
		$this->assertNotNull($grant);

		$this->assertTrue(TokenGrantValidator::lapsesWithin($grant->daysLeft($this->now()), 7));
		$this->assertFalse(TokenGrantValidator::lapsesWithin($grant->daysLeft($this->now()), 6));
	}//end testLapsesSoonHonoursANarrowerWindow()
}//end class
