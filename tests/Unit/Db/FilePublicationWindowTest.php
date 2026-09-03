<?php

/**
 * Unit tests for the file publication window.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\File;
use PHPUnit\Framework\TestCase;

/**
 * Locks the window rule. Each null in it means something different, and the
 * whole reason the rule is a method rather than an inline comparison is that
 * treating them alike is the mistake it exists to prevent.
 */
class FilePublicationWindowTest extends TestCase {

	/**
	 * The moment every case is evaluated against.
	 */
	private const NOW = '2026-06-15 12:00:00';

	/**
	 * Build a file with a window.
	 *
	 * @param string|null $published   When it becomes public.
	 * @param string|null $depublished When it stops.
	 *
	 * @return File The file.
	 */
	private function file(?string $published, ?string $depublished = null): File {
		$file = new File();
		$file->setFileId(1);

		if ($published !== null) {
			$file->setPublished(new DateTime($published));
		}

		if ($depublished !== null) {
			$file->setDepublished(new DateTime($depublished));
		}

		return $file;

	}//end file()

	/**
	 * The moment under test.
	 *
	 * @return DateTime The evaluation moment.
	 */
	private function now(): DateTime {
		return new DateTime(self::NOW);

	}//end now()

	/**
	 * No publication date means never published. It must NOT fall back to the
	 * creation time: that is what made every file that had ever existed look
	 * published, and made "not published" unrepresentable.
	 *
	 * @return void
	 */
	public function testAFileWithNoPublicationDateIsNotPublished(): void {
		$this->assertFalse($this->file(published: null)->isPublishedAt(now: $this->now()));

	}//end testAFileWithNoPublicationDateIsNotPublished()

	/**
	 * A depublication date without a publication date does not publish
	 * anything. An end date is not a start date.
	 *
	 * @return void
	 */
	public function testADepublicationDateAloneDoesNotPublish(): void {
		$this->assertFalse(
			$this->file(published: null, depublished: '2030-01-01 00:00:00')->isPublishedAt(now: $this->now())
		);

	}//end testADepublicationDateAloneDoesNotPublish()

	/**
	 * A future publication date is not yet published, which is the whole point
	 * of being able to set one.
	 *
	 * @return void
	 */
	public function testAFuturePublicationDateIsNotYetPublished(): void {
		$this->assertFalse($this->file(published: '2026-06-16 12:00:00')->isPublishedAt(now: $this->now()));

	}//end testAFuturePublicationDateIsNotYetPublished()

	/**
	 * No depublication date means no end date, not an end date in the past.
	 * This is the ordinary case and the one a careless comparison gets wrong.
	 *
	 * @return void
	 */
	public function testNoDepublicationDateMeansItStaysPublished(): void {
		$this->assertTrue($this->file(published: '2020-01-01 00:00:00')->isPublishedAt(now: $this->now()));

	}//end testNoDepublicationDateMeansItStaysPublished()

	/**
	 * A depublication date that has passed ends publication.
	 *
	 * @return void
	 */
	public function testAPassedDepublicationDateEndsPublication(): void {
		$this->assertFalse(
			$this->file(published: '2020-01-01 00:00:00', depublished: '2026-06-15 09:00:00')
				->isPublishedAt(now: $this->now())
		);

	}//end testAPassedDepublicationDateEndsPublication()

	/**
	 * A depublication date still ahead keeps the file published.
	 *
	 * @return void
	 */
	public function testAFutureDepublicationDateKeepsItPublished(): void {
		$this->assertTrue(
			$this->file(published: '2020-01-01 00:00:00', depublished: '2026-06-16 00:00:00')
				->isPublishedAt(now: $this->now())
		);

	}//end testAFutureDepublicationDateKeepsItPublished()

	/**
	 * The start boundary is inclusive: a file published at exactly this instant
	 * is published.
	 *
	 * @return void
	 */
	public function testTheStartBoundaryIsInclusive(): void {
		$this->assertTrue($this->file(published: self::NOW)->isPublishedAt(now: $this->now()));

	}//end testTheStartBoundaryIsInclusive()

	/**
	 * The end boundary is exclusive, so a file published and depublished at the
	 * same instant is not public. A zero-length window publishes nothing.
	 *
	 * @return void
	 */
	public function testTheEndBoundaryIsExclusive(): void {
		$this->assertFalse(
			$this->file(published: self::NOW, depublished: self::NOW)->isPublishedAt(now: $this->now())
		);

	}//end testTheEndBoundaryIsExclusive()

	/**
	 * A window whose end precedes its start publishes nothing, rather than
	 * reading as published because the start has passed.
	 *
	 * @return void
	 */
	public function testAnInvertedWindowPublishesNothing(): void {
		$this->assertFalse(
			$this->file(published: '2020-01-01 00:00:00', depublished: '2019-01-01 00:00:00')
				->isPublishedAt(now: $this->now())
		);

	}//end testAnInvertedWindowPublishesNothing()

	/**
	 * The serialised form carries the window and the computed state, so a
	 * caller never has to re-derive the rule.
	 *
	 * @return void
	 */
	public function testTheSerialisedFormCarriesTheWindow(): void {
		$serialised = $this->file(published: '2020-01-01 00:00:00', depublished: '2030-01-01 00:00:00')
			->jsonSerialize();

		$this->assertSame('2020-01-01T00:00:00+00:00', $serialised['published']);
		$this->assertSame('2030-01-01T00:00:00+00:00', $serialised['depublished']);
		$this->assertTrue($serialised['isPublished']);

	}//end testTheSerialisedFormCarriesTheWindow()

	/**
	 * An unpublished file serialises nulls rather than dates, so "never
	 * published" is representable on the wire.
	 *
	 * @return void
	 */
	public function testAnUnpublishedFileSerialisesNulls(): void {
		$serialised = $this->file(published: null)->jsonSerialize();

		$this->assertNull($serialised['published']);
		$this->assertNull($serialised['depublished']);
		$this->assertFalse($serialised['isPublished']);

	}//end testAnUnpublishedFileSerialisesNulls()
}//end class
