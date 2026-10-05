const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Which options a `<select>` shows belongs to the browser. What is ours, and what a regression
// would break, is the decision: an option the chosen province does not own has to disappear, a
// selection that no longer belongs has to go, and with no province chosen the box has to close
// so a district cannot be submitted on its own.
function load() {
    const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/store_address_controller.js'), 'utf8')
        .replace(/^import .*;\r?\n/, '')
        .replace('export default class extends Controller', 'globalThis.StoreAddress = class extends Controller');
    const context = vm.createContext({ Controller: class {} });
    vm.runInContext(source, context);

    return context.StoreAddress;
}

function select(values, selected) {
    return {
        value: selected ?? '',
        disabled: false,
        options: ['', ...values].map((value) => ({ value, hidden: false, disabled: false })),
        get selectedOptions() {
            return this.options.filter((option) => option.value === this.value);
        },
    };
}

function visible(district) {
    return district.options.filter((option) => !option.disabled).map((option) => option.value);
}

const CATALOG = { Adana: ['Ceyhan', 'Seyhan'], Gaziantep: ['Şehitkamil'] };

test('the districts of the chosen province are the only ones left offered', () => {
    const controller = new (load())();
    controller.cityTarget = select(Object.keys(CATALOG), 'Adana');
    controller.districtTarget = select(['Ceyhan', 'Seyhan', 'Şehitkamil'], 'Seyhan');
    controller.districtsValue = CATALOG;

    controller.update();

    assert.deepEqual(visible(controller.districtTarget), ['', 'Ceyhan', 'Seyhan']);
    assert.equal(controller.districtTarget.value, 'Seyhan', 'a district of the chosen province survives');
    assert.equal(controller.districtTarget.disabled, false);
});

test('changing the province clears a district that no longer belongs to it', () => {
    const controller = new (load())();
    controller.cityTarget = select(Object.keys(CATALOG), 'Gaziantep');
    controller.districtTarget = select(['Ceyhan', 'Şehitkamil'], 'Ceyhan');
    controller.districtsValue = CATALOG;

    controller.update();

    assert.deepEqual(visible(controller.districtTarget), ['', 'Şehitkamil']);
    assert.equal(controller.districtTarget.value, '', 'Seyhan under Adana cannot stay selected under Gaziantep');
});

test('with no province chosen the box closes and offers nothing', () => {
    const controller = new (load())();
    controller.cityTarget = select(Object.keys(CATALOG), '');
    controller.districtTarget = select(['Ceyhan', 'Şehitkamil'], 'Şehitkamil');
    controller.districtsValue = CATALOG;

    controller.update();

    assert.deepEqual(visible(controller.districtTarget), ['']);
    assert.equal(controller.districtTarget.value, '');
    assert.equal(controller.districtTarget.disabled, true, 'a district alone cannot be submitted');
});

test('a province the catalog does not know offers nothing rather than everything', () => {
    const controller = new (load())();
    controller.cityTarget = select(Object.keys(CATALOG), 'Adaa');
    controller.districtTarget = select(['Ceyhan', 'Şehitkamil'], 'Ceyhan');
    controller.districtsValue = CATALOG;

    controller.update();

    assert.deepEqual(visible(controller.districtTarget), ['']);
    assert.equal(controller.districtTarget.disabled, true);
});

test('connect runs the same narrowing, so a saved pair is filtered on first paint', () => {
    const controller = new (load())();
    controller.cityTarget = select(Object.keys(CATALOG), 'Adana');
    controller.districtTarget = select(['Ceyhan', 'Seyhan', 'Şehitkamil'], 'Seyhan');
    controller.districtsValue = CATALOG;

    controller.connect();

    assert.deepEqual(visible(controller.districtTarget), ['', 'Ceyhan', 'Seyhan']);
});