<?php

/**
 * DeclarationValidatorTest — the shape rules, the narrowing test and the digest of
 * an app-declared credential provider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\DeclarationValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\DeclarationValidator
 */
class DeclarationValidatorTest extends TestCase {

	private DeclarationValidator $validator;

	/**
	 * A reviewed catalogue with one proxy entry and one inject-only entry.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $catalogue = [
		'github' => [
			'identifier' => 'github',
			'title' => 'GitHub',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [
				['method' => 'GET', 'pathPattern' => '/repos/*'],
				['method' => 'PUT', 'pathPattern' => '/repos/*/contents/*'],
				['method' => 'GET', 'pathPattern' => '/user/repos'],
			],
		],
		'generic-bearer' => ['identifier' => 'generic-bearer', 'title' => 'Bearer', 'inject_only' => true],
	];

	protected function setUp(): void {
		$this->validator = new DeclarationValidator();
	}

	/**
	 * A sound novel declaration of app `alpha`.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function novel(array $overrides = []): array {
		return array_merge(
			[
				'identifier' => 'alpha:codeberg',
				'title' => 'Codeberg',
				'baseUrl' => 'https://codeberg.org/api/v1',
				'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
				'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
			],
			$overrides
		);
	}

	/**
	 * A declaration of app `alpha` extending github with the given rules.
	 *
	 * @param array<int, array<string, string>> $rules The declared rules.
	 *
	 * @return array<string, mixed>
	 */
	private function extendsGithub(array $rules): array {
		return [
			'identifier' => 'alpha:github-read',
			'title' => 'GitHub, read only',
			'extends' => 'github',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => $rules,
		];
	}

	private function check(array $entry, ?string $key = null): array {
		return $this->validator->validate(appId: 'alpha', key: ($key ?? (string)$entry['identifier']), entry: $entry, catalogue: $this->catalogue);
	}

	public function testANovelDeclarationIsValidAndNeedsApproval(): void {
		$this->assertSame(['valid' => true, 'reason' => '', 'lane' => DeclarationValidator::LANE_APPROVAL], $this->check($this->novel()));
	}

	public function testADocumentationNoteIsAllowedOnTheEntryAndItsRules(): void {
		$entry = $this->novel(['$why' => 'Our forge.', 'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*', '$why' => 'Read.']]]);

		$this->assertTrue($this->check($entry)['valid']);
	}

	/**
	 * @dataProvider unsoundEntries
	 */
	public function testAnUnsoundEntryIsRefusedWithItsReason(array $entry, string $reason): void {
		$outcome = $this->check($entry);

		$this->assertFalse($outcome['valid']);
		$this->assertSame('', $outcome['lane']);
		$this->assertSame($reason, $outcome['reason']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unsoundEntries(): array {
		$base = [
			'identifier' => 'alpha:codeberg',
			'title' => 'Codeberg',
			'baseUrl' => 'https://codeberg.org/api/v1',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
		];

		return [
			'inject_only' => [array_merge($base, ['inject_only' => true]), 'a declared provider cannot be inject_only'],
			'inject_only false is still an unknown field' => [array_merge($base, ['inject_only' => false]), 'unknown field "inject_only"'],
			'an extra field' => [array_merge($base, ['kind' => 'oauth2-token-set']), 'unknown field "kind"'],
			'no baseUrl' => [array_diff_key($base, ['baseUrl' => true]), 'the entry has no https baseUrl'],
			'a plain-http baseUrl' => [array_merge($base, ['baseUrl' => 'http://codeberg.org']), 'the entry has no https baseUrl'],
			'a baseUrl with credentials' => [array_merge($base, ['baseUrl' => 'https://u:p@codeberg.org']), 'the entry has no https baseUrl'],
			'a baseUrl with a query' => [array_merge($base, ['baseUrl' => 'https://codeberg.org/api?x=1']), 'the entry has no https baseUrl'],
			'empty allowRules' => [array_merge($base, ['allowRules' => []]), 'the entry has no allowRules'],
			'a rule with an extra field' => [array_merge($base, ['allowRules' => [['method' => 'GET', 'pathPattern' => '/x', 'host' => 'evil']]]), 'an allow-rule may carry only a method and a pathPattern'],
			'a lower-case method' => [array_merge($base, ['allowRules' => [['method' => 'get', 'pathPattern' => '/x']]]), 'an allow-rule must name an upper-case method'],
			'an unrooted pattern' => [array_merge($base, ['allowRules' => [['method' => 'GET', 'pathPattern' => 'repos/*']]]), 'an allow-rule must carry a /-rooted pathPattern'],
			'an auth scheme without {secret}' => [array_merge($base, ['authScheme' => ['header' => 'Authorization', 'template' => 'token x']]), 'the authScheme must name a header and a template carrying {secret}'],
			'an auth scheme with an extra field' => [array_merge($base, ['authScheme' => ['header' => 'Authorization', 'template' => '{secret}', 'query' => 'k']]), 'the authScheme must carry only a header and a template'],
			'no title' => [array_merge($base, ['title' => ' ']), 'the entry has no title'],
		];
	}

	public function testAnUnnamespacedIdentifierCannotTakeAReviewedName(): void {
		$outcome = $this->check($this->novel(['identifier' => 'github']), 'github');

		$this->assertFalse($outcome['valid']);
		$this->assertSame('the identifier must be namespaced as "alpha:<localId>"', $outcome['reason']);
	}

	public function testAnAppCannotDeclareUnderAnotherAppsNamespace(): void {
		$outcome = $this->check($this->novel(['identifier' => 'beta:codeberg']), 'beta:codeberg');

		$this->assertFalse($outcome['valid']);
		$this->assertSame('the identifier must be namespaced as "alpha:<localId>"', $outcome['reason']);
	}

	public function testTheIdentifierMustEqualItsKeyAndBeAPlainLocalId(): void {
		$this->assertSame('the identifier must equal its key', $this->check($this->novel(), 'alpha:other')['reason']);
		$this->assertSame(
			'the local id must be lower-case letters, digits, dots, dashes or underscores',
			$this->check($this->novel(['identifier' => 'alpha:Code Berg']))['reason']
		);
		$this->assertSame('the entry is not an object', $this->validator->validate(appId: 'alpha', key: 'alpha:x', entry: 'x', catalogue: [])['reason']);
	}

	public function testAStrictSubsetOfTheBaseIsANarrowingDeclaration(): void {
		$outcome = $this->check($this->extendsGithub([
			['method' => 'GET', 'pathPattern' => '/repos/*'],
			['method' => 'PUT', 'pathPattern' => '/repos/conduction/*/contents/docs/*'],
		]));

		$this->assertSame(['valid' => true, 'reason' => '', 'lane' => DeclarationValidator::LANE_NARROWING], $outcome);
	}

	public function testAWiderPathUnderExtendsIsRejectedNotEscalated(): void {
		$outcome = $this->check($this->extendsGithub([['method' => 'PUT', 'pathPattern' => '/repos/*']]));

		$this->assertFalse($outcome['valid']);
		$this->assertSame('', $outcome['lane']);
		$this->assertSame('the rule PUT /repos/* is not narrower than the base', $outcome['reason']);
	}

	public function testAnotherMethodUnderExtendsIsRejected(): void {
		$outcome = $this->check($this->extendsGithub([['method' => 'DELETE', 'pathPattern' => '/repos/*']]));

		$this->assertFalse($outcome['valid']);
		$this->assertSame('the rule DELETE /repos/* is not narrower than the base', $outcome['reason']);
	}

	public function testAnotherHostOrAuthSchemeUnderExtendsIsRejected(): void {
		$wider = $this->extendsGithub([['method' => 'GET', 'pathPattern' => '/repos/*']]);
		$wider['baseUrl'] = 'https://evil.example.com';
		$this->assertSame('extends must keep the base baseUrl and authScheme', $this->check($wider)['reason']);

		$scheme = $this->extendsGithub([['method' => 'GET', 'pathPattern' => '/repos/*']]);
		$scheme['authScheme'] = ['header' => 'X-Token', 'template' => '{secret}'];
		$this->assertSame('extends must keep the base baseUrl and authScheme', $this->check($scheme)['reason']);
	}

	public function testExtendsMustNameANarrowableReviewedProvider(): void {
		$unknown = $this->extendsGithub([['method' => 'GET', 'pathPattern' => '/repos/*']]);
		$unknown['extends'] = 'codeberg';
		$this->assertSame('extends names no reviewed provider', $this->check($unknown)['reason']);

		$injectOnly = $this->extendsGithub([['method' => 'GET', 'pathPattern' => '/repos/*']]);
		$injectOnly['extends'] = 'generic-bearer';
		$this->assertSame('extends names a provider that cannot be narrowed', $this->check($injectOnly)['reason']);
	}

	/**
	 * @dataProvider coverCases
	 */
	public function testCoversOnlyWhatItCanProve(string $base, string $declared, bool $expected): void {
		$this->assertSame($expected, $this->validator->covers(basePattern: $base, declaredPattern: $declared));
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function coverCases(): array {
		return [
			'identical' => ['/repos/*', '/repos/*', true],
			'a literal under a star' => ['/repos/*', '/repos/a/b', true],
			'a narrower star' => ['/repos/*/contents/*', '/repos/x/contents/docs/*', true],
			'a star absorbs a star' => ['/repos/*', '/repos/*/issues/*', true],
			'a star at the head is never narrower' => ['/repos/*', '*', false],
			'a star at the head of a longer pattern' => ['/repos/*', '*/repos/x', false],
			'traversal is never provable' => ['/repos/*', '/repos/*/../../admin', false],
			'a dot segment is never provable' => ['/repos/*', '/repos/./x', false],
			'an empty segment is never provable' => ['/repos/*', '/repos//x', false],
			'a wider declared pattern' => ['/repos/*/contents/*', '/repos/*', false],
			'a different literal' => ['/user/repos', '/user/orgs', false],
			'a literal never covers a declared star' => ['/repos/a', '/repos/*', false],
			'a literal tail never covers a declared star' => ['/user/repos', '/user/*', false],
			'a question mark in the declared pattern' => ['/repos/*', '/repos/?', false],
			'a class in the declared pattern' => ['/repos/*', '/repos/[ab]', false],
			'a base with a class covers only itself' => ['/repos/[ab]', '/repos/a', false],
			'an unrooted declared pattern' => ['*', 'repos', false],
			'an empty base' => ['', '/repos', false],
			'a trailing slash' => ['/repos/*', '/repos/x/', true],
		];
	}

	public function testTheDigestIgnoresKeyOrderButNotContent(): void {
		$entry = $this->novel();
		$reordered = array_reverse($entry, true);
		$reordered['authScheme'] = ['template' => 'token {secret}', 'header' => 'Authorization'];

		$this->assertSame($this->validator->digest($entry), $this->validator->digest($reordered));
		$this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $this->validator->digest($entry));

		$widened = $entry;
		$widened['allowRules'][] = ['method' => 'DELETE', 'pathPattern' => '/repos/*'];
		$this->assertNotSame($this->validator->digest($entry), $this->validator->digest($widened));

		$reorderedRules = $widened;
		$reorderedRules['allowRules'] = array_reverse($widened['allowRules']);
		$this->assertNotSame($this->validator->digest($widened), $this->validator->digest($reorderedRules));
	}
}
