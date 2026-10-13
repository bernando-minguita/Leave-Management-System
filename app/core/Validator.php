<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tiny chained validation helper.
 *
 * Every rule records a user facing message keyed by field name so views can
 * render the message right beside the offending input.
 *
 *   $v = new Validator($_POST);
 *   $v->required('driver_name', 'Driver Name')
 *     ->maxLength('driver_name', 'Driver Name', 120)
 *     ->date('leave_start_date', 'Leave Start Date');
 *   if ($v->fails()) { ... }
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    private function fail(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function raw(string $field, $default = null)
    {
        return $this->data[$field] ?? $default;
    }

    public function text(string $field): string
    {
        $value = $this->data[$field] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    public function nullable(string $field): ?string
    {
        $value = $this->text($field);

        return $value === '' ? null : $value;
    }

    public function required(string $field, string $label): self
    {
        if ($this->text($field) === '') {
            $this->fail($field, $label . ' is required.');
        }

        return $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        $value = $this->text($field);

        if ($value !== '' && mb_strlen($value) > $max) {
            $this->fail($field, $label . ' may not be longer than ' . $max . ' characters.');
        }

        return $this;
    }

    /** Must be a parseable date when present (or always, when $required). */
    public function date(string $field, string $label, bool $required = false): self
    {
        $value = $this->text($field);

        if ($value === '') {
            if ($required) {
                $this->fail($field, $label . ' is required.');
            }
            return $this;
        }

        if (strtotime($value) === false) {
            $this->fail($field, $label . ' must be a valid date.');
        }

        return $this;
    }

    /** Normalise a date field to Y-m-d (null when empty/invalid). */
    public function dateValue(string $field): ?string
    {
        $value = $this->text($field);

        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /**
     * $field must be on/after $otherField (both must be present for the rule
     * to apply - presence is enforced separately by required()).
     */
    public function dateNotBefore(string $field, string $label, string $otherField, string $otherLabel): self
    {
        $a = $this->text($field);
        $b = $this->text($otherField);

        if ($a === '' || $b === '') {
            return $this;
        }

        $ta = strtotime($a);
        $tb = strtotime($b);

        if ($ta !== false && $tb !== false && $ta < $tb) {
            $this->fail($field, $label . ' cannot be before ' . $otherLabel . '.');
        }

        return $this;
    }

    /**
     * $field must be on/before $otherField.
     *
     * Used for "Last working date" which is normally the day the driver stops
     * working, i.e. on or before the leave start date - never after it.
     */
    public function dateNotAfter(string $field, string $label, string $otherField, string $otherLabel): self
    {
        $a = $this->text($field);
        $b = $this->text($otherField);

        if ($a === '' || $b === '') {
            return $this;
        }

        $ta = strtotime($a);
        $tb = strtotime($b);

        if ($ta !== false && $tb !== false && $ta > $tb) {
            $this->fail($field, $label . ' cannot be after ' . $otherLabel . '.');
        }

        return $this;
    }

    /**
     * $field must be strictly later than $otherField (equality fails).
     *
     * Used for "Expected return date", which has to fall after the last
     * working day, the leave start and the ticket date rather than merely
     * coincide with them.
     */
    public function dateAfter(string $field, string $label, string $otherField, string $otherLabel): self
    {
        $a = $this->text($field);
        $b = $this->text($otherField);

        if ($a === '' || $b === '') {
            return $this;
        }

        $ta = strtotime($a);
        $tb = strtotime($b);

        if ($ta !== false && $tb !== false && $ta <= $tb) {
            $this->fail($field, $label . ' must be after ' . $otherLabel . '.');
        }

        return $this;
    }

    /** @param string[] $allowed */
    public function in(string $field, string $label, array $allowed): self
    {
        $value = $this->text($field);

        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->fail($field, $label . ' is not a valid option.');
        }

        return $this;
    }

    /** Numeric id that must reference an existing row in $table. */
    public function existsIn(string $field, string $label, string $table): self
    {
        $value = $this->text($field);

        if ($value === '') {
            return $this;
        }

        if (ctype_digit($value) === false) {
            $this->fail($field, $label . ' is not a valid selection.');
            return $this;
        }

        $count = (int)Database::scalar(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE id = ?',
            [(int)$value]
        );

        if ($count === 0) {
            $this->fail($field, $label . ' no longer exists. Please choose another.');
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->text($field);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, $label . ' must be a valid email address.');
        }

        return $this;
    }

    public function matches(string $field, string $label, string $otherField, string $otherLabel): self
    {
        if ($this->text($field) !== $this->text($otherField)) {
            $this->fail($field, $label . ' does not match ' . $otherLabel . '.');
        }

        return $this;
    }

    /** Escape hatch: record an arbitrary failure against a field. */
    public function addError(string $field, string $message): self
    {
        $this->fail($field, $message);

        return $this;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
