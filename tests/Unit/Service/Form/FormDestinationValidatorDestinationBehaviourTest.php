<?php

/**
 * What a form mapping is checked for beyond its fields: a staging or closed
 * destination, fixed values, required properties nothing fills, the reasons a
 * property cannot be rendered, and the dialect markers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\FormDestinationValidator;
use OCA\OpenRegister\Service\Form\FormFieldRules;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FormDestinationValidator: destination, fixed-value and required findings.
 */
class FormDestinationValidatorDestinationBehaviourTest extends TestCase {

	private FormDestinationValidator $validator;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$this->validator = new FormDestinationValidator($l10n, new FormFieldRules());
	}//end setUp()

	/**
	 * @param array<string, mixed>      $properties    The schema properties.
	 * @param list<string>              $required      The required list.
	 * @param array<string, mixed>|null $configuration The schema configuration.
	 * @param array<string, mixed>|null $authorization The schema authorization.
	 */
	private function schema(array $properties, array $required = [], ?array $configuration = null, ?array $authorization = null): Schema {
		$schema = new Schema();
		$schema->setSlug('ticket');
		$schema->setProperties($properties);
		$schema->setRequired($required);
		$schema->setConfiguration($configuration);
		$schema->setAuthorization($authorization);
		return $schema;
	}//end schema()

	/**
	 * @param list<array<string, mixed>> $findings
	 *
	 * @return list<string>
	 */
	private static function codes(array $findings): array {
		return array_column($findings, 'code');
	}//end codes()

	public function testAStagingDestinationIsReportedWithoutAProperty(): void {
		$findings = $this->validator->validate([], $this->schema([], [], ['staging' => true]));

		$this->assertSame(
			[
				[
					'property' => '',
					'code' => 'destination-is-staging',
					'message' => 'Schema "ticket" is marked as a staging object. A form submits into the object a person works on, not into a layer before it.',
				],
			],
			$findings
		);
	}//end testAStagingDestinationIsReportedWithoutAProperty()

	public function testAStagingFlagThatIsNotStrictlyTrueIsIgnored(): void {
		$this->assertSame([], $this->validator->validate([], $this->schema([], [], ['staging' => 'yes'])));
	}//end testAStagingFlagThatIsNotStrictlyTrueIsIgnored()

	/**
	 * @return array<string, array{array<string, mixed>|null, string, bool}>
	 */
	public static function audiences(): array {
		return [
			'no authorization is open to all' => [null, 'public', true],
			'public create admits a public form' => [['create' => ['public']], 'public', true],
			'authenticated create refuses a public form' => [['create' => ['authenticated']], 'public', false],
			'authenticated create admits an authenticated form' => [['create' => ['authenticated']], 'authenticated', true],
			'public create admits an authenticated form' => [['create' => ['public']], 'authenticated', true],
			'a group entry object is read by its group' => [['create' => [['group' => 'public']]], 'public', true],
			'a non-string group admits nobody' => [['create' => [5, ['group' => 7]]], 'public', false],
			'no create rule refuses' => [['read' => ['public']], 'public', false],
		];
	}//end audiences()

	/**
	 * @param array<string, mixed>|null $authorization The schema authorization.
	 * @param string                    $audience      The form audience.
	 * @param bool                      $open          Whether the destination admits the audience.
	 */
	#[DataProvider('audiences')]
	public function testTheAudienceMustBeAllowedToCreate(?array $authorization, string $audience, bool $open): void {
		$findings = $this->validator->validate([], $this->schema([], [], null, $authorization), ['audience' => $audience]);

		if ($open === true) {
			$this->assertSame([], $findings);
			return;
		}

		$this->assertSame(['destination-not-public'], self::codes($findings));
		$this->assertSame(
			sprintf('Schema "ticket" does not let a %s visitor create objects, so this form could never be submitted.', $audience),
			$findings[0]['message']
		);
	}//end testTheAudienceMustBeAllowedToCreate()

	public function testAnInternalFormSkipsTheAudienceCheck(): void {
		$this->assertSame([], $this->validator->validate([], $this->schema([], [], null, ['create' => ['admin']])));
	}//end testAnInternalFormSkipsTheAudienceCheck()

	public function testAFixedValueIntoAnUnknownPropertyIsReportedUnlessExtrasAreAllowed(): void {
		$mapping = ['fixed' => ['channel' => 'web']];

		$closed = $this->validator->validate($mapping, $this->schema(['title' => ['type' => 'string']]));
		$open = $this->validator->validate($mapping, $this->schema(['title' => ['type' => 'string']], [], ['additionalProperties' => true]));

		$this->assertSame(
			[['property' => 'channel', 'code' => 'property-unknown', 'message' => 'A fixed value writes into "channel", which schema "ticket" does not have.']],
			$closed
		);
		$this->assertSame([], $open);
	}//end testAFixedValueIntoAnUnknownPropertyIsReportedUnlessExtrasAreAllowed()

	public function testAFixedValueThePropertyRefusesIsReportedAndAFittingOneIsNot(): void {
		$schema = $this->schema(['channel' => ['type' => 'string', 'enum' => ['web', 'phone']], 'count' => ['type' => 'integer']]);

		$bad = $this->validator->validate(['fixed' => ['channel' => 'fax', 'count' => 'many']], $schema);
		$good = $this->validator->validate(['fixed' => ['channel' => 'web', 'count' => 3]], $schema);

		$this->assertSame(['fixed-value-invalid', 'fixed-value-invalid'], self::codes($bad));
		$this->assertSame(['channel', 'count'], array_column($bad, 'property'));
		$this->assertSame('The fixed value for "channel" is one the property does not accept.', $bad[0]['message']);
		$this->assertSame([], $good);
	}//end testAFixedValueThePropertyRefusesIsReportedAndAFittingOneIsNot()

	public function testAFixedBlockThatIsNotAnArrayIsIgnored(): void {
		$this->assertSame([], $this->validator->validate(['fixed' => 'channel=web'], $this->schema([])));
	}//end testAFixedBlockThatIsNotAnArrayIsIgnored()

	public function testARequiredPropertyNothingFillsIsReported(): void {
		$schema = $this->schema(
			[
				'title' => ['type' => 'string'],
				'channel' => ['type' => 'string'],
				'status' => ['type' => 'string', 'default' => 'new'],
				'score' => ['type' => 'number', 'computed' => 'x'],
				'number' => ['type' => 'string', 'x-openregister-generated' => ['pattern' => 'Z-{n}']],
				'owner' => ['type' => 'string', 'x-openregister' => ['serverSet' => true]],
				'notes' => ['type' => 'string', 'required' => true],
				'flagged' => ['type' => 'string', 'x-openregister' => ['serverSet' => 'yes'], 'required' => true],
			],
			['title', 'channel', 'status', 'score', 'number', 'owner', 'title']
		);
		$mapping = ['fields' => [['field' => 'subject', 'type' => 'text', 'property' => 'title'], 'not-a-field'], 'fixed' => ['channel' => 'web']];

		$findings = $this->validator->validate($mapping, $schema);

		$this->assertSame(['notes', 'flagged'], array_column($findings, 'property'));
		$this->assertSame(['required-unmapped', 'required-unmapped'], self::codes($findings));
		$this->assertSame('"notes" is required, and no field, fixed value or server default fills it.', $findings[0]['message']);
	}//end testARequiredPropertyNothingFillsIsReported()

	public function testUnrenderableReasonNamesWhyAPropertyCannotBeShown(): void {
		$schema = $this->schema(
			[
				'title' => ['type' => 'string'],
				'locked' => ['type' => 'string', 'readOnly' => true],
				'hidden' => ['type' => 'string', 'visible' => false],
				'soft' => ['type' => 'string', 'readOnly' => 'yes', 'visible' => 0],
			]
		);

		$this->assertSame('the schema has no such property.', $this->validator->unrenderableReason($schema, 'missing'));
		$this->assertSame('the schema marks it read-only, so a submitted value would be refused.', $this->validator->unrenderableReason($schema, 'locked'));
		$this->assertSame('the schema marks it not visible, so no form can show it.', $this->validator->unrenderableReason($schema, 'hidden'));
		$this->assertNull($this->validator->unrenderableReason($schema, 'title'));
		$this->assertNull($this->validator->unrenderableReason($schema, 'soft'));
	}//end testUnrenderableReasonNamesWhyAPropertyCannotBeShown()

	public function testMarkersAreReadOnlyWhenStrictlyTrueInsideTheDialectBlock(): void {
		$this->assertTrue(FormDestinationValidator::marker(['x-openregister' => ['reference' => true]], 'reference'));
		$this->assertFalse(FormDestinationValidator::marker(['x-openregister' => ['reference' => 'true']], 'reference'));
		$this->assertFalse(FormDestinationValidator::marker(['x-openregister' => ['confirmation' => true]], 'reference'));
		$this->assertFalse(FormDestinationValidator::marker(['x-openregister' => 'reference'], 'reference'));
		$this->assertFalse(FormDestinationValidator::marker(['reference' => true], 'reference'));
	}//end testMarkersAreReadOnlyWhenStrictlyTrueInsideTheDialectBlock()

	public function testMarkedPropertiesListsEachMarkedPropertyInOrder(): void {
		$schema = $this->schema(
			[
				'ref' => ['x-openregister' => ['reference' => true]],
				'plain' => ['type' => 'string'],
				'odd' => 'not-a-definition',
				'ref2' => ['x-openregister' => ['reference' => true, 'confirmation' => true]],
			]
		);

		$this->assertSame(['ref', 'ref2'], FormDestinationValidator::markedProperties($schema, 'reference'));
		$this->assertSame(['ref2'], FormDestinationValidator::markedProperties($schema, 'confirmation'));
		$this->assertSame([], FormDestinationValidator::markedProperties($schema, 'serverSet'));
	}//end testMarkedPropertiesListsEachMarkedPropertyInOrder()

	public function testEachMappedFieldIsJudgedAgainstItsProperty(): void {
		$schema = $this->schema(
			[
				'title' => ['type' => 'string', 'maxLength' => 50],
				'locked' => ['type' => 'string', 'readOnly' => true],
				'count' => ['type' => 'integer'],
				'mail' => ['type' => 'string', 'format' => 'email'],
				'channel' => ['type' => 'string', 'enum' => ['web', 'phone']],
				'kind' => ['type' => ['string', 'null'], 'enum' => ['x', ['n' => 1]]],
			]
		);
		$fields = [
			['field' => 'f-none', 'type' => 'text'],
			['field' => 'f-unknown', 'property' => 'nope', 'type' => 'text'],
			['field' => 'f-locked', 'property' => 'locked', 'type' => 'text'],
			['field' => 'f-count', 'property' => 'count', 'type' => 'text'],
			['field' => 'f-mail', 'property' => 'mail', 'type' => 'text'],
			['field' => 'f-mail-ok', 'property' => 'mail', 'type' => 'email'],
			['field' => 'f-channel', 'property' => 'channel', 'type' => 'text'],
			['field' => 'f-choice', 'property' => 'channel', 'type' => 'choice', 'options' => ['web', 'fax', ['value' => 'phone']]],
			['field' => 'f-loose', 'property' => 'title', 'type' => 'text', 'maxLength' => 99],
			['field' => 'f-tight', 'property' => 'title', 'type' => 'text', 'maxLength' => 20],
			['field' => 'f-kind', 'property' => 'kind', 'type' => 'text'],
		];

		$findings = $this->validator->validate(['fields' => $fields], $schema);

		$this->assertSame(
			[
				['f-none', '', 'property-unknown', 'Field "f-none" writes into "", which schema "ticket" does not have.'],
				['f-unknown', 'nope', 'property-unknown', 'Field "f-unknown" writes into "nope", which schema "ticket" does not have.'],
				['f-locked', 'locked', 'property-read-only', 'Field "f-locked" writes into "locked", which is read-only, so the save would refuse it.'],
				['f-count', 'count', 'type-mismatch', 'Field "f-count" is a text field and cannot produce the integer that "count" holds.'],
				['f-mail', 'mail', 'format-mismatch', 'Field "f-mail" does not produce the email format that "mail" demands.'],
				['f-channel', 'channel', 'enum-unconstrained', 'Field "f-channel" lets anything be typed, but "channel" accepts only: web, phone.'],
				['f-choice', 'channel', 'enum-value-unknown', 'Field "f-choice" offers "fax", which "channel" does not accept.'],
				['f-loose', 'title', 'constraint-looser', 'Field "f-loose" allows more than "title" does: set maxLength to 50 or tighter.'],
				['f-kind', 'kind', 'enum-unconstrained', 'Field "f-kind" lets anything be typed, but "kind" accepts only: x, {"n":1}.'],
			],
			array_map(static fn (array $f): array => [$f['field'], $f['property'], $f['code'], $f['message']], $findings)
		);
	}//end testEachMappedFieldIsJudgedAgainstItsProperty()

	public function testWithExtrasAllowedOnlyAFieldWithoutAPropertyIsUnknown(): void {
		$schema = $this->schema(['title' => ['type' => 'string']], [], ['additionalProperties' => true]);
		$fields = [['field' => 'f-none', 'type' => 'text'], ['field' => 'f-extra', 'property' => 'nope', 'type' => 'text']];

		$findings = $this->validator->validate(['fields' => $fields], $schema);

		$this->assertSame(['f-none'], array_column($findings, 'field'));
		$this->assertSame(['property-unknown'], self::codes($findings));
	}//end testWithExtrasAllowedOnlyAFieldWithoutAPropertyIsUnknown()
}//end class
