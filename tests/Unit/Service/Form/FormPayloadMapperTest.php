<?php

/**
 * A payload becomes the object a write stores, and its upload tokens are redeemed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormPayloadMapper;
use OCA\OpenRegister\Service\Form\FormUploadClaim;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use PHPUnit\Framework\TestCase;

/**
 * The object, files and tokens a payload maps to.
 */
class FormPayloadMapperTest extends TestCase {

	public function testWithoutAMappingThePayloadIsTheObjectMinusControlKeysAndIds(): void {
		$uploads = $this->createMock(FormUploadStore::class);
		$uploads->expects($this->never())->method('redeem');
		$payload = ['name' => 'Ada', '_draft' => true, '@self' => ['id' => 'x'], 'id' => 'x', 'uuid' => 'y'];

		$mapped = (new FormPayloadMapper(uploads: $uploads))->mapPayload(null, new FormSubmission(writes: [], payload: $payload));

		$this->assertSame([['name' => 'Ada'], [], []], $mapped);
	}//end testWithoutAMappingThePayloadIsTheObjectMinusControlKeysAndIds()

	public function testAMappingTakesOnlyMappedFieldsAddsFixedValuesAndDropsAnId(): void {
		$uploads = $this->createMock(FormUploadStore::class);
		$mapping = [
			'fields' => [['field' => 'fullName', 'property' => 'name'], ['field' => 'absent', 'property' => 'gone']],
			'fixed'  => ['kind' => 'request', 'id' => 'chosen'],
		];
		$submission = new FormSubmission(writes: [], payload: ['fullName' => 'Ada', 'other' => 1]);

		$mapped = (new FormPayloadMapper(uploads: $uploads))->mapPayload($mapping, $submission);

		$this->assertSame([['name' => 'Ada', 'kind' => 'request'], [], []], $mapped);
	}//end testAMappingTakesOnlyMappedFieldsAddsFixedValuesAndDropsAnId()

	public function testAnUploadTokenIsRedeemedForTheFormAndDraft(): void {
		$file    = ['name' => 'a.pdf'];
		$uploads = $this->createMock(FormUploadStore::class);
		$uploads->expects($this->once())->method('redeem')->with(new FormUploadClaim(formId: 'form-1', property: 'attachment', draftId: 'd-1'), 'tok-1')->willReturn($file);
		$payload    = ['attachment' => ['uploadToken' => 'tok-1'], 'name' => 'Ada'];
		$submitter  = new FormSubmitter(scope: 'form-1', draftId: 'd-1');
		$submission = new FormSubmission(writes: [], payload: $payload, submitter: $submitter);

		$mapped = (new FormPayloadMapper(uploads: $uploads))->mapPayload(null, $submission);

		$this->assertSame([['name' => 'Ada'], ['attachment' => $file], ['tok-1']], $mapped);
	}//end testAnUploadTokenIsRedeemedForTheFormAndDraft()

	public function testATokenTheDraftAlreadyHoldsLeavesThePropertyAsStored(): void {
		$uploads = $this->createMock(FormUploadStore::class);
		$uploads->method('redeem')->willReturn(null);
		$submission = new FormSubmission(writes: [], payload: ['attachment' => ['uploadToken' => 'tok-1']]);

		$mapped = (new FormPayloadMapper(uploads: $uploads))->mapPayload(null, $submission);

		$this->assertSame([[], [], []], $mapped);
	}//end testATokenTheDraftAlreadyHoldsLeavesThePropertyAsStored()
}//end class
