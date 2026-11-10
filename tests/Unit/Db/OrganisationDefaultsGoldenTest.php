<?php

/**
 * Characterisation of the Organisation entity's defaults: the registered
 * field types and the default authorization block an organisation carries
 * until rules are set on it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\TestCase;

/**
 * Golden field types and default authorization for Organisation.
 *
 * @covers \OCA\OpenRegister\Db\Organisation
 */
final class OrganisationDefaultsGoldenTest extends TestCase {

	/**
	 * The registered field types, in registration order.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'id' => 'integer',
		'uuid' => 'string',
		'slug' => 'string',
		'name' => 'string',
		'description' => 'string',
		'users' => 'json',
		'groups' => 'json',
		'owner' => 'string',
		'created' => 'datetime',
		'updated' => 'datetime',
		'active' => 'boolean',
		'storageQuota' => 'integer',
		'bandwidthQuota' => 'integer',
		'requestQuota' => 'integer',
		'userQuota' => 'integer',
		'objectQuota' => 'integer',
		'objectQuotaSchema' => 'string',
		'authorization' => 'json',
		'parent' => 'string',
		'mail' => 'json',
		'contacts' => 'json',
		'notes' => 'json',
		'todos' => 'json',
		'calendar' => 'json',
		'talk' => 'json',
		'deck' => 'json',
		'status' => 'string',
		'environment' => 'string',
		'provisionedAt' => 'datetime',
		'suspendedAt' => 'datetime',
		'deprovisionedAt' => 'datetime',
		'retainedAt' => 'datetime',
		'type' => 'string',
		'isLocalTenant' => 'boolean',
		'remoteInstanceUrl' => 'string',
		'contactEmail' => 'string',
		'defaultPermissionLevel' => 'string',
		'qualityScore' => 'integer',
		'qualityStatus' => 'string',
		'summary' => 'string',
		'oin' => 'string',
		'tooi' => 'string',
		'rsin' => 'string',
		'kvk' => 'string',
		'legalName' => 'string',
		'pki' => 'string',
		'image' => 'string',
		'registrationStatus' => 'string',
		'mergedInto' => 'string',
		'mergedAt' => 'datetime',
	];

	/**
	 * The default authorization: every right present, granted to nobody.
	 *
	 * @var array<string, array<mixed>>
	 */
	private const DEFAULT_AUTHORIZATION = [
		'register' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'schema' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'object' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'view' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'agent' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'configuration' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'application' => [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		],
		'object_publish' => [],
		'agent_use' => [],
		'dashboard_view' => [],
		'llm_use' => [],
		'grant_manage' => [],
	];

	/**
	 * Field types are registered under the same names, types and order.
	 *
	 * @return void
	 */
	public function testFieldTypesAreRegisteredInOrder(): void {
		$this->assertSame(self::FIELD_TYPES, (new Organisation())->getFieldTypes());
	}//end testFieldTypesAreRegisteredInOrder()

	/**
	 * Without rules an organisation grants nothing: every right is present and empty.
	 *
	 * @return void
	 */
	public function testDefaultAuthorizationGrantsNobody(): void {
		$organisation = new Organisation();

		$this->assertSame(self::DEFAULT_AUTHORIZATION, $organisation->getAuthorization());
		$this->assertSame(self::DEFAULT_AUTHORIZATION, $organisation->jsonSerialize()['authorization']);
		$this->assertSame([], $organisation->getAuthorization()['grant_manage']);
		$this->assertSame([], $organisation->getAuthorization()['object']['delete']);
	}//end testDefaultAuthorizationGrantsNobody()

	/**
	 * Clearing the rules falls back to the same deny-all default.
	 *
	 * @return void
	 */
	public function testClearedAuthorizationFallsBackToTheDefault(): void {
		$organisation = new Organisation();
		$organisation->setAuthorization(['grant_manage' => ['admins']]);
		$organisation->setAuthorization(null);

		$this->assertSame(self::DEFAULT_AUTHORIZATION, $organisation->getAuthorization());
	}//end testClearedAuthorizationFallsBackToTheDefault()

	/**
	 * Rules that are set are returned as given: a granted group stays granted.
	 *
	 * @return void
	 */
	public function testSetAuthorizationGrantsWhatItSays(): void {
		$organisation = new Organisation();
		$organisation->setAuthorization(['grant_manage' => ['admins']]);

		$this->assertSame(['grant_manage' => ['admins']], $organisation->getAuthorization());
	}//end testSetAuthorizationGrantsWhatItSays()
}//end class
