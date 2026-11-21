<?php

/**
 * A ShareLifetime on a clock that stands still, for tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation\Support;

use DateTime;
use OCA\OpenRegister\Service\Federation\ShareLifetime;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Builds a ShareLifetime whose clock reads one fixed moment.
 */
final class FixedClock {

	/**
	 * The moment the clock reads.
	 *
	 * @var string
	 */
	public const NOW = '2026-11-11 12:00:00';

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test that builds the mock clock.
	 */
	public function __construct(private readonly TestCase $test) {
	}//end __construct()

	/**
	 * The lifetime on the fixed clock.
	 *
	 * @param string $now The moment the clock reads.
	 *
	 * @return ShareLifetime
	 */
	public function lifetime(string $now = self::NOW): ShareLifetime {
		$time = $this->test->getMockBuilder(ITimeFactory::class)->getMock();
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime($now));

		return new ShareLifetime(time: $time);
	}//end lifetime()
}//end class
