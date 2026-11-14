<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The NLGov rule catalogue: every rule once, each with a kind, deviations only where declared.
 *
 * @covers \OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue
 */
class NlGovRuleCatalogueTest extends TestCase {

	/**
	 * The catalogue is pinned to ruleset 2.2.1.
	 *
	 * @return void
	 */
	public function testThePinnedRulesetVersion(): void {
		$this->assertSame('2.2.1', NlGovRuleCatalogue::RULESET_VERSION);
	}//end testThePinnedRulesetVersion()

	/**
	 * Every rule of the design's table, each id once, in the four kinds.
	 *
	 * @return void
	 */
	public function testEveryRuleOnceWithItsKind(): void {
		$rules = NlGovRuleCatalogue::rules();
		$ids = array_column($rules, 'id');

		$this->assertSame($ids, array_values(array_unique($ids)));
		$this->assertCount(35, $rules);

		$kinds = array_count_values(array_column($rules, 'kind'));
		$this->assertSame(
			[
				NlGovRuleCatalogue::KIND_DOCUMENT => 14,
				NlGovRuleCatalogue::KIND_RESPONSE => 3,
				NlGovRuleCatalogue::KIND_FUNCTIONAL => 15,
				NlGovRuleCatalogue::KIND_MODULE => 3,
			],
			$kinds
		);

		foreach ($rules as $rule) {
			$this->assertMatchesRegularExpression('#^/core/[a-z/-]+$#', $rule['id']);
			$this->assertNotSame('', $rule['title'], $rule['id']);
		}
	}//end testEveryRuleOnceWithItsKind()

	/**
	 * The rules each kind holds, as design D-1 lists them.
	 *
	 * @return void
	 */
	public function testTheResponseAndModuleRules(): void {
		$byKind = [];
		foreach (NlGovRuleCatalogue::rules() as $rule) {
			$byKind[$rule['kind']][] = $rule['id'];
		}

		$this->assertSame(['/core/version-header', '/core/transport/security-headers', '/core/date-time/timezone'], $byKind['response']);
		$this->assertSame(['/core/modules/geospatial', '/core/modules/signing', '/core/modules/encryption'], $byKind['module']);
		$this->assertContains('/core/http-methods', $byKind['document']);
		$this->assertContains('/core/http-response-code', $byKind['functional']);
	}//end testTheResponseAndModuleRules()

	/**
	 * A reason on exactly the deviations named in design D-4.
	 *
	 * @return void
	 */
	public function testDeviationsAreExactlyTheDeclaredOnes(): void {
		$deviations = array_filter(array_column(NlGovRuleCatalogue::rules(), 'deviation', 'id'), static fn (?string $reason): bool => $reason !== null);

		$this->assertSame(['/core/query-keys-camel-case', '/core/uri-version'], array_keys($deviations));
		$this->assertStringContainsString('_limit', $deviations['/core/query-keys-camel-case']);
		$this->assertStringContainsString('API-Version', $deviations['/core/uri-version']);
		$this->assertNull(NlGovRuleCatalogue::deviation(ruleId: '/core/no-trailing-slash'));
		$this->assertSame($deviations['/core/uri-version'], NlGovRuleCatalogue::deviation(ruleId: '/core/uri-version'));
	}//end testDeviationsAreExactlyTheDeclaredOnes()

	/**
	 * One rule by id; an unknown id is none.
	 *
	 * @return void
	 */
	public function testOneRuleById(): void {
		$this->assertSame(
			['id' => '/core/semver', 'title' => 'Adhere to the Semantic Versioning model', 'kind' => 'document', 'deviation' => null],
			NlGovRuleCatalogue::rule(ruleId: '/core/semver')
		);
		$this->assertNull(NlGovRuleCatalogue::rule(ruleId: '/core/unknown'));
		$this->assertNull(NlGovRuleCatalogue::deviation(ruleId: '/core/unknown'));
	}//end testOneRuleById()

	/**
	 * The method and status code lists the generator checks moved here unchanged.
	 *
	 * @return void
	 */
	public function testTheMethodAndStatusCodeLists(): void {
		$this->assertSame(['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], NlGovRuleCatalogue::ALLOWED_HTTP_METHODS);
		$this->assertSame(['200', '201', '204', '400', '401', '403', '404', '422', '500', 'default'], NlGovRuleCatalogue::ALLOWED_STATUS_CODES);
	}//end testTheMethodAndStatusCodeLists()
}//end class
