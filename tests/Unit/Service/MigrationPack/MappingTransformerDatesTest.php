<?php

/**
 * Unit tests for the shorthand trim transform and the date transform of a migration mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Service\MigrationPack\MappingTransformer;
use OCA\OpenRegister\Service\MigrationPack\TransformRequest;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * `type: true` trims; a date without a source format is parsed freely, and an unparseable one is a named error.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\MappingTransformer
 */
class MappingTransformerDatesTest extends TestCase {

	/**
	 * One transform request.
	 *
	 * @param mixed                     $value     The source value.
	 * @param array<string, mixed>|null $transform The transform block.
	 * @param array<string, mixed>      $row       The source row.
	 *
	 * @return TransformRequest
	 */
	private static function request(mixed $value, ?array $transform, array $row): TransformRequest {
		return new TransformRequest(value: $value, transform: $transform, sourceRow: $row);
	}//end request()

	/**
	 * The transformer over an l10n that formats its arguments.
	 *
	 * @return MappingTransformer
	 */
	private function transformer(): MappingTransformer {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				foreach (array_values($parameters) as $i => $value) {
					$text = str_replace('%' . ($i + 1) . '$s', (string)$value, $text);
				}

				return $parameters === [] ? $text : str_replace('%s', (string)$parameters[0], $text);
			}
		);

		return new MappingTransformer($l10n);

	}//end transformer()

	/**
	 * The shorthand `type: true` trims the value.
	 *
	 * @return void
	 */
	public function testTheTrueShorthandTrims(): void {
		$this->assertSame(['value' => 'Jansen', 'error' => null], $this->transformer()->apply(self::request("  Jansen \n", ['type' => true], [])));

	}//end testTheTrueShorthandTrims()

	/**
	 * A date without a source format is parsed freely and written in the target format.
	 *
	 * @return void
	 */
	public function testADateWithoutASourceFormatIsParsedFreely(): void {
		$this->assertSame(
			['value' => '01-09-2026', 'error' => null],
			$this->transformer()->apply(self::request('2026-09-01T10:00:00+00:00', ['type' => 'date', 'targetFormat' => 'd-m-Y'], []))
		);
		$this->assertSame(['value' => '2026-09-01', 'error' => null], $this->transformer()->apply(self::request('1 September 2026', ['type' => 'date'], [])));

	}//end testADateWithoutASourceFormatIsParsedFreely()

	/**
	 * An unparseable date without a source format is an error naming the value.
	 *
	 * @return void
	 */
	public function testAnUnparseableFreeDateIsANamedError(): void {
		$result = $this->transformer()->apply(self::request('not a date', ['type' => 'date'], []));

		$this->assertNull($result['value']);
		$this->assertStringStartsWith('Could not parse date "not a date": ', $result['error']);

	}//end testAnUnparseableFreeDateIsANamedError()
}//end class
