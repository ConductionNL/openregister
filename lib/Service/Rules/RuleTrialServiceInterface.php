<?php

/**
 * RuleTrialServiceInterface: what callers outside the Service component ask of RuleTrialService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Trying a rule without writing, as the rules controller uses it. Only the
 * methods and the constant that caller uses.
 */
interface RuleTrialServiceInterface {

	/**
	 * The refusal when the named object cannot be read.
	 *
	 * @var string
	 */
	public const CODE_OBJECT_NOT_FOUND = 'rule-trial-object-not-found';

	/**
	 * Try a rule against a stored object, without writing.
	 *
	 * @param RuleTrialRequest $request The rule to try: schema, rule id and optional unsaved declaration.
	 * @param ObjectRef        $ref     The register (id, uuid or slug) and object (id, uuid, slug or uri); the schema is the request's.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 */
	public function tryObject(RuleTrialRequest $request, ObjectRef $ref): array;

	/**
	 * Try a rule against a sample payload, without writing.
	 *
	 * @param RuleTrialRequest     $request The rule to try: schema, rule id and optional unsaved declaration.
	 * @param array<string, mixed> $sample  The object payload to evaluate against.
	 *
	 * @return array<string, mixed> The verdict, the trace and the writes it would make.
	 */
	public function trySample(RuleTrialRequest $request, array $sample): array;
}//end interface
