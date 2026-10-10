<?php

/**
 * A form submit creates its destination object in one request.
 *
 * Decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we
 * intake into a case, or ticket or something else." ADR-117 decisions 3 and
 * 4. Nothing sits between the submit and the object: the payload is checked
 * by the destination schema's full validator, whatever its hard-validation
 * flag, the object is created under the subject's RBAC, and the answer names
 * the object a person will work on, with every property its schema marks
 * `x-openregister.confirmation: true`, read after every create listener ran.
 *
 * Several writes (a journey step's `writes[]`) commit all or none: every
 * write is validated before the first is made, and when a later write is
 * still refused (a uniqueness rule only the store can check, an outage),
 * the earlier writes of that submit are deleted before the answer returns.
 * OpenRegister has no cross-object transaction, so compensation is the
 * mechanism; this service makes it a property of the submit, not of every
 * caller. Upload tokens are claimed only after the last write succeeded, so
 * a refused submit leaves the resident's files ready for the retry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validate all, write in order, compensate on a late refusal, answer with the real record.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The submit IS the meeting point of
 * mapping, validation, save, delete, uploads and idempotency; each collaborator is one
 * of those steps and none is reached any other way.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the submit contract:
 * map, validate all, write in order, compensate, answer. Split, the all-or-none rule would
 * live in two files that must agree on what a plan is.
 * @SuppressWarnings(PHPMD.StaticAccess) FormDestinationValidator::marker() and
 * markedProperties() are pure reads of a schema property's `x-openregister` block.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormSubmitService {

	/**
	 * Stands in for an earlier write's id while every write is validated before the first.
	 *
	 * @var string
	 */
	public const PENDING_ID = '00000000-0000-4000-8000-000000000000';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper       $registers Resolves a destination's register.
	 * @param SchemaMapper         $schemas   Resolves a destination's schema.
	 * @param ValidateObject       $validator The destination schema's full validator.
	 * @param ObjectService        $objects   Creates, re-reads and (to compensate) deletes objects.
	 * @param FormPayloadFindings  $findings  Turns a refusal into per-property findings.
	 * @param FormIdempotencyStore $keys      Repeats the first answer for a retried key.
	 * @param FormUploadStore      $uploads   Holds and releases upload tokens.
	 * @param IL10N                $l10n      Translations, for answers a resident reads.
	 * @param LoggerInterface      $logger    Records each compensation.
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly ValidateObject $validator,
		private readonly ObjectService $objects,
		private readonly FormPayloadFindings $findings,
		private readonly FormIdempotencyStore $keys,
		private readonly FormUploadStore $uploads,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Submit one form into one destination.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination    The resolved destination pair.
	 * @param array<string, mixed>|null               $mapping        The form's mapping; null takes the payload as the object.
	 * @param array<string, mixed>                    $payload        What the submitter filled in.
	 * @param IUser|null                              $subject        The signed-in subject; null for an anonymous submit.
	 * @param string|null                             $idempotencyKey The Idempotency-Key, when the caller sent one.
	 * @param string                                  $scope          The form id the key and upload tokens belong to.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function submit(
		array $destination,
		?array $mapping,
		array $payload,
		?IUser $subject = null,
		?string $idempotencyKey = null,
		string $scope = '',
	): array {
		return $this->submitAll(
			writes: [array_merge($destination, ['mapping' => $mapping])],
			payload: $payload,
			subject: $subject,
			idempotencyKey: $idempotencyKey,
			scope: $scope
		);
	}//end submit()

	/**
	 * Submit one payload into several destinations, all or none.
	 *
	 * Each write is `{ register, schema, mapping?, as? }`. A fixed value
	 * `{ "$write": "<as>" }` is the id of an earlier write of this submit.
	 * The answer's `reference`, `id`, `receivedAt` and `confirmation` are
	 * the first write's; `objects` lists every write.
	 *
	 * @param array<int, array<string, mixed>> $writes         The writes, in order.
	 * @param array<string, mixed>             $payload        What the submitter filled in.
	 * @param IUser|null                       $subject        The signed-in subject; null for anonymous.
	 * @param string|null                      $idempotencyKey The Idempotency-Key, when sent.
	 * @param string                           $scope          The form id the key and tokens belong to.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-writing-several-objects-must-commit-all-or-none
	 */
	public function submitAll(
		array $writes,
		array $payload,
		?IUser $subject = null,
		?string $idempotencyKey = null,
		string $scope = '',
	): array {
		$key = trim((string)$idempotencyKey);
		$keyScope = $this->keyScope(scope: $scope, writes: $writes);
		if ($key !== '') {
			$remembered = $this->keys->find(scope: $keyScope, key: $key);
			if ($remembered !== null) {
				/*
				 * @var array<string, mixed> $remembered
				 */
				return $remembered;
			}
		}

		$plans = $this->plan(writes: $writes, payload: $payload, scope: $scope);
		$this->validateAll(plans: $plans);
		$created = $this->writeAll(plans: $plans, subject: $subject);

		$this->uploads->claim(tokens: array_merge(...array_map(static fn (array $plan): array => $plan['tokens'], $plans)));
		$answer = $this->answer(created: $created);
		if ($key !== '') {
			$this->keys->remember(scope: $keyScope, key: $key, response: $answer);
		}

		return $answer;
	}//end submitAll()

	/**
	 * Resolve each write's destination and map the payload onto it.
	 *
	 * @param array<int, array<string, mixed>> $writes  The writes.
	 * @param array<string, mixed>             $payload The payload.
	 * @param string                           $scope   The form id upload tokens belong to.
	 *
	 * @return array<int, array<string, mixed>> The plans: as, named, register, schema, mapping, object, files, tokens.
	 *
	 * @throws FormSubmitRefusedException 404 when a destination does not exist; 422 for a bad upload token.
	 */
	private function plan(array $writes, array $payload, string $scope): array {
		$plans = [];
		foreach (array_values($writes) as $index => $write) {
			$writeName = trim((string)($write['as'] ?? ''));
			$named = $writeName !== '';
			if ($named === false) {
				$writeName = 'write' . $index;
			}

			$mapping = null;
			if (is_array($write['mapping'] ?? null) === true) {
				$mapping = $write['mapping'];
			}

			[$object, $files, $tokens] = $this->mapPayload(mapping: $mapping, payload: $payload, scope: $scope);
			$plans[] = [
				'as' => $writeName,
				'named' => $named && count($writes) > 1,
				'register' => $this->register(reference: $write['register'] ?? null),
				'schema' => $this->schema(reference: $write['schema'] ?? null),
				'mapping' => $mapping,
				'object' => $object,
				'files' => $files,
				'tokens' => $tokens,
			];
		}

		if ($plans === []) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This form has no destination.'), status: 404);
		}

		return $plans;
	}//end plan()

	/**
	 * The object a mapping makes of a payload, plus the uploads it claims.
	 *
	 * Only mapped fields reach the object: the submitter does not choose which
	 * properties a form writes. Without a mapping the payload is the object,
	 * minus `_` control keys and `@` metadata, as the objects endpoint does.
	 *
	 * @param array<string, mixed>|null $mapping The mapping.
	 * @param array<string, mixed>      $payload The payload.
	 * @param string                    $scope   The form id upload tokens belong to.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: array<int, string>} Object, uploads, tokens.
	 *
	 * @throws FormSubmitRefusedException 422 for an unknown, expired or misdirected upload token.
	 */
	private function mapPayload(?array $mapping, array $payload, string $scope): array {
		$object = [];
		$files = [];
		$tokens = [];
		foreach ($this->pairsOf(mapping: $mapping, payload: $payload) as $property => $value) {
			$token = $this->tokenOf(value: $value);
			if ($token === null) {
				$object[$property] = $value;
				continue;
			}

			$file = $this->uploads->materialise(formId: $scope, token: $token);
			if ($file['property'] !== $property) {
				$message = $this->l10n->t('An uploaded file was sent for another question. Please add it again.');
				throw new FormSubmitRefusedException(
					message: $message,
					status: 422,
					findings: [['property' => $property, 'code' => 'upload-token-unknown', 'message' => $message]]
				);
			}

			unset($file['property']);
			$files[$property] = $file;
			$tokens[] = $token;
		}

		$fixed = ($mapping['fixed'] ?? []);
		if (is_array($fixed) === true) {
			foreach ($fixed as $property => $value) {
				$object[(string)$property] = $value;
			}
		}

		return [$object, $files, $tokens];
	}//end mapPayload()

	/**
	 * Property => value: the mapped fields, or without a mapping the payload minus control and metadata keys.
	 *
	 * @param array<string, mixed>|null $mapping The mapping.
	 * @param array<string, mixed>      $payload The payload.
	 *
	 * @return array<string, mixed> The pairs.
	 */
	private function pairsOf(?array $mapping, array $payload): array {
		$pairs = [];
		if ($mapping === null) {
			foreach ($payload as $name => $value) {
				$name = (string)$name;
				if (str_starts_with($name, '_') === false && str_starts_with($name, '@') === false) {
					$pairs[$name] = $value;
				}
			}

			return $pairs;
		}

		foreach ((array)($mapping['fields'] ?? []) as $field) {
			$name = (string)($field['field'] ?? '');
			$property = (string)($field['property'] ?? '');
			if ($property !== '' && array_key_exists($name, $payload) === true) {
				$pairs[$property] = $payload[$name];
			}
		}

		return $pairs;
	}//end pairsOf()

	/**
	 * The upload token a payload value carries, or null.
	 *
	 * @param mixed $value The payload value.
	 *
	 * @return string|null The token.
	 */
	private function tokenOf(mixed $value): ?string {
		if (is_array($value) === true && is_string($value['uploadToken'] ?? null) === true && count($value) === 1) {
			return $value['uploadToken'];
		}

		return null;
	}//end tokenOf()

	/**
	 * Validate every write with its destination's full validator before the first write.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 422 with every finding of every write.
	 */
	private function validateAll(array $plans): void {
		$findings = [];
		foreach ($plans as $plan) {
			$write = null;
			if ($plan['named'] === true) {
				$write = $plan['as'];
			}

			$object = $this->resolveWriteReferences(object: $plan['object'], ids: [], pending: true);
			try {
				$result = $this->validator->validateObject(
					object: $object,
					schema: $plan['schema'],
					notSupplied: $this->serverFilled(schema: $plan['schema'], object: $object)
				);
				array_push($findings, ...$this->findings->fromResult(result: $result, write: $write));
			} catch (CustomValidationException $exception) {
				array_push($findings, ...$this->findings->fromThrowable(exception: $exception, write: $write));
			}
		}

		if ($findings !== []) {
			throw new FormSubmitRefusedException(
				message: $this->l10n->t('The form was not accepted. Please check the answers marked below.'),
				status: 422,
				findings: $findings
			);
		}
	}//end validateAll()

	/**
	 * The properties the server fills, excused from `required` while the payload is judged.
	 *
	 * A property with a default, a computation or the serverSet marker is
	 * filled on save or by a creating listener, so a payload without it is
	 * complete. The build-time validator excuses the same set.
	 *
	 * @param Schema               $schema The destination schema.
	 * @param array<string, mixed> $object The mapped object.
	 *
	 * @return array<string, string> Property => reason, for ValidateObject's notSupplied.
	 */
	private function serverFilled(Schema $schema, array $object): array {
		$excused = [];
		foreach ($schema->getProperties() as $name => $property) {
			if (is_array($property) === false || array_key_exists((string)$name, $object) === true) {
				continue;
			}

			$filled = array_key_exists('default', $property) === true
				|| isset($property['computed']) === true
				|| FormDestinationValidator::marker(property: $property, name: FormDestinationValidator::MARKER_SERVER_SET);
			if ($filled === true) {
				$excused[(string)$name] = 'server-set';
			}
		}

		return $excused;
	}//end serverFilled()

	/**
	 * Write in order; on a refusal, delete what this submit already wrote, then refuse.
	 *
	 * @param array<int, array<string, mixed>> $plans   The plans.
	 * @param IUser|null                       $subject The subject.
	 *
	 * @return array<int, array{plan: array<string, mixed>, object: ObjectEntity}> The created objects, in order.
	 *
	 * @throws FormSubmitRefusedException 422, 403 or 503 after compensation.
	 */
	private function writeAll(array $plans, ?IUser $subject): array {
		$created = [];
		$ids = [];
		foreach ($plans as $plan) {
			$files = null;
			if ($plan['files'] !== []) {
				$files = $plan['files'];
			}

			try {
				$entity = $this->objects->saveObject(
					object: $this->resolveWriteReferences(object: $plan['object'], ids: $ids, pending: false),
					register: $plan['register'],
					schema: $plan['schema'],
					_rbac: true,
					_multitenancy: true,
					uploadedFiles: $files,
					currentUser: $subject,
					_unowned: $subject === null
				);
			} catch (Throwable $exception) {
				$this->compensate(created: $created, failedAt: $plan['as'], reason: $exception);
				throw $this->refusalFor(exception: $exception, plan: $plan);
			}

			$created[] = ['plan' => $plan, 'object' => $entity];
			$ids[$plan['as']] = (string)$entity->getUuid();
		}

		return $created;
	}//end writeAll()

	/**
	 * Replace `{ "$write": "<as>" }` values with that write's id (or a placeholder while validating).
	 *
	 * @param array<string, mixed>  $object  The object.
	 * @param array<string, string> $ids     Ids of earlier writes, by `as`.
	 * @param bool                  $pending True while validating, before any write.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws FormSubmitRefusedException 422 when a reference names no earlier write.
	 */
	private function resolveWriteReferences(array $object, array $ids, bool $pending): array {
		foreach ($object as $property => $value) {
			if (is_array($value) === false || count($value) !== 1 || is_string($value['$write'] ?? null) === false) {
				continue;
			}

			if ($pending === true) {
				$object[$property] = self::PENDING_ID;
				continue;
			}

			if (isset($ids[$value['$write']]) === false) {
				$message = $this->l10n->t('Write "%1$s" is referenced before it is made.', [$value['$write']]);
				throw new FormSubmitRefusedException(
					message: $message,
					status: 422,
					findings: [['property' => (string)$property, 'code' => 'write-unknown', 'message' => $message]]
				);
			}

			$object[$property] = $ids[$value['$write']];
		}

		return $object;
	}//end resolveWriteReferences()

	/**
	 * Delete the writes this submit made before a later one was refused, and record it.
	 *
	 * @param array<int, array{plan: array<string, mixed>, object: ObjectEntity}> $created  The writes made.
	 * @param string                                                             $failedAt The write that was refused.
	 * @param Throwable                                                          $reason   Why.
	 *
	 * @return void
	 */
	private function compensate(array $created, string $failedAt, Throwable $reason): void {
		foreach (array_reverse($created) as $done) {
			$uuid = (string)$done['object']->getUuid();
			try {
				// Permanent: a submit that did not happen leaves no tombstone that
				// holds a unique key against the resident's retry. The audit trail
				// keeps both the create and this delete.
				$this->objects->deleteObject(
					uuid: $uuid,
					register: $done['plan']['register'],
					schema: $done['plan']['schema'],
					_rbac: false,
					_multitenancy: false,
					permanent: true
				);
			} catch (Throwable $exception) {
				$this->logger->error(
					message: '[FormSubmitService] Compensation failed: an earlier write of a refused submit could not be deleted',
					context: ['uuid' => $uuid, 'failedAt' => $failedAt, 'exception' => $exception->getMessage()]
				);
				continue;
			}

			$this->logger->warning(
				message: '[FormSubmitService] Compensated: deleted an earlier write of a refused submit',
				context: ['uuid' => $uuid, 'write' => $done['plan']['as'], 'failedAt' => $failedAt, 'reason' => $reason->getMessage()]
			);
		}//end foreach
	}//end compensate()

	/**
	 * The refusal a failed write answers with.
	 *
	 * @param Throwable            $exception The save's exception.
	 * @param array<string, mixed> $plan      The write that failed.
	 *
	 * @return FormSubmitRefusedException 422 for the payload, 403 for permission, 503 otherwise.
	 */
	private function refusalFor(Throwable $exception, array $plan): FormSubmitRefusedException {
		if ($exception instanceof FormSubmitRefusedException) {
			return $exception;
		}

		$write = null;
		if ($plan['named'] === true) {
			$write = $plan['as'];
		}

		if ($this->findings->isPayloadRefusal(exception: $exception) === true) {
			return new FormSubmitRefusedException(
				message: $this->l10n->t('The form was not accepted. Please check the answers marked below.'),
				status: 422,
				findings: $this->findings->fromThrowable(exception: $exception, write: $write)
			);
		}

		if ($exception instanceof NotAuthorizedException) {
			return new FormSubmitRefusedException(
				message: $this->l10n->t('You may not send this form.'),
				status: 403,
				findings: [['property' => '', 'code' => 'not-allowed', 'message' => $this->l10n->t('You may not send this form.')]]
			);
		}

		$this->logger->error(
			message: '[FormSubmitService] A submit could not reach its destination',
			context: ['schema' => $plan['schema']->getSlug(), 'exception' => $exception->getMessage()]
		);

		// Q3: refuse with "try again later"; the answers stay in the form. A
		// queue would hand the resident a receipt for something that may never
		// arrive, which is the defect decision 179 removes.
		return new FormSubmitRefusedException(
			message: $this->l10n->t('Your form could not be sent right now. Your answers are kept; please try again later.'),
			status: 503
		);
	}//end refusalFor()

	/**
	 * The answer: the first write's reference, id, received moment and confirmation; every write listed.
	 *
	 * @param array<int, array{plan: array<string, mixed>, object: ObjectEntity}> $created The writes.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 */
	private function answer(array $created): array {
		$objects = [];
		$first = null;
		foreach ($created as $done) {
			// Re-read: a creating listener has run by now, and a created listener
			// that saves again is visible only on the stored row.
			$schema = $done['plan']['schema'];
			$entity = ($this->objects->find(
				id: (string)$done['object']->getUuid(),
				register: $done['plan']['register'],
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_audit: false
			) ?? $done['object']);
			$data = $entity->getObject();
			$entry = [
				'as' => $done['plan']['as'],
				'id' => (string)$entity->getUuid(),
				'reference' => $this->referenceOf(schema: $schema, data: $data, entity: $entity),
				'register' => $done['plan']['register']->getSlug(),
				'schema' => $schema->getSlug(),
			];
			$objects[] = $entry;
			if ($first === null) {
				$first = ['entry' => $entry, 'entity' => $entity, 'data' => $data, 'schema' => $schema];
			}
		}//end foreach

		$confirmation = [];
		foreach (FormDestinationValidator::markedProperties(schema: $first['schema'], name: FormDestinationValidator::MARKER_CONFIRMATION) as $property) {
			if (array_key_exists($property, $first['data']) === true) {
				$confirmation[$property] = $first['data'][$property];
			}
		}

		$receivedAt = null;
		$created = $first['entity']->getCreated();
		if ($created instanceof DateTimeInterface) {
			$receivedAt = $created->format(DateTimeInterface::ATOM);
		}

		return [
			'reference' => $first['entry']['reference'],
			'id' => $first['entry']['id'],
			'receivedAt' => $receivedAt,
			'confirmation' => $confirmation,
			'objects' => $objects,
		];
	}//end answer()

	/**
	 * The human-readable reference: the property marked `reference`, else the uuid.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $data   The stored object.
	 * @param ObjectEntity         $entity The entity.
	 *
	 * @return string The reference.
	 */
	private function referenceOf(Schema $schema, array $data, ObjectEntity $entity): string {
		foreach (FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_REFERENCE) as $property) {
			if (is_scalar($data[$property] ?? null) === true && (string)$data[$property] !== '') {
				return (string)$data[$property];
			}
		}

		return (string)$entity->getUuid();
	}//end referenceOf()

	/**
	 * The scope an idempotency key belongs to: the form, else the first destination.
	 *
	 * @param string                           $scope  The form id.
	 * @param array<int, array<string, mixed>> $writes The writes.
	 *
	 * @return string The scope.
	 */
	private function keyScope(string $scope, array $writes): string {
		if ($scope !== '') {
			return $scope;
		}

		$first = ($writes[0] ?? []);

		return 'destination:' . (string)json_encode([$first['register'] ?? null, $first['schema'] ?? null]);
	}//end keyScope()

	/**
	 * A destination's register.
	 *
	 * @param mixed $reference Id, uuid or slug.
	 *
	 * @return Register The register.
	 *
	 * @throws FormSubmitRefusedException 404 when it does not exist.
	 */
	private function register(mixed $reference): Register {
		if ($reference instanceof Register) {
			return $reference;
		}

		try {
			// Multitenancy and RBAC off: this resolves WHERE the form writes,
			// fixed by its author. Whether the subject may write there is the
			// save's question, asked under RBAC.
			return $this->registers->find((string)$reference, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('The place this form sends to does not exist.'), status: 404);
		}
	}//end register()

	/**
	 * A destination's schema.
	 *
	 * @param mixed $reference Id, uuid or slug.
	 *
	 * @return Schema The schema.
	 *
	 * @throws FormSubmitRefusedException 404 when it does not exist.
	 */
	private function schema(mixed $reference): Schema {
		if ($reference instanceof Schema) {
			return $reference;
		}

		try {
			return $this->schemas->find((string)$reference, _multitenancy: false);
		} catch (Throwable) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('The place this form sends to does not exist.'), status: 404);
		}
	}//end schema()
}//end class
