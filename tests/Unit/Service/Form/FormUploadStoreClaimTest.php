<?php

/**
 * Characterisation tests for FormUploadStore::claim().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormFileStorage;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Pins claim(): no upload folder yet, a final claim, a draft claim of a live
 * token (which leaves a record the purge later counts) and a draft claim of an
 * unknown token (which leaves none). The clock is the injected time factory.
 */
class FormUploadStoreClaimTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private FormUploadStore $store;

	private array $temporary = [];

	protected function setUp(): void {
		$this->appData = new MemoryAppData();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'or-form-test');
				$this->temporary[] = $path;

				return $path;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);

		$this->store = new FormUploadStore(storage: new FormFileStorage(appData: $this->appData, time: $time), temp: $temp, rules: new FilePropertyRules(), l10n: $l10n);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * A PHP upload array for some bytes.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	private function upload(string $bytes, string $type = 'application/pdf', int $size = -1): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-form-upload');
		file_put_contents($path, $bytes);
		$this->temporary[] = $path;

		return ['name' => 'bewijs.pdf', 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => $size < 0 ? strlen($bytes) : $size];
	}//end upload()

	private function liveToken(): string {
		return $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('%PDF-1.7'))['token'];
	}

	public function testAClaimBeforeAnyUploadDoesNothing(): void {
		$this->store->claim(tokens: [str_repeat('a', 32)], draftId: 'draft-1');

		$this->now += 86_401;
		$this->assertSame(0, $this->store->purge());
	}

	public function testAFinalClaimDeletesTheTokenAndRecordsNothing(): void {
		$token = $this->liveToken();
		$this->store->claim(tokens: [$token]);

		$this->now += 86_401;
		$this->assertSame(0, $this->store->purge(), 'The bytes are gone and no record was left.');
	}

	public function testADraftClaimRecordsEachLiveTokenButNotAnUnknownOne(): void {
		$one = $this->liveToken();
		$two = $this->liveToken();
		$this->store->claim(tokens: [$one, str_repeat('b', 32), 'not-a-token', $two], draftId: 'draft-1');

		$this->now += 86_401;
		$this->assertSame(2, $this->store->purge(), 'One record per live token, none for the unknown ones.');
		$this->assertSame(0, $this->store->purge());
	}
}//end class
