<?php

/**
 * Refusal tests for TokenGrantValidator not pinned elsewhere.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unreadable end dates, a non-list axis, rate limits and the lapse warning's edges.
 *
 * The clock is fixed; nothing here reads the real time.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantValidator
 */
class TokenGrantValidatorRefusalTest extends TestCase {

	private const NOW = '2026-09-18T12:00:00+00:00';

	/**
	 * The validator.
	 *
	 * @return TokenGrantValidator
	 */
	private function validator(): TokenGrantValidator {
		return new TokenGrantValidator(catalogue: new PermissionCatalogue());
	}//end validator()

	/**
	 * The refusal for an otherwise acceptable grant with overrides.
	 *
	 * @param array<string, mixed> $overrides Grant fields to change.
	 *
	 * @return string|null
	 */
	private function refusal(array $overrides): ?string {
		$grant = array_merge(['verbs' => ['read'], 'expiresAt' => '2026-11-01T00:00:00+00:00'], $overrides);
		return $this->validator()->refusalFor(issue: new TokenGrantIssue(grant: $grant, issuerVerbs: ['read'], now: new DateTimeImmutable(self::NOW)));
	}//end refusal()

	/**
	 * An end date that is not a date is refused, naming the value.
	 *
	 * @return void
	 */
	public function testUnreadableEndDateIsRefused(): void {
		$this->assertSame('the end date "next tuesday-ish" could not be read as a date', $this->refusal(overrides: ['expiresAt' => 'next tuesday-ish']));
	}//end testUnreadableEndDateIsRefused()

	/**
	 * A scope axis that is not a list is refused.
	 *
	 * @return void
	 */
	public function testNonListAxisIsRefused(): void {
		$this->assertSame('"registers" must be a list of slugs when it is present at all', $this->refusal(overrides: ['registers' => 'zaken']));
	}//end testNonListAxisIsRefused()

	/**
	 * A rate limit must be a positive number; a valid one and none are accepted.
	 *
	 * @return void
	 */
	public function testRateLimitMustBePositive(): void {
		$refused = 'a rate limit must be a positive number of calls per minute';
		$this->assertSame($refused, $this->refusal(overrides: ['rateLimit' => 0]));
		$this->assertSame($refused, $this->refusal(overrides: ['rateLimit' => 'fast']));
		$this->assertNull($this->refusal(overrides: ['rateLimit' => '60']));
		$this->assertNull($this->refusal(overrides: []));
	}//end testRateLimitMustBePositive()

	/**
	 * A grant with no end date never "lapses soon", and neither does one already lapsed.
	 *
	 * @return void
	 */
	public function testLapseWarningEdges(): void {
		$now = new DateTimeImmutable(self::NOW);

		$this->assertFalse($this->validator()->lapsesSoon(grant: new TokenGrant(verbs: ['read']), now: $now));
		$lapsed = new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('2026-09-10T00:00:00+00:00')));
		$this->assertFalse($this->validator()->lapsesSoon(grant: $lapsed, now: $now));
		$today = new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('2026-09-18T18:00:00+00:00')));
		$this->assertTrue($this->validator()->lapsesSoon(grant: $today, now: $now));
		$this->assertFalse(TokenGrantValidator::lapsesWithin(daysLeft: $today->daysLeft(now: $now), within: -1));
	}//end testLapseWarningEdges()
}//end class
