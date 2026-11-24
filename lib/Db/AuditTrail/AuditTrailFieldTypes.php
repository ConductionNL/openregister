<?php

/**
 * OpenRegister AuditTrailFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * The column types of an audit trail row, in registration order, and the fields its sealed form carries.
 *
 * @spec exclude audit trail plumbing moved out of the entity, not a capability of its own
 */
final class AuditTrailFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'schema' => 'integer',
		'register' => 'integer',
		'object' => 'integer',
		'objectUuid' => 'string',
		'registerUuid' => 'string',
		'schemaUuid' => 'string',
		'action' => 'string',
		'changed' => 'json',
		'user' => 'string',
		'userName' => 'string',
		'session' => 'string',
		'request' => 'string',
		'ipAddress' => 'string',
		'version' => 'string',
		'created' => 'datetime',
		'organisationId' => 'string',
		'organisationIdType' => 'string',
		'processingActivityId' => 'string',
		'processingActivityUrl' => 'string',
		'processingId' => 'string',
		'confidentiality' => 'string',
		'retentionPeriod' => 'string',
		'size' => 'integer',
		'expires' => 'datetime',
		'hash' => 'string',
		'previousHash' => 'string',
		'importJobId' => 'string',
		'toolId' => 'string',
		'paramsDigest' => 'string',
		'resultSummary' => 'json',
		'purgedAt' => 'datetime',
		'cause' => 'string',
		'causeRun' => 'string',
		'flowRun' => 'string',
		'flowNode' => 'string',
		'flowStep' => 'integer',
		'purpose' => 'string',
		'consumer' => 'string',
		'triggerObject' => 'string',
	];

	/**
	 * The fields serialised after the id, in API order: the form the hash chain seals.
	 *
	 * ⚠️ Every one of these is COVERED BY THE HASH CHAIN. They are part of the
	 * canonical JSON that AuditHashService seals, which is what makes
	 * re-pointing a row at a different run detectable rather than merely
	 * unlikely. That coverage is also why adding the flow attribution fields
	 * (cause … flowStep) was a genesis-seed migration (v1 → v2, ADR-003 Rule 4)
	 * rather than a column addition: any key added here changes the canonical
	 * form of every row ever written. Do not add a key to this list without
	 * reading that ADR — and note that `purgedAt` is deliberately ABSENT for
	 * exactly this reason, as are `importJobId`, `purpose`, `consumer` and
	 * `triggerObject` (a projection of `changed`, which is sealed already).
	 *
	 * @var string[]
	 */
	public const SEALED_FIELDS = [
		'uuid', 'schema', 'register', 'object', 'objectUuid', 'registerUuid', 'schemaUuid',
		'action', 'changed', 'user', 'userName', 'session', 'request', 'ipAddress', 'version',
		'created', 'organisationId', 'organisationIdType', 'processingActivityId',
		'processingActivityUrl', 'processingId', 'confidentiality', 'retentionPeriod', 'size',
		'expires', 'hash', 'previousHash', 'toolId', 'paramsDigest', 'resultSummary',
		'cause', 'causeRun', 'flowRun', 'flowNode', 'flowStep',
	];
}//end class
