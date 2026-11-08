<?php

/**
 * LifecycleAnnotationCheck: save-time check of the lifecycle annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidators;
use Psr\Log\LoggerInterface;

/**
 * Refuses the lifecycle faults that break a transition and logs the rest.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleAnnotationCheck {

	/**
	 * The lifecycle error codes that refuse the save.
	 *
	 * A broken transition CONDITION refuses the save. A condition is a gate, and
	 * a gate that is stored broken either blocks every transition it covers or,
	 * for a graph block, silently gates nothing while its author believes it
	 * holds. No register shipped a condition before this key existed, so
	 * refusing here breaks no existing import, which is what the advisory policy
	 * protects.
	 *
	 * The four `autoWhen` / `executionMode` codes join it for the same two
	 * reasons. A stored scalar `autoWhen` is WORSE than a stored broken
	 * condition: it evaluates as a truthy literal, so the transition fires on
	 * every write from its `from` state, each move writing an audit row and
	 * feeding the next decision. The loop cap bounds that, but "bounded damage on
	 * every save" is not an acceptable failure mode for a typo. An unknown
	 * `executionMode`, a required input beside `autoWhen` and an `autoWhen` on a
	 * graph block each describe a move that can never happen as written.
	 *
	 * The two `provider` codes join them on the same test. A mistyped provider
	 * tag, or a `provider` declared beside `transitions`, does not fail at save
	 * time: it fails on a GET, when the client asks what the object can do and
	 * OpenRegister cannot resolve the app service that knows. Stored advisory,
	 * that is a lifecycle an author believes is wired and a user sees as a dead
	 * timeline.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKING_CODES = [
		'lifecycle-condition-malformed',
		'lifecycle-condition-graph-unsupported',
		'lifecycle-autowhen-malformed',
		'lifecycle-execution-mode-malformed',
		'lifecycle-autowhen-requires-input',
		'lifecycle-autowhen-graph-unsupported',
		'lifecycle-provider-invalid',
		'lifecycle-provider-mode-conflict',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the advisory faults.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate the optional `x-openregister-lifecycle` annotation.
	 *
	 * A broken transition `condition`, `autoWhen` or `executionMode` refuses the
	 * save. Every other lifecycle error is ADVISORY: rejecting the whole schema
	 * import over it breaks register imports for apps that ship a partial or
	 * different-dialect lifecycle block. So the schema is saved and this is a
	 * warning. Note what that does NOT mean: the annotation is stored as written,
	 * and LifecycleValidationListener still acts on it. It is not switched off,
	 * so the warning must not claim it was.
	 *
	 * @param Schema $schema Schema to validate.
	 *
	 * @throws \Exception When a transition `condition` or automatic-transition
	 *                    declaration is malformed or unsupported.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function check(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-lifecycle');
		if ($annotation->isBlock() === false) {
			return;
		}

		$errors = SchemaAnnotationValidators::lifecycle()->validate($annotation->shape());
		if (count($errors) === 0) {
			return;
		}

		$annotation->faults()->refuseInvalid(errors: $this->blocking(errors: $errors));
		$this->logger->warning(
			$annotation->label() . ' is invalid; it was stored as written and the lifecycle listener still acts on it: '
			. implode(' ', array_map(static fn (array $err) => $err['message'], $errors))
		);
	}//end check()

	/**
	 * The errors that refuse the save.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The validator's errors.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function blocking(array $errors): array {
		return array_values(
			array_filter(
				$errors,
				static fn (array $err): bool => in_array($err['code'], self::BLOCKING_CODES, true)
			)
		);
	}//end blocking()
}//end class
