<?php

/**
 * ExternalLinkAnnotationValidatorTest — the typo, caught at the only moment it
 * can be told apart from an empty value.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExternalLink
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExternalLink;

use OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExternalLink\ExternalLinkAnnotationValidator
 */
class ExternalLinkAnnotationValidatorTest extends TestCase {

	/**
	 * Build a schema shape carrying the given declarations.
	 *
	 * @param mixed $declarations The annotation value.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<string, mixed> The shape.
	 */
	private static function shape(mixed $declarations, array $properties = ['bagId' => ['type' => 'string']]): array {
		return [
			'properties' => $properties,
			ExternalLinkResolver::ANNOTATION => $declarations,
		];
	}//end shape()

	public function testASchemaWithNoAnnotationIsValid(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(['properties' => []]);

		$this->assertSame([], $errors);
	}//end testASchemaWithNoAnnotationIsValid()

	public function testAWellFormedDeclarationIsValid(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'BAG', 'url' => 'https://bag.test/{bagId}']])
		);

		$this->assertSame([], $errors);
	}//end testAWellFormedDeclarationIsValid()

	public function testAMisspeltPlaceholderIsRefused(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'BAG', 'url' => 'https://bag.test/{bagID}']])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('{bagID}', $errors[0]);
		$this->assertStringContainsString(
			'hides the link on every object',
			$errors[0],
			'The message has to say why a 200 and no link is the symptom.'
		);
	}//end testAMisspeltPlaceholderIsRefused()

	public function testANestedPathUnderADeclaredPropertyIsAccepted(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape(
				[['title' => 'BAG', 'url' => 'https://bag.test/{adres.bagId}']],
				['adres' => ['type' => 'object']]
			)
		);

		$this->assertSame(
			[],
			$errors,
			'Refusing this would refuse the exact case the feature was asked for: an untyped nested object.'
		);
	}//end testANestedPathUnderADeclaredPropertyIsAccepted()

	public function testASchemaWithNoDeclaredPropertiesContradictsNothing(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'BAG', 'url' => 'https://bag.test/{anything}']], [])
		);

		$this->assertSame([], $errors);
	}//end testASchemaWithNoDeclaredPropertiesContradictsNothing()

	public function testAJavascriptSchemeIsRefused(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'Click', 'url' => 'javascript:alert(1)']])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('javascript', $errors[0]);
	}//end testAJavascriptSchemeIsRefused()

	/**
	 * @dataProvider provideRefusedSchemes
	 */
	public function testOnlyAllowlistedSchemesAreAccepted(string $url, bool $valid): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'X', 'url' => $url]])
		);

		$this->assertSame($valid, ($errors === []), $url);
	}//end testOnlyAllowlistedSchemesAreAccepted()

	public static function provideRefusedSchemes(): array {
		return [
			'https' => ['https://x.test/', true],
			'http' => ['http://x.test/', true],
			'mailto' => ['mailto:info@x.test', true],
			'tel' => ['tel:+31201234567', true],
			'data' => ['data:text/html;base64,PHNjcmlwdD4=', false],
			'vbscript' => ['vbscript:msgbox', false],
			'file' => ['file:///etc/passwd', false],
			'a placeholder as the scheme' => ['{scheme}://x.test/', false],
			'no scheme at all' => ['//x.test/', false],
			'a relative path' => ['/apps/openregister', false],
		];
	}//end provideRefusedSchemes()

	public function testADeclarationWithNoTitleIsRefused(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['url' => 'https://bag.test/']])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('needs a title', $errors[0]);
	}//end testADeclarationWithNoTitleIsRefused()

	public function testADeclarationWithNoUrlIsRefused(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'BAG']])
		);

		$this->assertContains('link 0: needs a url template.', $errors);
	}//end testADeclarationWithNoUrlIsRefused()

	public function testTheAnnotationMustBeAList(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape(['bag' => ['title' => 'BAG', 'url' => 'https://bag.test/']])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('must be a list', $errors[0]);
	}//end testTheAnnotationMustBeAList()

	public function testTooManyLinksAreRefused(): void {
		$declarations = array_fill(
			0,
			(ExternalLinkAnnotationValidator::MAX_LINKS + 1),
			['title' => 'X', 'url' => 'https://x.test/']
		);

		$errors = (new ExternalLinkAnnotationValidator())->validate(self::shape($declarations));

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('resolved on every render', $errors[0]);
	}//end testTooManyLinksAreRefused()

	public function testAConditionMustBeAMap(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'X', 'url' => 'https://x.test/', 'condition' => ['open', 'closed']]])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('map of property path', $errors[0]);
	}//end testAConditionMustBeAMap()

	public function testAConditionNamingAnUndeclaredPropertyIsRefused(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape([['title' => 'X', 'url' => 'https://x.test/', 'condition' => ['staus' => 'open']]])
		);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('staus', $errors[0]);
	}//end testAConditionNamingAnUndeclaredPropertyIsRefused()

	public function testEveryErrorNamesTheLinkItBelongsTo(): void {
		$errors = (new ExternalLinkAnnotationValidator())->validate(
			self::shape(
				[
					['title' => 'BAG', 'url' => 'https://bag.test/{bagId}'],
					['title' => 'GIS', 'url' => 'https://gis.test/{gsiId}'],
				]
			)
		);

		$this->assertCount(1, $errors);
		$this->assertStringStartsWith('link 1: ', $errors[0]);
	}//end testEveryErrorNamesTheLinkItBelongsTo()
}//end class
