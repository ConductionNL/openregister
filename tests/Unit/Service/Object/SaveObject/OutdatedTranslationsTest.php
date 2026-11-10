<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectUpdateRun;
use OCA\OpenRegister\Service\Object\SaveObject\OutdatedTranslations;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins when an update flips derived translations to outdated.
 */
class OutdatedTranslationsTest extends TestCase {

	private TranslationHandler&MockObject $translations;

	private TranslationProjectionService&MockObject $projection;

	private TranslationStatusService&MockObject $status;

	private LoggerInterface&MockObject $logger;

	/** @var list<array{string, string, string}> */
	private array $marked = [];

	protected function setUp(): void {
		parent::setUp();
		$this->translations = $this->createMock(TranslationHandler::class);
		$this->translations->method('getTranslatableProperties')->willReturn(['title']);
		$this->projection = $this->createMock(TranslationProjectionService::class);
		$this->projection->method('resolveSourceLanguage')->willReturn('nl');
		$this->status = $this->createMock(TranslationStatusService::class);
		$this->status->method('markDerivedTranslationsOutdated')->willReturnCallback(
			function (TranslationSlot $source): int {
				$this->marked[] = [$source->objectUuid, $source->property, $source->language];
				return 1;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Which values count as a source change.
	 *
	 * @return array<string, array{mixed, mixed, bool}>
	 */
	public static function changes(): array {
		return [
			'source language changed' => [['nl' => 'oud', 'en' => 'old'], ['nl' => 'nieuw', 'en' => 'old'], true],
			'other language changed' => [['nl' => 'oud', 'en' => 'old'], ['nl' => 'oud', 'en' => 'new'], false],
			'plain string changed' => ['oud', 'nieuw', true],
			'plain string against a keyed value' => ['oud', ['nl' => 'oud'], false],
			'numeric entry changed' => [['nl' => 1], ['nl' => 2], true],
			'boolean entry to its string' => [['nl' => true], ['nl' => '1'], false],
			'no old value' => [null, 'nieuw', false],
			'no new value' => ['oud', null, false],
			'source language absent' => [['en' => 'old'], ['en' => 'new'], false],
			'array entry' => [['nl' => ['a']], ['nl' => ['b']], false],
			'number as a body' => [1, 2, false],
		];
	}

	/**
	 * @dataProvider changes
	 *
	 * @param mixed $old     The old property value.
	 * @param mixed $new     The new property value.
	 * @param bool  $flagged Whether the derived translations are marked.
	 *
	 * @return void
	 */
	public function testFlagsOnlyASourceChange(mixed $old, mixed $new, bool $flagged): void {
		$this->flag(old: ['title' => $old], new: ['title' => $new], uuid: 'u-1');

		$this->assertSame($flagged ? [['u-1', 'title', 'nl']] : [], $this->marked);
	}

	/**
	 * An object without a uuid has no translation rows to mark.
	 *
	 * @return void
	 */
	public function testSkipsAnObjectWithoutUuid(): void {
		$this->translations->expects($this->never())->method('getTranslatableProperties');

		$this->flag(old: ['title' => 'oud'], new: ['title' => 'nieuw'], uuid: '');

		$this->assertSame([], $this->marked);
	}

	/**
	 * A failure is logged and does not reach the save.
	 *
	 * @return void
	 */
	public function testLogsAFailureWithoutThrowing(): void {
		$projection = $this->createMock(TranslationProjectionService::class);
		$projection->method('resolveSourceLanguage')->willThrowException(new RuntimeException('no language'));
		$this->projection = $projection;
		$this->logger->expects($this->once())->method('warning')->with('[SaveObject] failed to flag outdated translations: no language');

		$this->flag(old: ['title' => 'oud'], new: ['title' => 'nieuw'], uuid: 'u-1');

		$this->assertSame([], $this->marked);
	}

	/**
	 * Run the check over an update from one state to another.
	 *
	 * @param array  $old  The old object data.
	 * @param array  $new  The new object data.
	 * @param string $uuid The object's uuid.
	 *
	 * @return void
	 */
	private function flag(array $old, array $new, string $uuid): void {
		$oldObject = new ObjectEntity();
		$oldObject->setObject($old);
		$updated = new ObjectEntity();
		$updated->setUuid($uuid);
		$updated->setObject($new);
		$run = new ObjectUpdateRun();
		$run->schema = new Schema();
		$run->register = new Register();
		$run->oldObject = $oldObject;

		$handler = new OutdatedTranslations(translations: $this->translations, projection: $this->projection, status: $this->status, logger: $this->logger);
		$handler->flag(run: $run, updated: $updated);
	}
}
