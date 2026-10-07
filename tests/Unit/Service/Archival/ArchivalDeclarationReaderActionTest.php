<?php

declare(strict_types=1);

/**
 * What the reader does with an archival action it does not recognise.
 *
 * 🔴 THE DEFECT THIS EXISTS FOR. `declaredAppraisal()` resolved the schema's
 * `action` through the vocabulary and fell through to `Appraisal::DESTROY` when
 * the lookup missed. So a schema whose action said anything the vocabulary had
 * not heard of — a typo, a spelling from another standard, or `anonymiseren`
 * before the word existed here — nominated every one of its records for
 * destruction. Nothing failed and nothing warned: the sweep simply found them
 * eligible, under the schema's own apparent instruction.
 *
 * The default for "we do not know what this says" has to be the value that
 * means neither sweep may act. An undecided record is a question somebody
 * answers. A destroyed record is not recoverable.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ArchivalDeclarationReader;
use OCA\OpenRegister\Service\Archival\Appraisal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the archival action the reader resolves.
 */
class ArchivalDeclarationReaderActionTest extends TestCase {

	private LoggerInterface&MockObject $logger;
	private ArchivalDeclarationReader $reader;

	/**
	 * Wire the reader with a logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reader = new ArchivalDeclarationReader(logger: $this->logger);
	}//end setUp()

	/**
	 * A schema carrying one archival annotation with the given action.
	 *
	 * @param string|null $action The declared action, or null for none.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithAction(?string $action): Schema {
		$annotation = ['retention' => ['default' => 'P5Y']];
		if ($action !== null) {
			$annotation['action'] = $action;
		}

		$schema = new Schema();
		$schema->setArchive([]);
		$schema->setConfiguration(['x-openregister-archival' => $annotation]);

		return $schema;
	}//end schemaWithAction()

	/**
	 * A record for the reader to resolve against.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-1111-1111-111111111111');
		$object->setObject(['naam' => 'Fatima El-Amrani']);
		$object->setCreated('2020-01-01 00:00:00');

		return $object;
	}//end record()

	/**
	 * 🔴 THE REGRESSION. Reverting the fallback to `Appraisal::DESTROY` reddens
	 * the assertion below.
	 *
	 * @return void
	 */
	public function testAnUnknownActionDoesNotNominateTheRecordForDestruction(): void {
		$block = $this->reader->read(object: $this->record(), schema: $this->schemaWithAction('vernietgen'));

		$this->assertNotSame(
			Appraisal::DESTROY,
			($block['defaultNominatie'] ?? null),
			'a misspelled action must not read as an instruction to destroy'
		);
	}//end testAnUnknownActionDoesNotNominateTheRecordForDestruction()

	/**
	 * An unknown action is reported, so somebody can correct the schema rather
	 * than the records staying undecided forever in silence.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIsReported(): void {
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->reader->read(object: $this->record(), schema: $this->schemaWithAction('obliterate'));
	}//end testAnUnknownActionIsReported()

	/**
	 * A known action still resolves, so the refusal above is not a blanket.
	 *
	 * @return void
	 */
	public function testAKnownActionStillResolves(): void {
		$block = $this->reader->read(object: $this->record(), schema: $this->schemaWithAction('vernietigen'));

		$this->assertSame(Appraisal::DESTROY, ($block['defaultNominatie'] ?? null));
	}//end testAKnownActionStillResolves()

	/**
	 * The fourth word resolves through the reader too, which is what makes it
	 * configurable rather than a manual operation.
	 *
	 * @return void
	 */
	public function testAnonymiserenResolvesAsTheFourthAction(): void {
		$block = $this->reader->read(object: $this->record(), schema: $this->schemaWithAction('anonymiseren'));

		$this->assertSame(Appraisal::ANONYMISE, ($block['defaultNominatie'] ?? null));
	}//end testAnonymiserenResolvesAsTheFourthAction()

	/**
	 * No action at all still means destruction, which is the annotation's own
	 * meaning and is deliberately unchanged.
	 *
	 * @return void
	 */
	public function testNoActionStillMeansDestruction(): void {
		$block = $this->reader->read(object: $this->record(), schema: $this->schemaWithAction(null));

		$this->assertSame(Appraisal::DESTROY, ($block['defaultNominatie'] ?? null));
	}//end testNoActionStillMeansDestruction()
}//end class
