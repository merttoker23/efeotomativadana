const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function controller(min = '', max = '') {
    const source = fs.readFileSync('assets/controllers/catalog_price_range_controller.js', 'utf8')
        .replace(/^import .*;\r?\n/, '').replace('export default class extends Controller', 'globalThis.Range = class extends Controller');
    const context = vm.createContext({ Controller: class {}, Intl });
    vm.runInContext(source, context);
    const field = (value) => ({ value, setCustomValidity(message) { this.error = message; } });
    const range = (value) => ({ value, min: '0.01', max: '399735.00', style: {} });
    const result = new context.Range();
    Object.assign(result, { minInputTarget: field(min), maxInputTarget: field(max),
        minCanonicalTarget: field(''), maxCanonicalTarget: field(''),
        minRangeTarget: range('0.01'), maxRangeTarget: range('399735.00'), hasMinRangeTarget: true,
        hasTrackTarget: false });
    return result;
}

test('Turkish text writes canonical hidden fields without losing kuruş', () => {
    const range = controller('0,01', '399.735,00');
    range.inputsChanged();
    assert.equal(range.minCanonicalTarget.value, '0.01');
    assert.equal(range.maxCanonicalTarget.value, '399735.00');
    assert.equal(range.maxRangeTarget.value, '399735.00');
});

test('slider writes localized display and canonical submission', () => {
    const range = controller();
    range.rangeChanged({ target: range.maxRangeTarget });
    assert.equal(range.minInputTarget.value, '0,01');
    assert.equal(range.maxInputTarget.value, '399.735,00');
    assert.equal(range.maxCanonicalTarget.value, '399735.00');
});

test('invalid, negative and inverted bounds block submission without silently clamping text', () => {
    for (const input of ['-1', 'abc', '1.23,45', '1,234', '1e3']) {
        const range = controller(input, '100,00');
        range.inputsChanged();
        assert.ok(range.minInputTarget.error, input);
        assert.equal(range.minCanonicalTarget.value, '');
    }
    const range = controller('101,00', '100,00');
    range.inputsChanged();
    assert.ok(range.maxInputTarget.error);
    assert.equal(range.minInputTarget.value, '101,00');
    range.minInputTarget.value = '';
    range.inputsChanged();
    assert.equal(range.minCanonicalTarget.value, '');
    assert.equal(range.maxInputTarget.error, '');
});

test('valid kuruş fractions are parsed without floating point rejection', () => {
    const range = controller('1,11', '12,34');
    range.inputsChanged();
    assert.equal(range.minCanonicalTarget.value, '1.11');
    assert.equal(range.maxCanonicalTarget.value, '12.34');
});

test('text bounds preserve the backend integer ceiling exactly', () => {
    const range = controller('92.233.720.368.547.758,06', '92.233.720.368.547.758,07');
    range.inputsChanged();
    assert.equal(range.minCanonicalTarget.value, '92233720368547758.06');
    assert.equal(range.maxCanonicalTarget.value, '92233720368547758.07');
    range.formatInputs();
    assert.equal(range.minInputTarget.value, '92.233.720.368.547.758,06');
    range.maxInputTarget.value = '92.233.720.368.547.758,08';
    range.inputsChanged();
    assert.ok(range.maxInputTarget.error);
});
