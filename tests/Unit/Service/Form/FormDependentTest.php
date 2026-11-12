<?php

/**
 * A form an owning app announces carries every field it was given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\PublishedForm;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Form\FormDependent
 */
final class FormDependentTest extends TestCase {

	/**
	 * Every announced field reads back as given.
	 *
	 * @return void
	 */
	public function testEveryAnnouncedFieldReadsBack(): void {
		$unpublish = static function (): void {
		};
		$form = new FormDependent(
			form: new PublishedForm(id: 'form-a', app: 'pipelinq', title: 'Contact opnemen', audience: 'public'),
			author: 'alice',
			mapping: ['fields' => [['field' => 'onderwerp', 'property' => 'title']]],
			unpublish: $unpublish
		);

		$this->assertSame('form-a', $form->id);
		$this->assertSame('pipelinq', $form->app);
		$this->assertSame('Contact opnemen', $form->title);
		$this->assertSame('alice', $form->author);
		$this->assertSame(['fields' => [['field' => 'onderwerp', 'property' => 'title']]], $form->mapping);
		$this->assertSame($unpublish, $form->unpublish);
		$this->assertSame('public', $form->audience);
	}//end testEveryAnnouncedFieldReadsBack()

	/**
	 * An internal form without a closure has neither an unpublish nor an audience.
	 *
	 * @return void
	 */
	public function testAnInternalFormWithoutACloserHasNoUnpublishOrAudience(): void {
		$form = new FormDependent(form: new PublishedForm(id: 'form-c', app: 'portaliq', title: 'Melding'), author: null, mapping: []);

		$this->assertNull($form->author);
		$this->assertNull($form->unpublish);
		$this->assertNull($form->audience);
	}//end testAnInternalFormWithoutACloserHasNoUnpublishOrAudience()
}//end class
