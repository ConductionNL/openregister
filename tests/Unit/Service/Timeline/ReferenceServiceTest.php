<?php

/**
 * Unit tests for ReferenceService — a short code becomes a link, and the link
 * is recorded on both sides.
 *
 * Covers the declaration and its refusals, the instance that declares nothing
 * and therefore changes nothing, the code that resolves and the one that does
 * not, and the rewrite that removes a reference when the code leaves the text.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\EntryReference;
use OCA\OpenRegister\Db\EntryReferenceMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ReferencePattern;
use OCA\OpenRegister\Db\ReferencePatternMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ReferenceServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ReferenceServiceTest extends TestCase {
	/**
	 * Pattern mapper mock.
	 *
	 * @var ReferencePatternMapper&MockObject
	 */
	private ReferencePatternMapper&MockObject $patterns;

	/**
	 * Reference mapper mock.
	 *
	 * @var EntryReferenceMapper&MockObject
	 */
	private EntryReferenceMapper&MockObject $references;

	/**
	 * Object service mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * Service under test.
	 *
	 * @var ReferenceService
	 */
	private ReferenceService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->patterns = $this->createMock(ReferencePatternMapper::class);
		$this->references = $this->createMock(EntryReferenceMapper::class);
		$this->objects = $this->createMock(ObjectService::class);

		$this->service = new ReferenceService(
			$this->patterns,
			$this->references,
			$this->objects,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function zaakPattern(): ReferencePattern {
		$pattern = new ReferencePattern();
		$pattern->setSlug('zaaknummer');
		$pattern->setPattern('Z-\d{4}-\d{4}');
		$pattern->setRegister('zaken');
		$pattern->setSchema('zaak');
		$pattern->setUrlTemplate('/apps/dossiq/zaken/{code}');
		$pattern->setEnabled(true);

		return $pattern;
	}

	private function resolvesTo(?string $uuid): void {
		if ($uuid === null) {
			$this->objects->method('find')->willReturn(null);

			return;
		}

		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$this->objects->method('find')->willReturn($object);
	}

	public function testACaseNumberWrittenInASentenceBecomesALink(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->resolvesTo('zaak-uuid');

		$matches = $this->service->scan('zie Z-2026-0044 voor de achtergrond');

		$this->assertCount(1, $matches);
		$this->assertSame('Z-2026-0044', $matches[0]['code']);
		$this->assertSame('zaak-uuid', $matches[0]['targetUuid']);
		$this->assertSame('/apps/dossiq/zaken/Z-2026-0044', $matches[0]['url']);
	}

	public function testAnInstanceThatDeclaresNoPatternChangesNothing(): void {
		$this->patterns->method('findAll')->willReturn([]);

		$this->assertSame([], $this->service->scan('zie Z-2026-0044'));
	}

	public function testACodeThatNamesNothingResolvesToNothing(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->objects->method('find')->willThrowException(new \RuntimeException('no such object'));

		$matches = $this->service->scan('zie Z-2026-9999');

		$this->assertNull($matches[0]['targetUuid']);
	}

	public function testBothEndsHoldTheReference(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->resolvesTo('zaak-uuid');
		$this->references->method('insert')
			->willReturnCallback(static fn (EntryReference $ref): EntryReference => $ref);

		$written = $this->service->record('entry-a', 'case-1', 'zie Z-2026-0044');

		$this->assertCount(1, $written);
		$this->assertSame('case-1', $written[0]->getSourceUuid());
		$this->assertSame('zaak-uuid', $written[0]->getTargetUuid());
		$this->assertSame('entry-a', $written[0]->getEntryUuid());
	}

	public function testACodeThatResolvesToNothingIsNotRecorded(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->resolvesTo(null);
		$this->references->expects($this->never())->method('insert');

		$this->assertSame([], $this->service->record('entry-a', 'case-1', 'zie Z-2026-9999'));
	}

	public function testRemovingTheTextRemovesTheReference(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->resolvesTo('zaak-uuid');

		// The rewrite is a delete and a write, not a merge: a merge would keep
		// a reference to a code the sentence no longer carries.
		$this->references->expects($this->once())->method('deleteForEntry')->with('entry-a');
		$this->references->expects($this->never())->method('insert');

		$this->assertSame([], $this->service->record('entry-a', 'case-1', 'de verwijzing is eruit gehaald'));
	}

	public function testAPatternWithoutASlugIsRefused(): void {
		$this->expectException(TimelineValidationException::class);
		$this->service->declarePattern(['pattern' => 'Z-\d+']);
	}

	public function testAPatternWithoutAnExpressionIsRefused(): void {
		$this->expectException(TimelineValidationException::class);
		$this->service->declarePattern(['slug' => 'zaaknummer']);
	}

	public function testAnExpressionThatDoesNotCompileIsRefusedAtDeclarationTime(): void {
		// Refused here rather than thrown on the next note somebody writes.
		$this->expectException(TimelineValidationException::class);
		$this->service->declarePattern(['slug' => 'broken', 'pattern' => 'Z-[0-9']);
	}

	public function testDeclaringAPatternThatDoesNotExistInsertsIt(): void {
		$this->patterns->method('findBySlug')->willReturn(null);
		$this->patterns->expects($this->once())->method('insert')
			->willReturnCallback(static fn (ReferencePattern $p): ReferencePattern => $p);

		$pattern = $this->service->declarePattern(
			['slug' => 'Zaaknummer', 'pattern' => 'Z-\d{4}-\d{4}', 'urlTemplate' => '/z/{code}']
		);

		$this->assertSame('zaaknummer', $pattern->getSlug());
		$this->assertNotNull($pattern->getUuid());
	}

	public function testTheSameCodeTwiceInOneSentenceIsOneReference(): void {
		$this->patterns->method('findAll')->willReturn([$this->zaakPattern()]);
		$this->resolvesTo('zaak-uuid');

		$this->assertCount(1, $this->service->scan('Z-2026-0044 hoort bij Z-2026-0044'));
	}

	public function testAnUnusablePatternDoesNotStopTheOthers(): void {
		$broken = new ReferencePattern();
		$broken->setSlug('broken');
		// Stored directly, as a row written before validation existed would be.
		$broken->setPattern('Z-[0-9');
		$broken->setEnabled(true);

		$this->patterns->method('findAll')->willReturn([$broken, $this->zaakPattern()]);
		$this->resolvesTo('zaak-uuid');

		$matches = $this->service->scan('zie Z-2026-0044');

		$this->assertCount(1, $matches);
		$this->assertSame('zaaknummer', $matches[0]['patternSlug']);
	}
}
