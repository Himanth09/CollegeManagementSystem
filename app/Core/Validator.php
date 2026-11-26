<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal rule-based validator.
 *
 *   $data = Validator::make($input, [
 *       'full_name' => 'required|max:150',
 *       'email'     => 'nullable|email',
 *       'amount'    => 'required|money|min_value:0.01',
 *       'gender'    => 'required|in:Male,Female,Other',
 *   ]);
 *
 * Returns only the validated keys (trimmed strings, null for empty values);
 * throws HttpException 422 with per-field messages on failure.
 */
final class Validator
{
    public static function make(array $input, array $rules, array $labels = []): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $raw = $input[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            if (is_array($value)) {
                $errors[$field] = "$label is invalid.";
                continue;
            }
            $value = ($value === '' ) ? null : $value;
            $rulesList = explode('|', $ruleString);

            if ($value === null) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = "$label is required.";
                }
                $out[$field] = null;
                continue;
            }
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $msg = self::check($name, $arg, $value, $label);
                if ($msg !== null) {
                    $errors[$field] = $msg;
                    break;
                }
            }
            if (!isset($errors[$field])) {
                if (in_array('int', $rulesList, true)) {
                    $value = (int) $value;
                } elseif (in_array('money', $rulesList, true)) {
                    $value = Money::normalize($value);
                }
                $out[$field] = $value;
            }
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }
        return $out;
    }

    private static function check(string $rule, ?string $arg, string $v, string $label): ?string
    {
        return match ($rule) {
            'required', 'nullable', '' => null,
            'max'       => mb_strlen($v) > (int) $arg ? "$label must not exceed $arg characters." : null,
            'min'       => mb_strlen($v) < (int) $arg ? "$label must be at least $arg characters." : null,
            'email'     => filter_var($v, FILTER_VALIDATE_EMAIL) === false || mb_strlen($v) > 150 ? "$label must be a valid email address." : null,
            'phone'     => !preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $v) ? "$label must be a valid phone number." : null,
            'date'      => !self::isDate($v) ? "$label must be a valid date (YYYY-MM-DD)." : null,
            'month'     => !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? "$label must be a valid month (YYYY-MM)." : null,
            'before_today' => $v > date('Y-m-d') ? "$label cannot be in the future." : null,
            'int'       => !preg_match('/^\d+$/', $v) ? "$label must be a whole number." : null,
            // Up to 99,99,99,999.99: even 8 fee components added together stay inside DECIMAL(12,2).
            'money'     => !preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v) ? "$label must be a valid amount (up to 99,99,99,999.99, 2 decimals)." : null,
            'min_value' => (float) $v < (float) $arg ? "$label must be at least $arg." : null,
            'max_value' => (float) $v > (float) $arg ? "$label must not exceed $arg." : null,
            'in'        => !in_array($v, explode(',', (string) $arg), true) ? "$label has an invalid value." : null,
            'code'      => !preg_match('/^[A-Za-z0-9\-\/_.]+$/', $v) ? "$label may contain only letters, numbers and - / _ ." : null,
            'username'  => !preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $v) ? "$label must be 3-60 letters, numbers, dot, dash or underscore." : null,
            'bool'      => !in_array($v, ['0', '1', 'true', 'false'], true) ? "$label must be yes or no." : null,
            default     => null,
        };
    }

    public static function isDate(string $v): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) && $y >= 1900 && $y <= 2100;
    }

    /** Validates a strong password; returns an error message or null. */
    public static function passwordError(string $password): ?string
    {
        $min = (int) Config::get('security.password_min_length', 8);
        if (mb_strlen($password) < $min) {
            return "Password must be at least $min characters.";
        }
        if (mb_strlen($password) > 128) {
            return 'Password must not exceed 128 characters.';
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must include upper and lower case letters, a number and a symbol.';
        }
        return null;
    }
}
