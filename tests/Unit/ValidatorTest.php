<?php
declare(strict_types=1);

use App\Core\Validator;

suite('Validator', function () {
    it('returns only declared fields, trimmed, with empty strings as null', function () {
        $out = Validator::make(
            ['name' => '  Asha  ', 'note' => '   ', 'extra' => 'dropped'],
            ['name' => 'required|max:50', 'note' => 'nullable|max:10']
        );
        eq(['name' => 'Asha', 'note' => null], $out);
    });

    it('reports a missing required field with a readable label', function () {
        $e = throwsHttp(422, fn () => Validator::make([], ['full_name' => 'required']), 'full_name');
        eq('Full name is required.', $e->errors['full_name']);
        $e = throwsHttp(422, fn () => Validator::make([], ['dob' => 'required'], ['dob' => 'Date of birth']), 'dob');
        eq('Date of birth is required.', $e->errors['dob']);
    });

    it('collects errors for every invalid field at once', function () {
        $e = throwsHttp(422, fn () => Validator::make(
            ['email' => 'nope', 'amount' => 'x'],
            ['name' => 'required', 'email' => 'email', 'amount' => 'money']
        ));
        eq(['name', 'email', 'amount'], array_keys($e->errors));
    });

    it('rejects arrays where a scalar is expected', function () {
        throwsHttp(422, fn () => Validator::make(['name' => ['a', 'b']], ['name' => 'nullable']), 'name');
    });

    it('casts int fields and normalizes money fields', function () {
        $out = Validator::make(['n' => '42', 'amt' => '100.5'], ['n' => 'required|int', 'amt' => 'required|money']);
        eq(42, $out['n']);
        eq('100.50', $out['amt']);
    });

    it('validates money format strictly', function () {
        foreach (['100', '0.5', '999999999.99'] as $v) {
            Validator::make(['a' => $v], ['a' => 'money']);
        }
        foreach (['-5', '1.234', '1e3', '1,000', '1000000000', '12345678901'] as $v) {
            throwsHttp(422, fn () => Validator::make(['a' => $v], ['a' => 'money']), 'a');
        }
    });

    it('enforces min_value and max_value', function () {
        throwsHttp(422, fn () => Validator::make(['a' => '0'], ['a' => 'money|min_value:0.01']), 'a');
        eq('0.01', Validator::make(['a' => '0.01'], ['a' => 'money|min_value:0.01'])['a']);
        throwsHttp(422, fn () => Validator::make(['s' => '9'], ['s' => 'int|max_value:8']), 's');
    });

    it('enforces max and min length in characters, not bytes', function () {
        eq('ééééé', Validator::make(['x' => 'ééééé'], ['x' => 'max:5'])['x']);
        throwsHttp(422, fn () => Validator::make(['x' => 'éééééé'], ['x' => 'max:5']), 'x');
        throwsHttp(422, fn () => Validator::make(['x' => 'ab'], ['x' => 'min:3']), 'x');
    });

    it('validates allowed values with in:', function () {
        eq('Male', Validator::make(['g' => 'Male'], ['g' => 'in:Male,Female,Other'])['g']);
        throwsHttp(422, fn () => Validator::make(['g' => 'male'], ['g' => 'in:Male,Female,Other']), 'g');
    });

    it('validates emails', function () {
        Validator::make(['e' => 'a.b@college.edu'], ['e' => 'email']);
        throwsHttp(422, fn () => Validator::make(['e' => 'a@'], ['e' => 'email']), 'e');
        throwsHttp(422, fn () => Validator::make(['e' => str_repeat('a', 145) . '@x.com'], ['e' => 'email']), 'e');
    });

    it('validates phone numbers', function () {
        foreach (['+91 98765 43210', '080-1234567', '9876543210'] as $p) {
            Validator::make(['p' => $p], ['p' => 'phone']);
        }
        foreach (['12345', 'phone', '+91 98765 4321O'] as $p) {
            throwsHttp(422, fn () => Validator::make(['p' => $p], ['p' => 'phone']), 'p');
        }
    });

    it('validates dates including leap years and range', function () {
        ok(Validator::isDate('2024-02-29'));
        no(Validator::isDate('2023-02-29'));
        no(Validator::isDate('2024-13-01'));
        no(Validator::isDate('2024-1-01'));
        no(Validator::isDate('1899-12-31'));
        no(Validator::isDate('2026-11-25 10:00'));
        throwsHttp(422, fn () => Validator::make(['d' => '31/12/2024'], ['d' => 'date']), 'd');
    });

    it('rejects future dates for before_today', function () {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        throwsHttp(422, fn () => Validator::make(['d' => $tomorrow], ['d' => 'date|before_today']), 'd');
        eq(date('Y-m-d'), Validator::make(['d' => date('Y-m-d')], ['d' => 'date|before_today'])['d']);
    });

    it('validates months, codes, usernames and booleans', function () {
        Validator::make(['m' => '2026-11'], ['m' => 'month']);
        throwsHttp(422, fn () => Validator::make(['m' => '2026-13'], ['m' => 'month']), 'm');
        Validator::make(['c' => 'BT-CSE/2026_A.1'], ['c' => 'code']);
        throwsHttp(422, fn () => Validator::make(['c' => 'BT CSE'], ['c' => 'code']), 'c');
        Validator::make(['u' => 'john.doe-1'], ['u' => 'username']);
        throwsHttp(422, fn () => Validator::make(['u' => 'jo'], ['u' => 'username']), 'u');
        throwsHttp(422, fn () => Validator::make(['u' => "john'--"], ['u' => 'username']), 'u');
        eq('1', Validator::make(['b' => true], ['b' => 'bool'])['b']);
        eq('0', Validator::make(['b' => false], ['b' => 'bool'])['b']);
        throwsHttp(422, fn () => Validator::make(['b' => 'yes'], ['b' => 'bool']), 'b');
    });

    it('requires strong passwords', function () {
        eq(null, Validator::passwordError('Admin@12345'));
        contains('at least 8', (string) Validator::passwordError('Ab1@'));
        contains('upper and lower', (string) Validator::passwordError('admin@12345'));
        contains('upper and lower', (string) Validator::passwordError('ADMIN@12345'));
        contains('upper and lower', (string) Validator::passwordError('Admin12345'));
        contains('upper and lower', (string) Validator::passwordError('Admin@abcde'));
        contains('128', (string) Validator::passwordError('Aa1@' . str_repeat('x', 130)));
    });
});
