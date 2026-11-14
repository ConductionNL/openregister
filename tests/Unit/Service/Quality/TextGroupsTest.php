<?php

/**
 * Unit tests for grouping near-identical files by their text signatures.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Quality\DedupScoringSources;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use OCA\OpenRegister\Search\Service\Quality\TextGroupFinder;
use OCA\OpenRegister\Search\Service\Quality\TextGroups;
use OCA\OpenRegister\Service\Quality\TextSignature;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\Quality\TextGroups
 * @covers \OCA\OpenRegister\Search\Service\Quality\TextGroupFinder
 * @covers \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService::findTextGroups
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupScoringSources
 * @uses   \OCA\OpenRegister\Search\Service\Quality\DedupRuleResolver
 * @uses   \OCA\OpenRegister\Search\Service\Quality\PayloadComparator
 * @uses   \OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolvers
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Service\Quality\TextSignature
 * @uses   \OCA\OpenRegister\Db\TextSignature
 */
final class TextGroupsTest extends TestCase {

	/**
	 * The stored signature rows the mapper serves, keyed by file id.
	 *
	 * @var array<int, TextSignatureRow>
	 */
	private array $rows = [];

	/**
	 * The threshold setting the app config serves.
	 *
	 * @var string
	 */
	private string $setting = '';

	/**
	 * The file ids the mapper was asked for.
	 *
	 * @var list<list<int>>
	 */
	private array $asked = [];

	/**
	 * The service under test, with a real finder over a mocked mapper and app config.
	 *
	 * @return DuplicateDetectionService The service.
	 */
	private function service(): DuplicateDetectionService {
		/** @var TextSignatureMapper&MockObject $mapper */
		$mapper = $this->createMock(TextSignatureMapper::class);
		$mapper->method('findByFileIds')->willReturnCallback(
			function (array $fileIds): array {
				$this->asked[] = $fileIds;
				return array_intersect_key($this->rows, array_flip($fileIds));
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($app === 'openregister' && $key === 'duplicates.textThreshold') ? $this->setting : $default
		);

		return $this->bareService(finder: new TextGroupFinder($mapper, $config));
	}//end service()

	/**
	 * The service with only the given finder wired in.
	 *
	 * @param TextGroupFinder $finder The finder.
	 *
	 * @return DuplicateDetectionService The service.
	 */
	private function bareService(TextGroupFinder $finder): DuplicateDetectionService {
		$sources = new DedupScoringSources(
			new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class)),
			new SimilarityCalculator()
		);

		return new DuplicateDetectionService(
			$this->createMock(ObjectOperationsInterface::class),
			$sources,
			$this->createMock(DismissedPairStore::class),
			$this->createMock(LoggerInterface::class),
			$finder
		);
	}//end bareService()

	/**
	 * Store the signature row of a text for a file.
	 *
	 * @param int    $fileId The file id.
	 * @param string $text   The text.
	 *
	 * @return void
	 */
	private function store(int $fileId, string $text): void {
		$this->rows[$fileId] = TextSignatureRow::fromRecord($fileId, (new TextSignature())->sign($text), new \DateTime('2026-11-13 10:00:00'));
	}//end store()

	/**
	 * A mail, its forward, an edited print and a re-cased copy form one group; the rest is reported.
	 *
	 * @return void
	 */
	public function testThreeCopiesFormOneGroup(): void {
		$mail = NearDuplicateTexts::letter(seed: 11);
		$this->store(11, $mail);
		$this->store(12, "Doorgestuurd bericht:\n" . $mail);
		$this->store(13, NearDuplicateTexts::edited(text: $mail, positions: [150]));
		$this->store(14, NearDuplicateTexts::letter(seed: 99));
		$this->store(15, NearDuplicateTexts::letter(seed: 3, words: 20));
		$this->store(16, '');
		$this->store(18, strtoupper($mail));

		$result = $this->service()->findTextGroups([18, 17, 16, 15, 14, 13, 12, 11, 11]);

		$this->assertCount(1, $result['groups']);
		$group = $result['groups'][0];
		$this->assertSame(11, $group['representative']);
		$this->assertSame([11, 12, 13, 18], array_column($group['members'], 'fileId'));
		$this->assertSame([true, false, false, true], array_column($group['members'], 'identicalText'));
		$this->assertSame(1.0, $group['members'][0]['similarity']);
		$this->assertSame(1.0, $group['members'][3]['similarity']);
		foreach ($group['members'] as $member) {
			$this->assertGreaterThanOrEqual(0.9, $member['similarity']);
		}

		$this->assertSame(
			[
				['fileId' => 15, 'reason' => 'tooShort'],
				['fileId' => 16, 'reason' => 'noText'],
				['fileId' => 17, 'reason' => 'notExtracted'],
			],
			$result['notCompared']
		);
		$this->assertSame([[11, 12, 13, 14, 15, 16, 17, 18]], $this->asked);
	}//end testThreeCopiesFormOneGroup()

	/**
	 * Files that are not alike form no group, and an empty scope answers empty.
	 *
	 * @return void
	 */
	public function testUnrelatedFilesFormNoGroup(): void {
		$this->store(1, NearDuplicateTexts::letter(seed: 21));
		$this->store(2, NearDuplicateTexts::letter(seed: 22));

		$this->assertSame(['groups' => [], 'notCompared' => []], $this->service()->findTextGroups([1, 2]));
		$this->assertSame(['groups' => [], 'notCompared' => []], $this->service()->findTextGroups([]));
	}//end testUnrelatedFilesFormNoGroup()

	/**
	 * The earliest created file represents its group, whatever its id; without dates the lowest id does.
	 *
	 * @return void
	 */
	public function testTheEarliestFileRepresents(): void {
		$signer = new TextSignature();
		$text   = NearDuplicateTexts::letter(seed: 31);
		$files  = [
			20 => ['signature' => $signer->sign($text)['signature'], 'textSha256' => 'a', 'created' => new DateTimeImmutable('2026-03-01')],
			30 => ['signature' => $signer->sign($text . ' bijlage')['signature'], 'textSha256' => 'b', 'created' => new DateTimeImmutable('2026-01-01')],
			40 => ['signature' => $signer->sign($text)['signature'], 'textSha256' => 'a', 'created' => null],
		];

		$groups = (new TextGroups())->group($files, 0.9);

		$this->assertSame(30, $groups[0]['representative']);
		$this->assertSame([30, 20, 40], array_column($groups[0]['members'], 'fileId'));
		$this->assertSame([true, false, false], array_column($groups[0]['members'], 'identicalText'));

		$this->store(40, $text);
		$this->store(20, $text);
		$this->assertSame(20, $this->service()->findTextGroups([40, 20])['groups'][0]['representative']);
	}//end testTheEarliestFileRepresents()

	/**
	 * Among 1000 distinct signatures banding compares a handful of pairs, not all 499500, and still finds the copies.
	 *
	 * @return void
	 */
	public function testBandingDoesNotCompareAllPairs(): void {
		mt_srand(42);
		$files = [];
		for ($fileId = 1; $fileId <= 1000; $fileId++) {
			$signature = [];
			for ($i = 0; $i < TextSignature::HASHES; $i++) {
				$signature[] = mt_rand(0, 2147483646);
			}

			$files[$fileId] = ['signature' => $signature, 'textSha256' => (string)$fileId, 'created' => null];
		}

		mt_srand();
		$copy               = $files[500]['signature'];
		$copy[0]            = -1;
		$files[1001]        = ['signature' => $copy, 'textSha256' => 'copy', 'created' => null];

		$grouper = new TextGroups();
		$groups  = $grouper->group($files, 0.9);

		$this->assertLessThan(100, $grouper->comparisons());
		$this->assertGreaterThanOrEqual(1, $grouper->comparisons());
		$this->assertSame(
			[['representative' => 500, 'members' => [['fileId' => 500, 'similarity' => 1.0, 'identicalText' => true], ['fileId' => 1001, 'similarity' => 127 / 128, 'identicalText' => false]]]],
			$groups
		);
	}//end testBandingDoesNotCompareAllPairs()

	/**
	 * A threshold below 0.5 or above 1, given or configured, is refused; a non-numeric setting falls back to 0.9.
	 *
	 * @return void
	 */
	public function testAThresholdOutsideRangeIsRefused(): void {
		foreach ([0.49, 1.01, NAN] as $threshold) {
			try {
				$this->service()->findTextGroups([1], $threshold);
				$this->fail('Threshold ' . $threshold . ' was accepted');
			} catch (InvalidArgumentException $refused) {
				$this->assertStringContainsString('between 0.5 and 1', $refused->getMessage());
			}
		}

		$this->assertSame(0.5, (new TextGroups())->checkedThreshold(0.5));
		$this->assertSame(1.0, (new TextGroups())->checkedThreshold(1.0));

		$this->setting = '0.3';
		$this->expectException(InvalidArgumentException::class);
		$this->service()->findTextGroups([1]);
	}//end testAThresholdOutsideRangeIsRefused()

	/**
	 * The configured threshold applies when none is given, and a non-numeric one falls back to 0.9.
	 *
	 * @return void
	 */
	public function testTheConfiguredThresholdApplies(): void {
		$text = NearDuplicateTexts::letter(seed: 41);
		$this->store(1, $text);
		$this->store(2, NearDuplicateTexts::edited(text: $text, positions: [20, 60, 100, 140, 180, 220, 260]));
		$similarity = (new TextSignature())->estimate($this->rows[1]->getSignatureValues(), $this->rows[2]->getSignatureValues());
		$this->assertGreaterThan(0.5, $similarity);
		$this->assertLessThan(0.9, $similarity);

		$this->setting = 'abc';
		$this->assertSame([], $this->service()->findTextGroups([1, 2])['groups']);

		$this->setting = ' 0.5 ';
		$this->assertCount(1, $this->service()->findTextGroups([1, 2])['groups']);
		$this->assertSame([], $this->service()->findTextGroups([1, 2], 0.95)['groups']);
	}//end testTheConfiguredThresholdApplies()
}//end class
