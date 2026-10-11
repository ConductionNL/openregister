<?php

/**
 * Upload tokens hold bytes only, are checked against the property's file rules, and expire.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Issue, refuse, materialise, claim and purge.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormUploadStore
 * @uses \OCA\OpenRegister\Service\File\FilePropertyRules
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 */
class FormUploadStoreTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private FormUploadStore $store;

	/**
	 * @var array<int, string>
	 */
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

		$this->store = new FormUploadStore(appData: $this->appData, time: $time, temp: $temp, rules: new FilePropertyRules(), l10n: $l10n);
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

	/**
	 * A file within the rules gets a token that expires in 24 hours.
	 */
	public function testAFileWithinTheRulesGetsAToken(): void {
		$issued = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file', 'maxSize' => 1024], file: $this->upload('%PDF-1.7'));

		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $issued['token']);
		$this->assertSame($this->now + 86_400, $issued['expiresAt']);
	}//end testAFileWithinTheRulesGetsAToken()

	/**
	 * Spec scenario: an oversized file is refused at upload and no token is issued.
	 */
	public function testAnOversizedFileIsRefused(): void {
		$rule = ['type' => 'file', 'fileConfiguration' => ['maxSize' => 10]];

		try {
			$this->store->issue(formId: 'form-1', property: 'bijlage', rule: $rule, file: $this->upload('x', size: 12 * 1024 * 1024));
			$this->fail('An oversized file was given a token.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(422, $refused->getStatus());
			$this->assertSame('file-too-large', $refused->getFindings()[0]['code']);
		}

		$this->assertSame([], ($this->appData->folders[FormUploadStore::FOLDER] ?? null)?->files ?? []);
	}//end testAnOversizedFileIsRefused()

	/**
	 * A type the property does not allow is refused; an items rule of an array property counts.
	 */
	public function testADisallowedTypeIsRefused(): void {
		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(
			formId: 'form-1',
			property: 'bijlagen',
			rule: ['type' => 'array', 'items' => ['type' => 'file', 'allowedTypes' => ['image/png']]],
			file: $this->upload('%PDF')
		);
	}//end testADisallowedTypeIsRefused()

	/**
	 * A property that holds no files takes no upload.
	 */
	public function testAPropertyThatHoldsNoFilesTakesNoUpload(): void {
		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(formId: 'form-1', property: 'title', rule: ['type' => 'string'], file: $this->upload('x'));
	}//end testAPropertyThatHoldsNoFilesTakesNoUpload()

	/**
	 * A failed PHP upload is refused.
	 */
	public function testAFailedUploadIsRefused(): void {
		$file = $this->upload('x');
		$file['error'] = UPLOAD_ERR_PARTIAL;

		$this->expectException(FormSubmitRefusedException::class);
		$this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $file);
	}//end testAFailedUploadIsRefused()

	/**
	 * A token becomes an upload array the save path reads, for its own form only.
	 */
	public function testATokenMaterialisesForItsOwnFormOnly(): void {
		$issued = $this->store->issue(formId: 'form-1', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('%PDF-1.7 bytes'));

		$file = $this->store->materialise(formId: 'form-1', token: $issued['token']);
		$this->assertSame('bijlage', $file['property']);
		$this->assertSame('bewijs.pdf', $file['name']);
		$this->assertSame('%PDF-1.7 bytes', file_get_contents($file['tmp_name']));
		$this->assertSame(UPLOAD_ERR_OK, $file['error']);

		$this->expectException(FormSubmitRefusedException::class);
		$this->store->materialise(formId: 'form-2', token: $issued['token']);
	}//end testATokenMaterialisesForItsOwnFormOnly()

	/**
	 * A claimed token is gone; an unknown or expired one is refused.
	 */
	public function testAClaimedOrExpiredTokenIsRefused(): void {
		$claimed = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('a'))['token'];
		$this->store->claim(tokens: [$claimed]);

		$expired = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('b'))['token'];
		$this->now += 86_401;

		foreach ([$claimed, $expired, 'not-a-token'] as $token) {
			try {
				$this->store->materialise(formId: 'f', token: $token);
				$this->fail('Token ' . $token . ' materialised.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame('upload-token-unknown', $refused->getFindings()[0]['code']);
			}
		}
	}//end testAClaimedOrExpiredTokenIsRefused()

	/**
	 * The purge deletes unclaimed expired tokens, bytes and record, and counts tokens.
	 */
	public function testThePurgeCountsExpiredTokens(): void {
		$this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('a'));
		$this->now += 50_000;
		$kept = $this->store->issue(formId: 'f', property: 'bijlage', rule: ['type' => 'file'], file: $this->upload('b'))['token'];
		$this->now += 40_000;

		$this->assertSame(1, $this->store->purge());
		$this->assertCount(2, $this->appData->folders[FormUploadStore::FOLDER]->files);
		$this->assertSame('b', file_get_contents($this->store->materialise(formId: 'f', token: $kept)['tmp_name']));
		$this->assertSame(0, $this->store->purge());
	}//end testThePurgeCountsExpiredTokens()
}//end class
