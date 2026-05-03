<?php

/**
 * OasRequestValidator — runtime request-body validation against an OAS schema.
 *
 * Wraps `opis/json-schema` to validate decoded request bodies against the
 * OAS operation's `requestBody.content."application/json".schema`. The
 * primitive is pure-PHP (no NC framework dep) so it can be unit-tested in
 * isolation; the NC middleware that wires it into `before-controller` is
 * a thin adapter on top.
 *
 * Validation errors are emitted in a flat list of `{ path, message }`
 * tuples ready to be wrapped by `ProblemDetailsBuilder::validationFailed`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/oas-validation/tasks.md "Request/Response Validation Against OAS Schema"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use Opis\JsonSchema\Validator;

/**
 * Validates a request body against a JSON-Schema.
 */
class OasRequestValidator
{
    /**
     * Validate `$body` against `$schema`. Returns the list of errors —
     * empty array on success. Each error is `{ path, message }`.
     *
     * @param mixed $body   The decoded request body (array / scalar / null).
     * @param array $schema The JSON-Schema to validate against (decoded).
     *
     * @return array<int, array{path: string, message: string}>
     */
    public function validate(mixed $body, array $schema): array
    {
        // The opis/json-schema library operates on object-shaped values; convert
        // the schema + body via JSON round-trip so we get the right
        // PHP shape (stdClass for objects, array for lists).
        $schemaJson = (string) json_encode($schema);
        $schemaObj  = json_decode($schemaJson);
        $bodyJson   = (string) json_encode($body);
        $bodyObj    = json_decode($bodyJson);

        $validator = new Validator();
        $result    = $validator->validate(data: $bodyObj, schema: $schemaObj);
        if ($result->isValid() === true) {
            return [];
        }

        $errors = [];
        $error  = $result->error();
        if ($error === null) {
            return [];
        }

        $this->collectErrors(error: $error, errors: $errors);
        return $errors;

    }//end validate()

    /**
     * Test whether `$body` validates against `$schema`.
     *
     * @param mixed $body   The decoded request body.
     * @param array $schema The JSON-Schema.
     *
     * @return bool
     */
    public function isValid(mixed $body, array $schema): bool
    {
        return ($this->validate(body: $body, schema: $schema) === []);

    }//end isValid()

    /**
     * Recursively walk the opis error tree and flatten into the
     * `{ path, message }` shape consumers expect.
     *
     * @param mixed $error  The opis ValidationError or sub-error.
     * @param array $errors The accumulator (mutated by reference).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Defensive guards against opis
     *                                               ValidationError shape variability
     *                                               across versions; each method_exists
     *                                               branch is one independent fallback.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: each fallback is one branch.
     */
    private function collectErrors(mixed $error, array &$errors): void
    {
        if (is_object($error) === false) {
            return;
        }

        $path = '';
        if (method_exists($error, 'data') === true) {
            $data = $error->data();
            if (is_object($data) === true && method_exists($data, 'fullPath') === true) {
                $path = '/'.implode('/', (array) $data->fullPath());
            }
        }

        $message = '';
        if (method_exists($error, 'keyword') === true) {
            $message = (string) $error->keyword();
        }

        if (method_exists($error, 'message') === true) {
            $msg = $error->message();
            if (is_string($msg) === true && $msg !== '') {
                $message = $msg;
            }
        }

        $errors[] = [
            'path'    => ($path !== '' ? $path : '/'),
            'message' => ($message !== '' ? $message : 'value does not validate'),
        ];

        if (method_exists($error, 'subErrors') === true) {
            foreach ((array) $error->subErrors() as $sub) {
                $this->collectErrors(error: $sub, errors: $errors);
            }
        }

    }//end collectErrors()
}//end class
