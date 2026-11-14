<?php

/**
 * OpenRegister AdvisoryAnnotationChecksTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Advisory annotations never refuse a save: an invalid block is logged and
 * ignored, a valid or absent one is silent.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationAnnotationValidator
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\AggregationSpecEntry
 * @uses   \OCA\OpenRegister\Search\Service\SearchAnnotationValidators
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\CrossSchemaTarget
 * @uses   \OCA\OpenRegister\Service\Hinge\DeclaredLens
 * @uses   \OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Merge\MergeAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Party\PartyAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Party\PartyPropertyFields
 * @uses   \OCA\OpenRegister\Service\Quality\DedupAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Quality\MasterDataAnnotationValidators
 * @uses   \OCA\OpenRegister\Service\Quality\QualityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators
 * @uses   \OCA\OpenRegister\Service\Survivorship\MdmAnnotationChecks
 * @uses   \OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator
 */
final class AdvisoryAnnotationChecksTest extends TestCase {
	/**
	 * Each check, its annotation key, the start of its warning and a block it rejects.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<mixed>}>
	 */
	public static function checks(): array {
		return [
			'aggregations' => ['checkAggregations', 'x-openregister-aggregations', 'x-openregister-aggregations annotation on schema "zaak" is invalid and was ignored (aggregation not registered): ', ['bogus' => ['nested' => true], 7 => 'x']],
			'quality'      => ['checkQuality', 'x-openregister-quality', 'x-openregister-quality annotation on schema "zaak" is invalid and was ignored (quality score not materialised): ', ['bogus' => ['nested' => true], 7 => 'x']],
			'dedup'        => ['checkDedup', 'x-openregister-dedup', 'x-openregister-dedup annotation on schema "zaak" is invalid and was ignored (declared match rules not used): ', ['bogus' => ['nested' => true], 7 => 'x']],
			'survivorship' => ['checkSurvivorship', 'x-openregister-survivorship', 'x-openregister-survivorship annotation on schema "zaak" is invalid and was ignored (golden record not materialised): ', ['bogus' => ['nested' => true], 7 => 'x']],
			'merge'        => ['checkMerge', 'x-openregister-merge', 'x-openregister-merge annotation on schema "zaak" is invalid and was ignored (merge falls back to defaults): ', ['reversalWindowDays' => -1]],
			'party'        => ['checkParty', 'x-openregister-party', 'x-openregister-party annotation on schema "zaak" is invalid and was ignored (the schema is not treated as a party schema): ', ['maxDepth' => 0]],
			'hinge'        => ['checkHinge', Schema::LENS_ANNOTATION, 'Hinge annotations on schema "zaak" are invalid and were ignored: ', ['byName' => 'not a lens']],
		];
	}//end checks()

	/**
	 * A schema slugged `zaak` with one property and the given configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * An invalid block is logged once, with the annotation named, and not thrown.
	 *
	 * @param string $method The check.
	 * @param string $key    Its annotation key.
	 * @param string       $prefix The start of its warning.
	 * @param array<mixed> $block  A block it rejects.
	 *
	 * @return void
	 */
	#[DataProvider('checks')]
	public function testAnInvalidBlockIsLoggedAndIgnored(string $method, string $key, string $prefix, array $block): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);

		(new AdvisoryAnnotationChecks(logger: $logger))->{$method}(self::schema([$key => $block]));

		self::assertCount(1, $logged);
		self::assertStringStartsWith($prefix, $logged[0]);
		self::assertGreaterThan(strlen($prefix), strlen($logged[0]), 'the validator messages follow');
	}//end testAnInvalidBlockIsLoggedAndIgnored()

	/**
	 * An invalid block is also recorded for the save response, named by its family,
	 * beside the log line (aggregation-dialect-repair, Fix 4).
	 *
	 * @param string       $method The check.
	 * @param string       $key    Its annotation key.
	 * @param string       $prefix The start of its warning.
	 * @param array<mixed> $block  A block it rejects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
	 */
	#[DataProvider('checks')]
	public function testAnInvalidBlockIsRecordedForTheSaveResponse(string $method, string $key, string $prefix, array $block): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');
		$discarded = new DiscardedAnnotations();

		(new AdvisoryAnnotationChecks(logger: $logger, discarded: $discarded))->{$method}(self::schema([$key => $block]));

		$family = ($method === 'checkHinge') ? 'hinge' : substr($key, strlen('x-openregister-'));
		$entries = $discarded->all();
		self::assertCount(1, $entries);
		self::assertSame($family, $entries[0]['family']);
		self::assertSame('zaak', $entries[0]['schema']);
		self::assertNotEmpty($entries[0]['messages']);
		// The response carries the messages the log line ends with.
		self::assertStringEndsWith(implode(' ', $entries[0]['messages']), self::lastMessageOf(method: $method, key: $key, block: $block));
	}//end testAnInvalidBlockIsRecordedForTheSaveResponse()

	/**
	 * The validator messages a check logs for a block, without the prefix.
	 *
	 * @param string       $method The check.
	 * @param string       $key    Its annotation key.
	 * @param array<mixed> $block  The block.
	 *
	 * @return string The logged text.
	 */
	private static function lastMessageOf(string $method, string $key, array $block): string {
		$logged = '';
		$logger = new class($logged) extends \Psr\Log\AbstractLogger {
			/**
			 * @param string $logged The text logged last.
			 */
			public function __construct(private string &$logged) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logged = (string)$message;
			}
		};
		(new AdvisoryAnnotationChecks(logger: $logger))->{$method}(self::schema([$key => $block]));

		return $logged;
	}//end lastMessageOf()

	/**
	 * A valid, absent or scalar annotation records nothing.
	 *
	 * @param string $method The check.
	 * @param string $key    Its annotation key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
	 */
	#[DataProvider('checks')]
	public function testNothingIsRecordedWithoutAFault(string $method, string $key): void {
		$discarded = new DiscardedAnnotations();
		$checks = new AdvisoryAnnotationChecks(logger: $this->createMock(LoggerInterface::class), discarded: $discarded);

		$checks->{$method}(self::schema([]));
		if ($method !== 'checkHinge') {
			$checks->{$method}(self::schema([$key => 'not a block']));
		}

		self::assertSame([], $discarded->all());
	}//end testNothingIsRecordedWithoutAFault()

	/**
	 * An absent or non-block annotation is not validated and logs nothing.
	 *
	 * @param string $method The check.
	 * @param string $key    Its annotation key.
	 *
	 * @return void
	 */
	#[DataProvider('checks')]
	public function testAnAbsentOrScalarAnnotationIsSilent(string $method, string $key): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$checks = new AdvisoryAnnotationChecks(logger: $logger);

		$checks->{$method}(self::schema([]));
		$checks->{$method}(self::schema(['x-openregister-other' => ['a' => 1]]));
		if ($method !== 'checkHinge') {
			$checks->{$method}(self::schema([$key => 'not a block']));
		}
	}//end testAnAbsentOrScalarAnnotationIsSilent()

	/**
	 * At save time a `schema` key naming a related schema is a cross-schema
	 * count, so its filter fields are not held to this schema's properties
	 * and nothing is discarded; one naming this schema still is, and a typo
	 * in it is logged (aggregation-dialect-repair, Fix 1).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/aggregation-api/spec.md#requirement-the-named-annotation-aggregation-surface-must-validate-filtergroupby-fields-against-the-schema-that-owns-them
	 */
	public function testAnAggregationOverARelatedSchemaIsKeptAtSave(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);
		$checks = new AdvisoryAnnotationChecks(logger: $logger);

		$checks->checkAggregations(
			self::schema(['x-openregister-aggregations' => ['documents' => ['metric' => 'count', 'schema' => 'document', 'filter' => ['zaak' => '@self.id']]]])
		);
		self::assertSame([], $logged, 'a count over a related schema was discarded');

		$checks->checkAggregations(
			self::schema(['x-openregister-aggregations' => ['titled' => ['metric' => 'count', 'schema' => 'Zaak', 'filter' => ['titel' => 'x']]]])
		);
		self::assertCount(1, $logged);
		self::assertStringContainsString('filter references unknown field "titel"', $logged[0]);
	}//end testAnAggregationOverARelatedSchemaIsKeptAtSave()
}//end class
