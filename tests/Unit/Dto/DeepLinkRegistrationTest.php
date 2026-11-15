<?php

declare(strict_types=1);

/**
 * DeepLinkRegistration Unit Tests
 *
 * Tests URL template placeholder resolution including contact placeholders.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Dto
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Dto;

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DeepLinkRegistration.
 */
class DeepLinkRegistrationTest extends TestCase {

	// -------------------------------------------------------------------------
	// Contact placeholder resolution
	// -------------------------------------------------------------------------

	public function testContactEmailPlaceholderIsUrlEncoded(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/cases?email={contactEmail}'
		);

		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'abc-123'],
			contactContext: [
				'contactEmail' => 'jan@example.nl',
				'contactName' => 'Jan de Vries',
				'contactId' => 'uid-456',
			]
		);

		$this->assertStringContainsString(urlencode('jan@example.nl'), $url);
		$this->assertStringNotContainsString('jan@example.nl', $url);
	}

	public function testContactNamePlaceholderIsUrlEncoded(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/cases?name={contactName}'
		);

		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'abc-123'],
			contactContext: [
				'contactEmail' => 'jan@example.nl',
				'contactName' => 'Jan de Vries',
				'contactId' => 'uid-456',
			]
		);

		$this->assertStringContainsString(urlencode('Jan de Vries'), $url);
	}

	public function testEntityIdPlaceholderIsReplacedWithUuid(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/cases/{entityId}'
		);

		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'abc-123'],
			contactContext: ['contactEmail' => 'test@example.nl']
		);

		$this->assertSame('/apps/procest/#/cases/abc-123', $url);
	}

	public function testContactIdPlaceholderIsUrlEncoded(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/contact/{contactId}'
		);

		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'abc-123'],
			contactContext: ['contactId' => 'vcard-uid-789']
		);

		$this->assertStringContainsString('vcard-uid-789', $url);
	}

	public function testBothObjectAndContactPlaceholdersCoexist(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/cases/{uuid}?email={contactEmail}&name={contactName}'
		);

		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'obj-uuid-111'],
			contactContext: [
				'contactEmail' => 'test@example.nl',
				'contactName' => 'Test User',
			]
		);

		$this->assertStringContainsString('obj-uuid-111', $url);
		$this->assertStringContainsString(urlencode('test@example.nl'), $url);
		$this->assertStringContainsString(urlencode('Test User'), $url);
	}

	public function testMissingContactContextLeavesPlaceholdersAsIs(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/cases/{uuid}?email={contactEmail}'
		);

		// No contactContext = empty array (default).
		$url = $reg->resolveUrl(
			objectData: ['uuid' => 'abc-123']
		);

		// Without contact context, the {contactEmail} placeholder should remain.
		$this->assertStringContainsString('{contactEmail}', $url);
		$this->assertStringContainsString('abc-123', $url);
	}

	public function testOriginalObjectPlaceholdersStillWork(): void {
		$reg = new DeepLinkRegistration(
			appId: 'procest',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/procest/#/{schema}/{uuid}'
		);

		$url = $reg->resolveUrl(
			objectData: [
				'uuid' => 'abc-123',
				'schema' => '5',
				'register' => '3',
			]
		);

		$this->assertSame('/apps/procest/#/5/abc-123', $url);
	}

	// -------------------------------------------------------------------------
	// Task route resolution (flow-task-search-provider, design D-3)
	// -------------------------------------------------------------------------

	public function testTaskUrlTemplateFillsTheTaskPlaceholdersEncoded(): void {
		$reg = new DeepLinkRegistration(
			appId: 'dossiq',
			registerSlug: 'main',
			schemaSlug: 'zaken',
			urlTemplate: '/apps/dossiq/#/cases/{uuid}',
			taskUrlTemplate: '/apps/dossiq/#/r/{register}/s/{schema}/cases/{objectUuid}/tasks/{taskUuid}?t={uuid}'
		);

		$url = $reg->resolveTaskUrl(taskData: ['uuid' => 'a b', 'objectUuid' => 'case/9', 'registerId' => 3, 'schemaId' => 12]);

		$this->assertSame('/apps/dossiq/#/r/3/s/12/cases/case%2F9/tasks/a%20b?t=a%20b', $url);
	}

	public function testNoTaskUrlTemplateResolvesToNull(): void {
		$reg = new DeepLinkRegistration('procest', 'main', 'zaken', '/apps/procest/#/cases/{uuid}');

		$this->assertNull($reg->taskUrlTemplate);
		$this->assertNull($reg->resolveTaskUrl(taskData: ['uuid' => 'task-1']));
	}

	public function testABlankTaskUrlTemplateResolvesToNull(): void {
		$reg = new DeepLinkRegistration('procest', 'main', 'zaken', '/apps/procest/#/cases/{uuid}', taskUrlTemplate: '  ');

		$this->assertNull($reg->resolveTaskUrl(taskData: ['uuid' => 'task-1']));
	}
}
