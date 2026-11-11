<?php

declare(strict_types=1);

/**
 * Golden test for ArchivalDeclarationReader: pins the exact outcomes (allowed and refused,
 * messages, codes, order and key order) captured on the code before the
 * A15 maintainability refactor, so the refactor provably changes nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use DateTime;
use OCA\OpenRegister\Service\Archival\ArchivalDeclarationReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ArchivalDeclarationReader.
 */
class ArchivalDeclarationReaderGoldenTest extends TestCase {

	/**
	 * A reader with a silent logger.
	 *
	 * @return ArchivalDeclarationReader The reader.
	 */
	private function reader(): ArchivalDeclarationReader {
		return new ArchivalDeclarationReader(logger: new NullLogger());
	}

	/**
	 * An object with the given data and a FIXED creation date.
	 *
	 * @param array<string, mixed>|null $data The object data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(?array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-4000-8000-000000000001');
		$object->setObject($data);
		$object->setCreated(new DateTime('2020-01-01T00:00:00+00:00'));
		return $object;
	}
	/**
	 * Golden: archiveEnabledWins.
	 *
	 * @return void
	 */
	public function testArchiveEnabledWins(): void {
		$this->assertSame(
			array (
			  'enabled' => true,
			  'defaultNominatie' => 'bewaren',
			),
			$this->caseArchiveEnabledWins()
		);
	}

	/**
	 * Golden: archiveDisabledNoAnnotation.
	 *
	 * @return void
	 */
	public function testArchiveDisabledNoAnnotation(): void {
		$this->assertSame(
			array (
			),
			$this->caseArchiveDisabledNoAnnotation()
		);
	}

	/**
	 * Golden: annotationThrows.
	 *
	 * @return void
	 */
	public function testAnnotationThrows(): void {
		$this->assertSame(
			array (
			),
			$this->caseAnnotationThrows()
		);
	}

	/**
	 * Golden: annotationDefault.
	 *
	 * @return void
	 */
	public function testAnnotationDefault(): void {
		$this->assertSame(
			array (
			  'enabled' => true,
			  'defaultNominatie' => 'destroy',
			  'defaultBewaartermijn' => 'P5Y',
			  'defaultRule' => 'archival_annotation',
			),
			$this->caseAnnotationDefault()
		);
	}

	/**
	 * Golden: annotationRuleCategoryOverride.
	 *
	 * @return void
	 */
	public function testAnnotationRuleCategoryOverride(): void {
		$this->assertSame(
			array (
			  'enabled' => true,
			  'defaultNominatie' => 'retain_permanently',
			  'defaultBewaartermijn' => 'P20Y',
			  'defaultRule' => 'archival_annotation',
			  'classification' => '11.1',
			  'classificationProperty' => 'cat',
			),
			$this->caseAnnotationRuleCategoryOverride()
		);
	}

	/**
	 * Golden: annotationUnknownAction.
	 *
	 * @return void
	 */
	public function testAnnotationUnknownAction(): void {
		$this->assertSame(
			array (
			  'enabled' => true,
			  'defaultNominatie' => 'not_yet_determined',
			  'defaultBewaartermijn' => 'P5Y',
			  'defaultRule' => 'archival_annotation',
			  'classification' => '4',
			),
			$this->caseAnnotationUnknownAction()
		);
	}

	private function caseArchiveEnabledWins(): mixed {
		$s = new Schema(); $s->setArchive(["enabled" => true, "defaultNominatie" => "bewaren"]); $s->setConfiguration(["x-openregister-archival" => ["retention" => ["default" => "P1Y"]]]); return $this->reader()->read($this->object(["a" => 1]), $s);
	}

	private function caseArchiveDisabledNoAnnotation(): mixed {
		$s = new Schema(); $s->setArchive(["enabled" => "true"]); $s->setConfiguration(["x-openregister-archival" => "nope"]); return $this->reader()->read($this->object([]), $s);
	}

	private function caseAnnotationThrows(): mixed {
		$s = new Schema(); $s->setArchive([]); $s->setConfiguration(["x-openregister-archival" => ["category" => "1.1"]]); return $this->reader()->read($this->object([]), $s);
	}

	private function caseAnnotationDefault(): mixed {
		$s = new Schema(); $s->setArchive([]); $s->setConfiguration(["x-openregister-archival" => ["retention" => ["default" => "P5Y"]]]); return $this->reader()->read($this->object([]), $s);
	}

	private function caseAnnotationRuleCategoryOverride(): mixed {
		$s = new Schema(); $s->setArchive([]); $s->setConfiguration(["x-openregister-archival" => ["retention" => ["default" => "P5Y", "rules" => [["condition" => "status == 'closed'", "retention" => "P20Y"]]], "category" => " 11.1 ", "categoryProperty" => "cat", "action" => "Bewaren"]]); return $this->reader()->read($this->object(["status" => "closed"]), $s);
	}

	private function caseAnnotationUnknownAction(): mixed {
		$s = new Schema(); $s->setArchive([]); $s->setConfiguration(["x-openregister-archival" => ["retention" => ["default" => "P5Y"], "action" => "verbranden", "category" => 4, "categoryProperty" => ""]]); return $this->reader()->read($this->object(null), $s);
	}
}
