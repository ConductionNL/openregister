<?php

/**
 * SchemaMigrationPlanner — pure transform engine for object migration plans.
 *
 * Applies an ordered list of declarative transforms to a single object's
 * data array, in memory, with no database or framework dependencies. The
 * supported transforms are `rename`, `setDefault`, `cast`, `drop` and
 * `compute`; `compute` delegates template rendering to an injected
 * callable (defaulting to a minimal `{{ field }}` substitution) so the
 * planner can be unit tested without the Twig/MappingService stack while
 * the production wiring injects the real templating engine.
 *
 * The planner is the substrate for both preview (apply to a sample, never
 * persist) and execution (apply per object, then persist through the save
 * pipeline). It also validates a plan's structure so malformed transforms
 * are rejected before any object is touched — the first line of the
 * no-data-loss guard.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Pure transform engine for declarative migration plans.
 */
class SchemaMigrationPlanner
{

    /**
     * The set of recognised transform operations.
     *
     * @var array<int, string>
     */
    public const TRANSFORMS = [
        'rename',
        'setDefault',
        'cast',
        'drop',
        'compute',
    ];

    /**
     * Optional template renderer for the `compute` transform.
     *
     * Signature: fn(string $template, array<string,mixed> $context): string.
     * When null a minimal `{{ field }}` substitution is used.
     *
     * @var callable|null
     */
    private $templateRenderer;


    /**
     * Constructor.
     *
     * @param callable|null $templateRenderer Optional template renderer for
     *                                         the `compute` transform.
     */
    public function __construct(?callable $templateRenderer=null)
    {
        $this->templateRenderer = $templateRenderer;

    }//end __construct()


    /**
     * Validate a migration plan's structure.
     *
     * Returns a list of human-readable problems; an empty list means the
     * plan is structurally valid. Unknown transforms or missing required
     * fields are reported so the caller can refuse the plan (HTTP 422)
     * before any object is loaded.
     *
     * @param array<int, array<string, mixed>> $plan The transform chain.
     *
     * @return array<int, string> The list of validation problems (empty when valid).
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function validatePlan(array $plan): array
    {
        $problems = [];

        if (count($plan) === 0) {
            $problems[] = 'Migration plan must contain at least one transform.';
            return $problems;
        }

        foreach ($plan as $index => $step) {
            if (is_array($step) === false || isset($step['op']) === false) {
                $problems[] = sprintf('Transform #%d is missing an "op" key.', $index);
                continue;
            }

            $op = $step['op'];
            if (in_array($op, self::TRANSFORMS, true) === false) {
                $problems[] = sprintf('Transform #%d has unknown op "%s".', $index, (string) $op);
                continue;
            }

            switch ($op) {
                case 'rename':
                    if (empty($step['from']) === true || empty($step['to']) === true) {
                        $problems[] = sprintf('rename transform #%d requires "from" and "to".', $index);
                    }
                    break;
                case 'setDefault':
                    if (empty($step['field']) === true || array_key_exists('value', $step) === false) {
                        $problems[] = sprintf('setDefault transform #%d requires "field" and "value".', $index);
                    }
                    break;
                case 'cast':
                    if (empty($step['field']) === true || empty($step['to']) === true) {
                        $problems[] = sprintf('cast transform #%d requires "field" and "to".', $index);
                    } else if (in_array($step['to'], ['string', 'integer', 'number', 'boolean', 'date'], true) === false) {
                        $problems[] = sprintf('cast transform #%d has unsupported target type "%s".', $index, (string) $step['to']);
                    }
                    break;
                case 'drop':
                    if (empty($step['field']) === true) {
                        $problems[] = sprintf('drop transform #%d requires "field".', $index);
                    }
                    break;
                case 'compute':
                    if (empty($step['field']) === true || array_key_exists('template', $step) === false) {
                        $problems[] = sprintf('compute transform #%d requires "field" and "template".', $index);
                    }
                    break;
            }//end switch
        }//end foreach

        return $problems;

    }//end validatePlan()


    /**
     * Apply a transform chain to one object's data.
     *
     * The input data is never mutated; a transformed copy is returned in
     * the result. An uncastable value (or any transform error) yields a
     * failed result with the original data preserved, so a failure can
     * never corrupt or partially write an object.
     *
     * @param array<string, mixed>             $data The object's current data.
     * @param array<int, array<string, mixed>> $plan The transform chain.
     *
     * @return MigrationPlanResult The transform outcome.
     *
     * @spec openspec/changes/schema-versioning-and-object-migration/specs/schema-migration/spec.md
     */
    public function apply(array $data, array $plan): MigrationPlanResult
    {
        $original = $data;
        $working  = $data;
        $applied  = [];

        foreach ($plan as $step) {
            $op = ($step['op'] ?? '');

            try {
                switch ($op) {
                    case 'rename':
                        $working   = $this->applyRename($working, (string) $step['from'], (string) $step['to']);
                        $applied[] = sprintf('rename %s -> %s', $step['from'], $step['to']);
                        break;
                    case 'setDefault':
                        $working   = $this->applySetDefault($working, (string) $step['field'], $step['value']);
                        $applied[] = sprintf('setDefault %s', $step['field']);
                        break;
                    case 'cast':
                        $working   = $this->applyCast($working, (string) $step['field'], (string) $step['to'], ($step['format'] ?? null));
                        $applied[] = sprintf('cast %s -> %s', $step['field'], $step['to']);
                        break;
                    case 'drop':
                        $working   = $this->applyDrop($working, (string) $step['field']);
                        $applied[] = sprintf('drop %s', $step['field']);
                        break;
                    case 'compute':
                        $working   = $this->applyCompute($working, (string) $step['field'], (string) $step['template']);
                        $applied[] = sprintf('compute %s', $step['field']);
                        break;
                    default:
                        return new MigrationPlanResult($original, false, sprintf('Unknown transform op "%s".', (string) $op), $applied);
                }//end switch
            } catch (\Throwable $e) {
                // Any transform failure leaves the ORIGINAL data intact.
                return new MigrationPlanResult($original, false, $e->getMessage(), $applied);
            }//end try
        }//end foreach

        $changed = ($working !== $original);

        return new MigrationPlanResult($working, $changed, null, $applied);

    }//end apply()


    /**
     * Apply a rename transform.
     *
     * @param array<string, mixed> $data The data.
     * @param string               $from Source property.
     * @param string               $to   Target property.
     *
     * @return array<string, mixed> The transformed data.
     */
    private function applyRename(array $data, string $from, string $to): array
    {
        if (array_key_exists($from, $data) === false) {
            return $data;
        }

        $data[$to] = $data[$from];
        unset($data[$from]);

        return $data;

    }//end applyRename()


    /**
     * Apply a setDefault transform (only when missing or null).
     *
     * @param array<string, mixed> $data  The data.
     * @param string               $field Target property.
     * @param mixed                $value The default value.
     *
     * @return array<string, mixed> The transformed data.
     */
    private function applySetDefault(array $data, string $field, $value): array
    {
        if (array_key_exists($field, $data) === false || $data[$field] === null) {
            $data[$field] = $value;
        }

        return $data;

    }//end applySetDefault()


    /**
     * Apply a cast transform.
     *
     * @param array<string, mixed> $data   The data.
     * @param string               $field  Target property.
     * @param string               $toType Target type.
     * @param string|null          $format Optional date format.
     *
     * @return array<string, mixed> The transformed data.
     *
     * @throws \RuntimeException When the value is uncastable.
     */
    private function applyCast(array $data, string $field, string $toType, ?string $format): array
    {
        if (array_key_exists($field, $data) === false || $data[$field] === null) {
            // Nothing to cast; leave as-is.
            return $data;
        }

        $value        = $data[$field];
        $data[$field] = $this->castValue($value, $toType, $format);

        return $data;

    }//end applyCast()


    /**
     * Cast a scalar value to the target type.
     *
     * @param mixed       $value  The value.
     * @param string      $toType Target type.
     * @param string|null $format Optional date format.
     *
     * @return mixed The cast value.
     *
     * @throws \RuntimeException When uncastable.
     */
    private function castValue($value, string $toType, ?string $format)
    {
        switch ($toType) {
            case 'string':
                if (is_scalar($value) === true) {
                    return (string) $value;
                }

                throw new \RuntimeException('Cannot cast non-scalar value to string.');

            case 'integer':
                if (is_bool($value) === true) {
                    return (int) $value;
                }

                if (is_numeric($value) === true) {
                    return (int) $value;
                }

                throw new \RuntimeException(sprintf('Cannot cast "%s" to integer.', $this->describe($value)));

            case 'number':
                if (is_bool($value) === true) {
                    return (float) $value;
                }

                if (is_numeric($value) === true) {
                    return (float) $value;
                }

                throw new \RuntimeException(sprintf('Cannot cast "%s" to number.', $this->describe($value)));

            case 'boolean':
                return $this->castBoolean($value);

            case 'date':
                return $this->castDate($value, $format);

            default:
                throw new \RuntimeException(sprintf('Unsupported cast target type "%s".', $toType));
        }//end switch

    }//end castValue()


    /**
     * Cast a value to a boolean using a strict allow-list.
     *
     * @param mixed $value The value.
     *
     * @return bool The boolean.
     *
     * @throws \RuntimeException When the value is not recognisably boolean.
     */
    private function castBoolean($value): bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        if (is_int($value) === true && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value) === true) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', '1', 'yes', 'on'], true) === true) {
                return true;
            }

            if (in_array($lower, ['false', '0', 'no', 'off'], true) === true) {
                return false;
            }
        }

        throw new \RuntimeException(sprintf('Cannot cast "%s" to boolean.', $this->describe($value)));

    }//end castBoolean()


    /**
     * Cast a value to an ISO-8601 date string.
     *
     * @param mixed       $value  The value.
     * @param string|null $format Optional input format.
     *
     * @return string The ISO-8601 date string.
     *
     * @throws \RuntimeException When the value cannot be parsed as a date.
     */
    private function castDate($value, ?string $format): string
    {
        if (is_string($value) === false && is_numeric($value) === false) {
            throw new \RuntimeException('Cannot cast non-string value to date.');
        }

        $stringValue = (string) $value;

        if ($format !== null && $format !== '') {
            $dt = \DateTime::createFromFormat($format, $stringValue);
            if ($dt === false) {
                throw new \RuntimeException(sprintf('Value "%s" does not match date format "%s".', $stringValue, $format));
            }

            return $dt->format(\DateTime::ATOM);
        }

        $timestamp = strtotime($stringValue);
        if ($timestamp === false) {
            throw new \RuntimeException(sprintf('Value "%s" is not a parseable date.', $stringValue));
        }

        return (new \DateTime('@'.$timestamp))->format(\DateTime::ATOM);

    }//end castDate()


    /**
     * Apply a drop transform.
     *
     * @param array<string, mixed> $data  The data.
     * @param string               $field Property to drop.
     *
     * @return array<string, mixed> The transformed data.
     */
    private function applyDrop(array $data, string $field): array
    {
        unset($data[$field]);

        return $data;

    }//end applyDrop()


    /**
     * Apply a compute transform via the template renderer.
     *
     * @param array<string, mixed> $data     The data.
     * @param string               $field    Target property.
     * @param string               $template The template string.
     *
     * @return array<string, mixed> The transformed data.
     */
    private function applyCompute(array $data, string $field, string $template): array
    {
        if ($this->templateRenderer !== null) {
            $data[$field] = ($this->templateRenderer)($template, $data);

            return $data;
        }

        $data[$field] = $this->renderSimpleTemplate($template, $data);

        return $data;

    }//end applyCompute()


    /**
     * Render a minimal `{{ field }}` template against the object's own data.
     *
     * Used as the default `compute` renderer so the planner is testable
     * without the Twig stack. Production wiring injects the MappingService
     * renderer for full template parity.
     *
     * @param string               $template The template.
     * @param array<string, mixed> $context  The object's data.
     *
     * @return string The rendered string.
     */
    private function renderSimpleTemplate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($context): string {
                $key = $matches[1];
                if (array_key_exists($key, $context) === false) {
                    return '';
                }

                $value = $context[$key];
                if (is_scalar($value) === true) {
                    return (string) $value;
                }

                return '';
            },
            $template
        );

    }//end renderSimpleTemplate()


    /**
     * Describe a value for an error message.
     *
     * @param mixed $value The value.
     *
     * @return string A short description.
     */
    private function describe($value): string
    {
        if (is_scalar($value) === true) {
            return (string) $value;
        }

        return gettype($value);

    }//end describe()


}//end class
