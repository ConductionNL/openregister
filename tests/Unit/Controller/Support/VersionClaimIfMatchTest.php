<?php

/**
 * VersionClaim If-Match parsing tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\VersionClaim;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The body's _expectedUpdated wins; otherwise a quoted If-Match date is
 * normalised to ATOM, and anything that is not a real date claims nothing.
 */
class VersionClaimIfMatchTest extends TestCase {

	/**
	 * The claim of a request with the given body field and If-Match header.
	 *
	 * @param string|null $expected The _expectedUpdated parameter.
	 * @param string      $ifMatch  The If-Match header.
	 *
	 * @return string|null
	 */
	private function claim(?string $expected, string $ifMatch): ?string {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): ?string => ($key === '_expectedUpdated') ? $expected : null
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'If-Match') ? $ifMatch : ''
		);
		return (new VersionClaim($request))->version();
	}//end claim()

	/**
	 * The body field is returned as sent and beats the header.
	 *
	 * @return void
	 */
	public function testTheBodyFieldWins(): void {
		$this->assertSame('2026-01-01T00:00:00+00:00', $this->claim('2026-01-01T00:00:00+00:00', '"2025-01-01T00:00:00+00:00"'));
	}//end testTheBodyFieldWins()

	/**
	 * A quoted If-Match date is normalised to ATOM.
	 *
	 * @return void
	 */
	public function testAQuotedIfMatchDateIsNormalised(): void {
		$this->assertSame('2026-03-04T05:06:07+00:00', $this->claim(null, ' "2026-03-04T05:06:07+00:00" '));
	}//end testAQuotedIfMatchDateIsNormalised()

	/**
	 * An etag that is not date-shaped, or a date-shaped impossible date, claims nothing.
	 *
	 * @return void
	 */
	public function testNonDatesClaimNothing(): void {
		$this->assertNull($this->claim(null, '"abc123"'));
		$this->assertNull($this->claim('  ', ''));
		$this->assertNull($this->claim(null, '"2026-13-45"'));
	}//end testNonDatesClaimNothing()
}//end class
