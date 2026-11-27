<?php

/**
 * Record templates against a running instance.
 *
 * Drives `/api/record-templates` over HTTP as an administrator and three
 * accounts the test provisions itself: an editor who may create in the
 * schema, a viewer who may only read it, and an editor who is also in the
 * viewers' group. It pins who is offered which template (own, public, shared
 * with a group) and that a caller without create rights is offered none of
 * them, not even a public one or one shared with their own group; and that
 * only the owner or an administrator changes or deletes a template.
 *
 * Needs a Nextcloud with OpenRegister: set NEXTCLOUD_URL (or BASE_URL) to its
 * address, and ADMIN_USER / ADMIN_PASSWORD when they are not admin/admin.
 * Without a URL the test is skipped, never pointed at a default instance.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Api
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Api;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RecordTemplatesTest extends TestCase {

	/**
	 * The accounts this test provisions: name => [password, groups].
	 *
	 * @var array<string, array{0: string, 1: list<string>}>
	 */
	private const ACCOUNTS = [
		'editor' => ['Rt-Editor-Api-2026!', ['editors']],
		'viewer' => ['Rt-Viewer-Api-2026!', ['viewers']],
		'both' => ['Rt-Both-Api-2026!', ['editors', 'viewers']],
	];

	/**
	 * The instance's address.
	 *
	 * @var string
	 */
	private string $baseUrl = '';

	/**
	 * A suffix that keeps this run's groups, accounts, register and schema apart from any other run's.
	 *
	 * @var string
	 */
	private string $run = '';

	/**
	 * The register id this run created.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The schema id this run created.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The template uuids this run created, for clean-up.
	 *
	 * @var list<string>
	 */
	private array $templates = [];

	protected function setUp(): void {
		$url = (getenv('NEXTCLOUD_URL') ?: getenv('BASE_URL'));
		if (is_string($url) === false || $url === '') {
			$this->markTestSkipped('Set NEXTCLOUD_URL (or BASE_URL) to a Nextcloud with OpenRegister to run the record template API test.');
		}

		$this->baseUrl = rtrim($url, '/');
		$this->run = substr(bin2hex(random_bytes(4)), 0, 8);

		foreach (['editors', 'viewers'] as $group) {
			$this->ocs(method: 'POST', path: '/cloud/groups', form: ['groupid' => $this->runName(base: $group)]);
		}

		foreach (self::ACCOUNTS as $account => [$password, $groups]) {
			$this->ocs(
				method: 'POST',
				path: '/cloud/users',
				form: [
					'userid' => $this->runName(base: $account),
					'password' => $password,
					'groups' => array_map(fn (string $group): string => $this->runName(base: $group), $groups),
				]
			);
		}

		$schema = $this->api(
			method: 'POST',
			path: '/schemas',
			body: [
				'title' => 'Record template API test ' . $this->run,
				'slug' => 'rt-api-' . $this->run,
				'properties' => ['titel' => ['type' => 'string'], 'categorie' => ['type' => 'string']],
				'authorization' => [
					'create' => [$this->runName(base: 'editors')],
					'read' => [$this->runName(base: 'editors'), $this->runName(base: 'viewers')],
					'update' => [$this->runName(base: 'editors')],
				],
			]
		);
		$this->assertSame(201, $schema['status'], 'the test schema is created');
		$this->schema = (string) $schema['body']['id'];

		$register = $this->api(
			method: 'POST',
			path: '/registers',
			body: ['title' => 'Record template API test ' . $this->run, 'slug' => 'rt-api-' . $this->run, 'schemas' => [(int) $this->schema]]
		);
		$this->assertSame(201, $register['status'], 'the test register is created');
		$this->register = (string) $register['body']['id'];
	}//end setUp()

	protected function tearDown(): void {
		if ($this->baseUrl === '') {
			return;
		}

		foreach ($this->templates as $uuid) {
			$this->api(method: 'DELETE', path: '/record-templates/' . $uuid);
		}

		if ($this->register !== '') {
			$this->api(method: 'DELETE', path: '/registers/' . $this->register);
		}

		if ($this->schema !== '') {
			$this->api(method: 'DELETE', path: '/schemas/' . $this->schema);
		}

		foreach (array_keys(self::ACCOUNTS) as $account) {
			$this->ocs(method: 'DELETE', path: '/cloud/users/' . $this->runName(base: $account));
		}

		foreach (['editors', 'viewers'] as $group) {
			$this->ocs(method: 'DELETE', path: '/cloud/groups/' . $this->runName(base: $group));
		}
	}//end tearDown()

	public function testWhoIsOfferedWhichTemplate(): void {
		$public = $this->create(as: null, name: 'Openbare melding', extra: ['isPublic' => true, 'values' => ['categorie' => 'afval']]);
		$shared = $this->create(as: null, name: 'Groep melding', extra: ['sharedWith' => [['group' => $this->runName(base: 'viewers'), 'mode' => 'read']]]);
		$own = $this->create(as: 'editor', name: 'Eigen melding', extra: ['values' => ['titel' => 'Losliggende stoeptegel']]);

		$this->assertSame(['Eigen melding', 'Openbare melding'], $this->offered(as: 'editor'));
		// In the viewers' group AND allowed to create: the group share reaches them.
		$this->assertSame(['Groep melding', 'Openbare melding'], $this->offered(as: 'both'));
		// The administrator owns two and is not offered somebody else's private template.
		$this->assertSame(['Groep melding', 'Openbare melding'], $this->offered(as: null));

		$this->assertSame(200, $this->api(method: 'GET', path: '/record-templates/' . $own, as: 'editor')['status']);
		$this->assertSame(404, $this->api(method: 'GET', path: '/record-templates/' . $own, as: 'both')['status'], 'a private template stays its owner\'s');
		$this->assertSame(['categorie' => 'afval'], $this->api(method: 'GET', path: '/record-templates/' . $public, as: 'editor')['body']['values']);
		$this->assertSame(200, $this->api(method: 'GET', path: '/record-templates/' . $shared, as: 'both')['status']);
	}//end testWhoIsOfferedWhichTemplate()

	public function testAUserWithoutCreateRightsIsOfferedNoTemplateAtAll(): void {
		$public = $this->create(as: null, name: 'Openbare melding', extra: ['isPublic' => true]);
		$shared = $this->create(as: null, name: 'Groep melding', extra: ['sharedWith' => [['group' => $this->runName(base: 'viewers'), 'mode' => 'read']]]);

		// The viewer may read the schema, and the second template is even shared with their group.
		$this->assertSame([], $this->offered(as: 'viewer'));
		$this->assertSame(404, $this->api(method: 'GET', path: '/record-templates/' . $public, as: 'viewer')['status']);
		$this->assertSame(404, $this->api(method: 'GET', path: '/record-templates/' . $shared, as: 'viewer')['status']);

		$refused = $this->api(
			method: 'POST',
			path: '/record-templates',
			body: ['name' => 'Mag niet', 'register' => $this->register, 'schema' => $this->schema],
			as: 'viewer'
		);
		$this->assertSame(403, $refused['status']);
		$this->assertSame(['Groep melding', 'Openbare melding'], $this->offered(as: null), 'the refused template was not stored');
	}//end testAUserWithoutCreateRightsIsOfferedNoTemplateAtAll()

	public function testOnlyTheOwnerOrAnAdministratorChangesOrDeletesATemplate(): void {
		$shared = $this->create(as: null, name: 'Groep melding', extra: ['sharedWith' => [['group' => $this->runName(base: 'viewers'), 'mode' => 'read']]]);
		$own = $this->create(as: 'editor', name: 'Eigen melding');

		$this->assertSame(403, $this->api(method: 'PATCH', path: '/record-templates/' . $shared, body: ['name' => 'Anders'], as: 'both')['status'], 'a read share does not let them change it');
		$this->assertSame(404, $this->api(method: 'DELETE', path: '/record-templates/' . $own, as: 'both')['status'], 'another editor cannot reach a private template');

		$changed = $this->api(method: 'PATCH', path: '/record-templates/' . $own, body: ['description' => 'Door beheer aangevuld']);
		$this->assertSame(200, $changed['status'], 'an administrator changes any template');
		$this->assertSame('Door beheer aangevuld', $changed['body']['description']);
		$this->assertSame($this->runName(base: 'editor'), $changed['body']['owner'], 'the owner stays the owner');

		$this->assertSame(204, $this->api(method: 'DELETE', path: '/record-templates/' . $own, as: 'editor')['status']);
		$this->assertSame(404, $this->api(method: 'GET', path: '/record-templates/' . $own, as: 'editor')['status']);
		$this->assertSame(['Groep melding'], $this->offered(as: null));
	}//end testOnlyTheOwnerOrAnAdministratorChangesOrDeletesATemplate()

	public function testNobodySignedInIsRefused(): void {
		$response = (new Client(['http_errors' => false]))->get(
			$this->baseUrl . '/index.php/apps/openregister/api/record-templates',
			['headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json']]
		);

		$this->assertSame(401, $response->getStatusCode());
	}//end testNobodySignedInIsRefused()

	/**
	 * Create a template in this run's register and schema and remember it for clean-up.
	 *
	 * @param string|null          $as    The account, or null for the administrator.
	 * @param string               $name  The template name.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return string The uuid.
	 */
	private function create(?string $as, string $name, array $extra = []): string {
		$response = $this->api(
			method: 'POST',
			path: '/record-templates',
			body: array_merge(['name' => $name, 'register' => $this->register, 'schema' => $this->schema], $extra),
			as: $as
		);
		$this->assertSame(201, $response['status'], 'template "' . $name . '" is created: ' . json_encode($response['body']));
		$this->templates[] = (string) $response['body']['uuid'];

		return (string) $response['body']['uuid'];
	}//end create()

	/**
	 * The names of the templates an account is offered for this run's register and schema, sorted.
	 *
	 * @param string|null $as The account, or null for the administrator.
	 *
	 * @return list<string>
	 */
	private function offered(?string $as): array {
		$response = $this->api(method: 'GET', path: '/record-templates?register=' . $this->register . '&schema=' . $this->schema, as: $as);
		$this->assertSame(200, $response['status']);
		$names = array_map(static fn (array $template): string => (string) $template['name'], $response['body']['results']);
		sort($names);

		return $names;
	}//end offered()

	/**
	 * One OpenRegister API call.
	 *
	 * @param string                    $method The HTTP method.
	 * @param string                    $path   The path under /api.
	 * @param array<string, mixed>|null $body   The JSON body, or null.
	 * @param string|null               $as     The account, or null for the administrator.
	 *
	 * @return array{status: int, body: mixed}
	 */
	private function api(string $method, string $path, ?array $body = null, ?string $as = null): array {
		$options = ['auth' => $this->credentials(as: $as), 'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json']];
		if ($body !== null) {
			$options['json'] = $body;
		}

		$response = (new Client(['http_errors' => false]))->request($method, $this->baseUrl . '/index.php/apps/openregister/api' . $path, $options);

		return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
	}//end api()

	/**
	 * One provisioning call as the administrator.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The path under /ocs/v2.php.
	 * @param array<string, mixed> $form   The form fields.
	 *
	 * @return void
	 */
	private function ocs(string $method, string $path, array $form = []): void {
		(new Client(['http_errors' => false]))->request(
			$method,
			$this->baseUrl . '/ocs/v2.php' . $path,
			['auth' => $this->credentials(as: null), 'headers' => ['OCS-APIRequest' => 'true'], 'form_params' => $form]
		);
	}//end ocs()

	/**
	 * Basic-auth credentials of an account, or of the administrator.
	 *
	 * @param string|null $as The account, or null for the administrator.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function credentials(?string $as): array {
		if ($as === null) {
			return [(getenv('ADMIN_USER') ?: 'admin'), (getenv('ADMIN_PASSWORD') ?: 'admin')];
		}

		return [$this->runName(base: $as), self::ACCOUNTS[$as][0]];
	}//end credentials()

	/**
	 * A group or account name unique to this run.
	 *
	 * @param string $base The base name.
	 *
	 * @return string
	 */
	private function runName(string $base): string {
		return 'rt-' . $base . '-' . $this->run;
	}//end runName()
}//end class
