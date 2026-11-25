<?php
declare(strict_types=1);

use App\Core\Money;

suite('Money', function () {
    it('parses strings, ints and floats to paise', function () {
        eq(123450, Money::toPaise('1234.50'));
        eq(123450, Money::toPaise('1,234.5'));
        eq(123450, Money::toPaise(1234.5));
        eq(500, Money::toPaise(5));
        eq(500, Money::toPaise('+5'));
        eq(5, Money::toPaise('.05'));
        eq(1000, Money::toPaise('10.'));
        eq(100000, Money::toPaise('1 000'));
    });

    it('treats null, empty and garbage as zero', function () {
        eq(0, Money::toPaise(null));
        eq(0, Money::toPaise(''));
        eq(0, Money::toPaise('abc'));
        eq(0, Money::toPaise('12a'));
        eq(0, Money::toPaise('1.2.3'));
    });

    it('rounds half up on the third decimal', function () {
        eq(1, Money::toPaise('0.005'));
        eq(0, Money::toPaise('0.004'));
        eq(1235, Money::toPaise('12.345'));
        eq(1234, Money::toPaise('12.3449'));
        eq(200, Money::toPaise('1.999'));
    });

    it('handles negative values', function () {
        eq(-1010, Money::toPaise('-10.10'));
        eq('-0.05', Money::fromPaise(-5));
        eq('-10.10', Money::normalize('-10.1'));
    });

    it('formats paise as fixed two-decimal strings', function () {
        eq('0.00', Money::fromPaise(0));
        eq('0.07', Money::fromPaise(7));
        eq('1234.50', Money::fromPaise(123450));
        eq('100.50', Money::normalize('100.5'));
        eq('0.00', Money::normalize(null));
    });

    it('adds and subtracts without floating-point drift', function () {
        eq('0.30', Money::sum('0.1', '0.2'));
        eq('0.30', Money::sum(0.1, 0.2));
        eq('0.00', Money::sum());
        eq('100000.00', Money::sum(...array_fill(0, 1000, '100.00')));
        eq('0.01', Money::sub('1.00', '0.99'));
        eq('-50.00', Money::sub('100', '150'));
    });

    it('compares amounts numerically, not as strings', function () {
        eq(0, Money::cmp('100', '100.00'));
        eq(1, Money::cmp('100.01', '100'));
        eq(-1, Money::cmp('9', '10'));
        eq(0, Money::cmp('1,000', 1000));
    });

    it('formats with Indian digit grouping', function () {
        eq('₹12,34,567.50', Money::format('1234567.5'));
        eq('1,00,000.00', Money::format(100000, false));
        eq('999.00', Money::format(999, false));
        eq('1,000.00', Money::format(1000, false));
        eq('1,00,00,000.00', Money::format(10000000, false));
        eq('-₹1,500.00', Money::format(-1500));
    });

    it('spells amounts in words for receipts', function () {
        eq('Rupees Zero Only', Money::inWords(0));
        eq('Rupees One Only', Money::inWords(1));
        eq('Rupees Nineteen Only', Money::inWords(19));
        eq('Rupees Twenty Only', Money::inWords(20));
        eq('Rupees One Hundred One Only', Money::inWords(101));
        eq('Rupees One Thousand Two Hundred Thirty Four and Fifty Paise Only', Money::inWords('1234.50'));
        eq('Rupees Fifteen Lakh Only', Money::inWords(1500000));
        eq('Rupees One Crore Only', Money::inWords(10000000));
        eq('Rupees One Hundred Crore Only', Money::inWords(1000000000));
        eq('Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven Only', Money::inWords(1234567));
        eq('Rupees Zero and Five Paise Only', Money::inWords('0.05'));
        eq('Rupees Ten Only', Money::inWords(-10), 'sign is dropped in words');
    });
});
