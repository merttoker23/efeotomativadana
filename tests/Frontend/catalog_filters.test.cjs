const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function controller(name) {
    const navigations = [];
    const timers = new Map();
    const context = vm.createContext({
        Controller: class {}, URL, Number, CustomEvent,
        window: { location: { href: 'https://shop.test/yeni/kategori/parts?q=lamp&brand=bosch&sort=price-asc&page=3', hash: '', assign: url => navigations.push(url) } },
        FormData: class { constructor(element) { this.fields = element.fields; } get(key) { return this.fields[key] ?? null; } },
        setTimeout: callback => { const id = timers.size + 1; timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
    });
    const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers', name + '_controller.js'), 'utf8')
        .replace("import { Controller } from '@hotwired/stimulus';", '')
        .replace('export default class', 'class Subject');
    const Subject = vm.runInContext(source + '\nSubject;', context);
    const subject = new Subject();
    subject.element = {
        action: 'https://shop.test/yeni/kategori/parts#catalog-results',
        fields: { q: 'lamp', brand: 'bosch', sort: 'price-asc', min_price: '0.01', max_price: '399735', availability: 'in-stock' },
        reportValidity: () => true,
    };
    return { subject, context, navigations, timers };
}

test('auto-submit preserves the route, search, brand, stock, price and sort while resetting pagination', () => {
    const { subject, navigations } = controller('catalog_filters');
    subject.submit();
    const destination = new URL(navigations[0]);
    assert.equal(destination.pathname, '/yeni/kategori/parts');
    assert.deepEqual(Object.fromEntries(destination.searchParams), subject.element.fields);
    assert.equal(destination.hash, '#catalog-results');
    subject.restore(); // The same controller instance survives BFCache Back.
    delete subject.element.fields.availability;
    subject.submit();
    assert.equal(navigations.length, 2);
    assert.equal(new URL(navigations[1]).searchParams.has('availability'), false);
});

test('clearing an already cleared range does not block the next stock change', () => {
    const { subject, context, navigations } = controller('catalog_filters');
    context.window.location.href = 'https://shop.test/yeni/kategori/parts?q=lamp&sort=price-asc&brand=bosch&min_price=0.01&max_price=399735&availability=in-stock#catalog-results';
    subject.submit();
    assert.equal(navigations.length, 0);
    delete subject.element.fields.availability;
    subject.submit();
    assert.equal(navigations.length, 1);
});

test('number edits debounce once and change cancels the pending submission', () => {
    const { subject, navigations, timers } = controller('catalog_filters');
    subject.debounce();
    subject.debounce();
    assert.equal(timers.size, 1);
    assert.equal(navigations.length, 0);
    subject.submit();
    assert.equal(timers.size, 0);
    assert.equal(navigations.length, 1);
});

test('adding only the results fragment does not lock subsequent filter changes', () => {
    const { subject, context, navigations } = controller('catalog_filters');
    context.window.location.href = 'https://shop.test/yeni/kategori/parts?q=lamp&sort=price-asc&brand=bosch&min_price=0.01&max_price=399735&availability=in-stock';
    subject.submit();
    assert.equal(new URL(navigations[0]).hash, '#catalog-results');
    context.window.location.href = navigations[0];
    delete subject.element.fields.availability;
    subject.submit();
    assert.equal(navigations.length, 2);
});

test('facet navigation preserves edited fields and native modified clicks', () => {
    const { subject, navigations } = controller('catalog_filters');
    let prevented = false;
    const event = { button: 0, currentTarget: { href: 'https://shop.test/yeni/marka/bosch?category=parts#catalog-results' }, preventDefault: () => { prevented = true; } };
    subject.navigate({ ...event, ctrlKey: true });
    assert.equal(prevented, false);
    subject.navigate(event);
    const destination = new URL(navigations[0]);
    assert.equal(destination.searchParams.get('category'), 'parts');
    for (const key of ['q', 'sort', 'min_price', 'max_price', 'availability']) assert.equal(destination.searchParams.get(key), subject.element.fields[key]);
});

test('clearing empties the price and stock fields and navigates to the bare listing', () => {
    const { subject, navigations } = controller('catalog_filters');
    const fields = {
        min_price: { name: 'min_price', value: '10', type: 'number', checked: false },
        max_price: { name: 'max_price', value: '1000', type: 'number', checked: false },
        availability: { name: 'availability', value: '', type: 'checkbox', checked: true },
        sort: { name: 'sort', value: 'price-asc', type: 'hidden', checked: false },
    };
    subject.element.elements = Object.values(fields);
    subject.clear({ preventDefault: () => {} });
    assert.equal(fields.min_price.value, '');
    assert.equal(fields.max_price.value, '');
    assert.equal(fields.availability.checked, false);
    assert.equal(navigations.length, 1);
    const destination = new URL(navigations[0]);
    assert.equal(destination.pathname, '/yeni/kategori/parts');
    assert.equal(destination.search, '');
    assert.equal(destination.hash, '#catalog-results');
});

test('slider drag writes plain decimals into the fields and never navigates', () => {
    const { subject, navigations } = controller('catalog_price_range');
    subject.minInputTarget = { value: '' };
    subject.maxInputTarget = { value: '' };
    subject.minRangeTarget = { value: '0.01', min: '0.01', max: '399735', style: {} };
    subject.maxRangeTarget = { value: '399735', max: '399735', style: {} };
    subject.hasMinRangeTarget = subject.hasMaxRangeTarget = true;
    subject.hasTrackTarget = false;
    subject.rangeChanged({ target: subject.minRangeTarget });
    assert.equal(subject.minInputTarget.value, '0.01');
    assert.equal(subject.maxInputTarget.value, '399735');
    assert.equal(navigations.length, 0);
    subject.maxRangeTarget.value = '2500.25';
    subject.rangeChanged({ target: subject.maxRangeTarget });
    assert.equal(subject.minInputTarget.value, '0.01');
    assert.equal(subject.maxInputTarget.value, '2500.25');
});
