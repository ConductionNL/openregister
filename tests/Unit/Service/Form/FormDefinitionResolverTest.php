<?php

/**
 * A form stored in OpenRegister resolves to its destination, mapping and audience, or to nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Published forms resolve; unknown, unpublished and destination-less ones answer the same 404.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDefinitionResolver
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class FormDefinitionResolverTest extends TestCase {

	/**
	 * A resolver over one stored object (or none).
	 *
	 * @param array<string, mixed>|null $body The stored form body.
	 */
	private function resolver(?array $body): FormDefinitionResolver {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use ($body): ?ObjectEntity {
				if ($body === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('form-1');
				$entity->setObject($body);

				return $entity;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FormDefinitionResolver(objects: $objects, l10n: $l10n);
	}//end resolver()

	/**
	 * A published form gives its destination, mapping and audience.
	 */
	public function testAPublishedFormResolves(): void {
		$form = $this->resolver(
			body: [
				'status' => 'published',
				'destination' => ['register' => 'dossiq', 'schema' => 'case'],
				'mapping' => ['fields' => [['field' => 'a', 'property' => 'title']]],
				'audience' => 'authenticated',
			]
		)->resolve(formId: 'form-1');

		$this->assertSame('form-1', $form['id']);
		$this->assertSame([['register' => 'dossiq', 'schema' => 'case', 'mapping' => ['fields' => [['field' => 'a', 'property' => 'title']]], 'as' => 'destination']], $form['writes']);
		$this->assertSame('authenticated', $form['audience']);
	}//end testAPublishedFormResolves()

	/**
	 * A journey step's writes list is taken as is; the audience defaults to public.
	 */
	public function testWritesAreTakenAsIs(): void {
		$writes = [['as' => 'org', 'register' => 'crm', 'schema' => 'organisation'], ['as' => 'contact', 'register' => 'crm', 'schema' => 'contact']];
		$form = $this->resolver(body: ['published' => true, 'writes' => $writes])->resolve(formId: 'form-1');

		$this->assertSame($writes, $form['writes']);
		$this->assertSame('public', $form['audience']);
	}//end testWritesAreTakenAsIs()

	/**
	 * Unknown, unpublished, and destination-less forms all answer one identical 404.
	 */
	public function testEverythingElseIsTheSame404(): void {
		$messages = [];
		foreach ([null, ['status' => 'draft', 'destination' => ['register' => 'r', 'schema' => 's']], ['status' => 'published']] as $body) {
			try {
				$this->resolver(body: $body)->resolve(formId: 'form-1');
				$this->fail('A form that should not resolve did.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(404, $refused->getStatus());
				$messages[] = $refused->toBody();
			}
		}

		$this->assertCount(1, array_unique(array_map('serialize', $messages)));
	}//end testEverythingElseIsTheSame404()
}//end class
