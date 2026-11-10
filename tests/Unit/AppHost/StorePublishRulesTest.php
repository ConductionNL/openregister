<?php

/**
 * Tests for the AppHost store publish rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-publish-must-send-only-allowed-fields-and-never-an-identity-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Store\StorePublishRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\AppHost\Store\StorePublishRules
 *
 * The body rules read the descriptor's field list, so these cases execute
 * StoreDescriptor; declared with `@uses` so they are not risky under
 * `beStrictAboutCoverageMetadata`.
 *
 * @uses \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 */
class StorePublishRulesTest extends TestCase {
	/**
	 * A descriptor allowing the given fields.
	 *
	 * @param array<int, string> $fields The allowed fields.
	 *
	 * @return StoreDescriptor
	 */
	private function descriptor(array $fields): StoreDescriptor {
		return new StoreDescriptor(
			appId: 'learniq',
			schema: 'shared-course-package',
			defaultRegister: 'learniq',
			publishFields: $fields,
			publishGroups: ['instructors']
		);
	}

	/**
	 * The body is the slug plus allowed fields present in the payload, and no identity key.
	 *
	 * @return void
	 */
	public function testTheBodyIsTheSlugPlusAllowedFieldsWithoutIdentity(): void {
		$body = (new StorePublishRules())->body(
			descriptor: $this->descriptor(['title', 'uuid', 'missing', 'slug']),
			payload: [
				'slug' => 'course-package-betoog-1a2b3c4d',
				'title' => 'Betoog',
				'uuid' => '00000000-0000-0000-0000-000000000001',
				'secret' => 'stays home',
			]
		);

		$this->assertSame(['slug' => 'course-package-betoog-1a2b3c4d', 'title' => 'Betoog'], $body);
	}

	/**
	 * A payload whose slug the install route would refuse gets no body.
	 *
	 * @return void
	 */
	public function testAnInvalidSlugGetsNoBody(): void {
		$rules = new StorePublishRules();

		$this->assertNull($rules->body(descriptor: $this->descriptor(['title']), payload: ['slug' => 'Not-Valid']));
		$this->assertNull($rules->body(descriptor: $this->descriptor(['title']), payload: ['slug' => 'ends-with-']));
		$this->assertNull($rules->body(descriptor: $this->descriptor(['title']), payload: []));
	}

	/**
	 * The encoded body is the JSON of the body; no body means no JSON.
	 *
	 * @return void
	 */
	public function testEncodedBodyIsTheJsonOfTheBodyOrNull(): void {
		$rules = new StorePublishRules();

		$this->assertSame(
			'{"slug":"a-b","title":"Één/twee"}',
			$rules->encodedBody(descriptor: $this->descriptor(['title']), payload: ['slug' => 'a-b', 'title' => 'Één/twee'])
		);
		$this->assertNull($rules->encodedBody(descriptor: $this->descriptor(['title']), payload: ['title' => 'no slug']));
		$this->assertNull(
			$rules->encodedBody(descriptor: $this->descriptor(['title']), payload: ['slug' => 'a-b', 'title' => "\xB1\x31"]),
			'A payload that does not encode as JSON is refused, not sent half-empty.'
		);
	}

	/**
	 * Only a 2xx is success.
	 *
	 * @return void
	 */
	public function testOnlyA2xxIsSuccess(): void {
		$rules = new StorePublishRules();

		$this->assertTrue($rules->isSuccess(status: 200));
		$this->assertTrue($rules->isSuccess(status: 201));
		$this->assertTrue($rules->isSuccess(status: 299));
		$this->assertFalse($rules->isSuccess(status: 199));
		$this->assertFalse($rules->isSuccess(status: 300));
		$this->assertFalse($rules->isSuccess(status: 404));
	}

	/**
	 * Each failure status names its remedy.
	 *
	 * @return void
	 */
	public function testFailureStatusesMapToTheirOutcome(): void {
		$rules = new StorePublishRules();

		$this->assertSame(GenericStoreService::OUTCOME_RATE_LIMITED, $rules->failureOutcome(status: 429));
		$this->assertSame(GenericStoreService::OUTCOME_REJECTED, $rules->failureOutcome(status: 400));
		$this->assertSame(GenericStoreService::OUTCOME_REJECTED, $rules->failureOutcome(status: 499));
		$this->assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $rules->failureOutcome(status: 301));
		$this->assertSame(GenericStoreService::OUTCOME_UNREACHABLE, $rules->failureOutcome(status: 500));
	}

	/**
	 * Only a JSON object counts as a stored object; a list or garbage does not.
	 *
	 * @return void
	 */
	public function testOnlyAJsonObjectIsAStoredObject(): void {
		$rules = new StorePublishRules();

		$this->assertSame(['slug' => 'a-b'], $rules->storedObject(body: '{"slug":"a-b"}'));
		$this->assertNull($rules->storedObject(body: '[{"slug":"a-b"}]'));
		$this->assertNull($rules->storedObject(body: 'not json'));
		$this->assertNull($rules->storedObject(body: '"a-b"'));
	}
}
