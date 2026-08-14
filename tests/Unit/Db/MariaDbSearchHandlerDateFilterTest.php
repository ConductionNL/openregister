<?php

/**
 * Unit tests for MariaDbSearchHandler date-filter normalisation.
 *
 * Locks in task 6.5 of the fix-empty-string-date-conversion change: a search
 * filter on a date field (`created`/`updated`) supplied as an empty or
 * whitespace-only string must normalise to `null` rather than to the moment
 * of the query. A `null` normalised value means no concrete datetime predicate
 * is emitted for an empty filter — the empty filter becomes a no-op instead of
 * silently filtering on "now".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectHandlers\MariaDbSearchHandler;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @group DB
 */
class MariaDbSearchHandlerDateFilterTest extends TestCase {

	private MariaDbSearchHandler $handler;

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$normalizer = new DateTimeNormalizer($logger);
		$this->handler = new MariaDbSearchHandler($normalizer);
	}//end setUp()

	private function invokeNormalize(string $field, mixed $value): mixed {
		$method = new ReflectionMethod(MariaDbSearchHandler::class, 'normalizeDateValue');
		$method->setAccessible(true);
		return $method->invoke($this->handler, $field, $value);
	}//end invokeNormalize()

	public function testEmptyStringDateFilterNormalisesToNull(): void {
		$this->assertNull(
			$this->invokeNormalize('created', ''),
			'an empty-string filter on a date field must normalise to null (no concrete datetime predicate)'
		);
	}//end testEmptyStringDateFilterNormalisesToNull()

	public function testWhitespaceDateFilterNormalisesToNull(): void {
		$this->assertNull(
			$this->invokeNormalize('updated', '   '),
			'a whitespace-only filter on a date field must normalise to null'
		);
	}//end testWhitespaceDateFilterNormalisesToNull()

	public function testValidDateFilterNormalisesToDatabaseFormat(): void {
		$result = $this->invokeNormalize('created', '2026-04-20T14:00:00+00:00');
		$this->assertIsString($result, 'a valid date filter must produce a database-format string');
		$this->assertSame('2026-04-20 14:00:00', $result);
	}//end testValidDateFilterNormalisesToDatabaseFormat()

	public function testNonDateFieldPassesThroughUnchanged(): void {
		// A filter on a non-date field is returned verbatim — the normaliser
		// must not touch text/term filters.
		$this->assertSame(
			'someTextValue',
			$this->invokeNormalize('name', 'someTextValue'),
			'a non-date field filter must pass through unchanged'
		);
	}//end testNonDateFieldPassesThroughUnchanged()
}//end class
