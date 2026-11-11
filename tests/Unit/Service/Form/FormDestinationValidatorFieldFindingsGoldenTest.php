<?php

/**
 * Golden test for the per-field findings of FormDestinationValidator.
 *
 * Pins the complete, ordered findings list `validate()` returns for a mapping
 * whose fields hit every per-field branch: unknown property (with and
 * without an empty property name, with and without additionalProperties),
 * read-only, type mismatch (which hides the rest), format mismatch, a free
 * field into an enum, options outside an enum, and every looser constraint.
 * Written ahead of a parameter refactor of those branches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for field findings.
 */
class FormDestinationValidatorFieldFindingsGoldenTest extends TestCase {

	/**
	 * A validator whose translator only substitutes the parameters.
	 *
	 * @return FormDestinationValidator The validator.
	 */
	private function validator(): FormDestinationValidator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new FormDestinationValidator(l10n: $l10n, rules: new FormFieldRules());
	}//end validator()

	/**
	 * The destination schema the golden mapping writes into.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('melding');
		$schema->setRequired([]);
		$schema->setConfiguration($configuration);
		$schema->setProperties(
			[
				'title'    => ['type' => 'string', 'maxLength' => 50, 'minLength' => 2],
				'locked'   => ['type' => 'string', 'readOnly' => true],
				'count'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9, 'enum' => [1, 2]],
				'mail'     => ['type' => 'string', 'format' => 'email'],
				'channel'  => ['type' => 'string', 'enum' => ['web', 'phone']],
				'tags'     => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', ['x' => 1]]]],
				'kind'     => ['type' => ['string', 'null'], 'enum' => ['x', 'y'], 'maxLength' => 3],
				'nullable' => null,
				'loose'    => ['enum' => ['a', ['k' => 1]]],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The mapping that hits every per-field branch, in this order.
	 *
	 * @return array<string, mixed> The mapping.
	 */
	private function mapping(): array {
		return [
			'fields' => [
				['field' => 'f-none', 'type' => 'text'],
				['field' => 'f-unknown', 'property' => 'nope', 'type' => 'text'],
				['field' => 'f-locked', 'property' => 'locked', 'type' => 'text'],
				['field' => 'f-count', 'property' => 'count', 'type' => 'text', 'options' => ['zz']],
				['field' => 'f-mail', 'property' => 'mail', 'type' => 'text', 'maxLength' => 999],
				['field' => 'f-channel', 'property' => 'channel', 'type' => 'text'],
				['field' => 'f-tags', 'property' => 'tags', 'type' => 'choice', 'options' => ['a', ['value' => 'b'], 'c']],
				['field' => 'f-title', 'property' => 'title', 'type' => 'text'],
				['field' => 'f-kind', 'property' => 'kind', 'type' => 'choice', 'options' => ['x', 'q'], 'maxLength' => 10],
				['field' => 'f-nullable', 'property' => 'nullable', 'type' => 'text'],
				['field' => 'f-loose', 'property' => 'loose', 'type' => 'text'],
				['field' => 'f-kind-object', 'property' => 'kind', 'type' => 'choice', 'options' => [['value' => ['n' => 1]]]],
				'not-an-entry',
			],
		];
	}//end mapping()

	/**
	 * Without additionalProperties every field finding is reported, in order.
	 *
	 * @return void
	 */
	public function testFieldFindingsArePinnedWhenExtrasAreRefused(): void {
		$findings = $this->validator()->validate(mapping: $this->mapping(), destination: new FormDestination(schema: $this->schema(configuration: [])));

		$this->assertSame(self::EXPECTED_STRICT, $findings);
	}//end testFieldFindingsArePinnedWhenExtrasAreRefused()

	/**
	 * With additionalProperties an unknown named property is accepted; an unnamed one is not.
	 *
	 * @return void
	 */
	public function testFieldFindingsArePinnedWhenExtrasAreAllowed(): void {
		$findings = $this->validator()->validate(mapping: $this->mapping(), destination: new FormDestination(schema: $this->schema(configuration: ['additionalProperties' => true])));

		$this->assertSame(self::EXPECTED_EXTRAS, $findings);
	}//end testFieldFindingsArePinnedWhenExtrasAreAllowed()

	/**
	 * The findings recorded from the code before the refactor, without additionalProperties.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const EXPECTED_STRICT = [
		[
			'field' => 'f-none',
			'property' => '',
			'code' => 'property-unknown',
			'message' => 'Field "f-none" writes into "", which schema "melding" does not have.',
		],
		[
			'field' => 'f-unknown',
			'property' => 'nope',
			'code' => 'property-unknown',
			'message' => 'Field "f-unknown" writes into "nope", which schema "melding" does not have.',
		],
		[
			'field' => 'f-locked',
			'property' => 'locked',
			'code' => 'property-read-only',
			'message' => 'Field "f-locked" writes into "locked", which is read-only, so the save would refuse it.',
		],
		[
			'field' => 'f-count',
			'property' => 'count',
			'code' => 'type-mismatch',
			'message' => 'Field "f-count" is a text field and cannot produce the integer that "count" holds.',
		],
		[
			'field' => 'f-mail',
			'property' => 'mail',
			'code' => 'format-mismatch',
			'message' => 'Field "f-mail" does not produce the email format that "mail" demands.',
		],
		[
			'field' => 'f-channel',
			'property' => 'channel',
			'code' => 'enum-unconstrained',
			'message' => 'Field "f-channel" lets anything be typed, but "channel" accepts only: web, phone.',
		],
		[
			'field' => 'f-tags',
			'property' => 'tags',
			'code' => 'type-mismatch',
			'message' => 'Field "f-tags" is a choice field and cannot produce the array that "tags" holds.',
		],
		[
			'field' => 'f-title',
			'property' => 'title',
			'code' => 'constraint-looser',
			'message' => 'Field "f-title" allows more than "title" does: set maxLength to 50 or tighter.',
		],
		[
			'field' => 'f-title',
			'property' => 'title',
			'code' => 'constraint-looser',
			'message' => 'Field "f-title" allows more than "title" does: set minLength to 2 or tighter.',
		],
		[
			'field' => 'f-kind',
			'property' => 'kind',
			'code' => 'enum-value-unknown',
			'message' => 'Field "f-kind" offers "q", which "kind" does not accept.',
		],
		[
			'field' => 'f-kind',
			'property' => 'kind',
			'code' => 'constraint-looser',
			'message' => 'Field "f-kind" allows more than "kind" does: set maxLength to 3 or tighter.',
		],
		[
			'field' => 'f-loose',
			'property' => 'loose',
			'code' => 'enum-unconstrained',
			'message' => 'Field "f-loose" lets anything be typed, but "loose" accepts only: a, {"k":1}.',
		],
		[
			'field' => 'f-kind-object',
			'property' => 'kind',
			'code' => 'enum-value-unknown',
			'message' => 'Field "f-kind-object" offers "{"n":1}", which "kind" does not accept.',
		],
		[
			'field' => 'f-kind-object',
			'property' => 'kind',
			'code' => 'constraint-looser',
			'message' => 'Field "f-kind-object" allows more than "kind" does: set maxLength to 3 or tighter.',
		],
	];

	/**
	 * The findings recorded from the code before the refactor, with additionalProperties.
	 *
	 * @var array<int, array<string, string>>
	 */
	private const EXPECTED_EXTRAS = [
		[
			'field' => 'f-none',
			'property' => '',
			'code' => 'property-unknown',
			'message' => 'Field "f-none" writes into "", which schema "melding" does not have.',
		],
		[
			'field' => 'f-locked',
			'property' => 'locked',
			'code' => 'property-read-only',
			'message' => 'Field "f-locked" writes into "locked", which is read-only, so the save would refuse it.',
		],
		[
			'field' => 'f-count',
			'property' => 'count',
			'code' => 'type-mismatch',
			'message' => 'Field "f-count" is a text field and cannot produce the integer that "count" holds.',
		],
		[
			'field' => 'f-mail',
			'property' => 'mail',
			'code' => 'format-mismatch',
			'message' => 'Field "f-mail" does not produce the email format that "mail" demands.',
		],
		[
			'field' => 'f-channel',
			'property' => 'channel',
			'code' => 'enum-unconstrained',
			'message' => 'Field "f-channel" lets anything be typed, but "channel" accepts only: web, phone.',
		],
		[
			'field' => 'f-tags',
			'property' => 'tags',
			'code' => 'type-mismatch',
			'message' => 'Field "f-tags" is a choice field and cannot produce the array that "tags" holds.',
		],
		[
			'field' => 'f-title',
			'property' => 'title',
			'code' => 'constraint-looser',
			'message' => 'Field "f-title" allows more than "title" does: set maxLength to 50 or tighter.',
		],
		[
			'field' => 'f-title',
			'property' => 'title',
			'code' => 'constraint-looser',
			'message' => 'Field "f-title" allows more than "title" does: set minLength to 2 or tighter.',
		],
		[
			'field' => 'f-kind',
			'property' => 'kind',
			'code' => 'enum-value-unknown',
			'message' => 'Field "f-kind" offers "q", which "kind" does not accept.',
		],
		[
			'field' => 'f-kind',
			'property' => 'kind',
			'code' => 'constraint-looser',
			'message' => 'Field "f-kind" allows more than "kind" does: set maxLength to 3 or tighter.',
		],
		[
			'field' => 'f-loose',
			'property' => 'loose',
			'code' => 'enum-unconstrained',
			'message' => 'Field "f-loose" lets anything be typed, but "loose" accepts only: a, {"k":1}.',
		],
		[
			'field' => 'f-kind-object',
			'property' => 'kind',
			'code' => 'enum-value-unknown',
			'message' => 'Field "f-kind-object" offers "{"n":1}", which "kind" does not accept.',
		],
		[
			'field' => 'f-kind-object',
			'property' => 'kind',
			'code' => 'constraint-looser',
			'message' => 'Field "f-kind-object" allows more than "kind" does: set maxLength to 3 or tighter.',
		],
	];
}//end class
