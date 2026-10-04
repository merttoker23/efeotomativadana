const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Stimulus target lookup and native dialog rendering belong to the browser. These
// DOM boundaries exercise our controller's selection, navigation and cleanup.
class Element {
    constructor(attributes = {}) {
        this.attributes = new Map(Object.entries(attributes));
        this.style = {};
        this.children = [];
        this.dataset = {};
    }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    removeAttribute(name) { this.attributes.delete(name); }
    get src() { return this.getAttribute('src'); }
    set src(value) { this.setAttribute('src', value); }
    get alt() { return this.getAttribute('alt'); }
    set alt(value) { this.setAttribute('alt', value); }
    querySelector() { return this.children[0]; }
    append(...elements) { this.children.push(...elements); }
    replaceChildren(...elements) { this.children = elements; }
    addEventListener() {}
    focus() { this.focused = true; }
    getBoundingClientRect() { return { left: 100, top: 50, right: 900, bottom: 700 }; }
}

function gallery(count = 2) {
    const document = { body: new Element(), documentElement: new Element(), createElement: () => new Element() };
    document.body.style.overflow = 'auto';
    document.documentElement.style.overflow = 'scroll';
    const source = fs.readFileSync(path.join(__dirname, '../../assets/controllers/product_gallery_controller.js'), 'utf8')
        .replace(/^import .*;\r?\n/, '')
        .replace('export default class extends Controller', 'globalThis.Gallery = class extends Controller');
    const context = vm.createContext({ Controller: class {}, document });
    vm.runInContext(source, context);
    const controller = new context.Gallery();
    const thumbnails = Array.from({ length: count }, (_, index) => {
        const button = new Element({ 'aria-pressed': String(index === 0) });
        button.append(new Element({ src: `/image-${index + 1}.jpg`, alt: `Image ${index + 1}`, width: '800', height: '600' }));
        return button;
    });
    Object.assign(controller, {
        mainTarget: new Element({ src: '/image-1.jpg', alt: 'Image 1', width: '800', height: '600' }),
        thumbnailTargets: count > 1 ? thumbnails : [],
        hasDialogTarget: count > 0,
        dialogTarget: new Element(), zoomTarget: new Element(), zoomThumbnailsTarget: new Element(),
        positionTarget: new Element(), previousTarget: new Element(), nextTarget: new Element(),
    });
    controller.dialogTarget.showModal = function () { this.open = true; };
    controller.dialogTarget.close = function () { this.open = false; controller.closed(); };
    return { controller, document, thumbnails };
}

test('opens the currently selected real image and locks scrolling without changing the main image', () => {
    const { controller, document, thumbnails } = gallery();
    assert.equal(controller.zoomTarget.src, null, 'The modal does not request an image before it is opened.');
    controller.select({ currentTarget: thumbnails[1] });
    controller.open({ currentTarget: new Element() });
    assert.equal(controller.dialogTarget.open, true);
    assert.equal(controller.zoomTarget.src, '/image-2.jpg');
    assert.equal(controller.mainTarget.src, '/image-2.jpg');
    assert.equal(document.body.style.overflow, 'hidden');
    assert.equal(document.documentElement.style.overflow, 'hidden');
    assert.equal(controller.positionTarget.textContent, '2 / 2');
});

test('keyboard navigation wraps and updates the selected dialog thumbnail', () => {
    const { controller } = gallery();
    controller.open({ currentTarget: new Element() });
    controller.keydown({ key: 'ArrowLeft', preventDefault() {} });
    assert.equal(controller.zoomTarget.src, '/image-2.jpg');
    assert.equal(controller.zoomThumbnailsTarget.children[1].getAttribute('aria-pressed'), 'true');
    assert.equal(controller.mainTarget.src, '/image-1.jpg');
    controller.keydown({ key: 'ArrowRight', preventDefault() {} });
    assert.equal(controller.zoomTarget.src, '/image-1.jpg');
    assert.equal(controller.zoomThumbnailsTarget.children[0].getAttribute('aria-pressed'), 'true');
});

test('dialog thumbnails select the requested image without changing storefront thumbnail selection', () => {
    const { controller, thumbnails } = gallery();
    controller.open({ currentTarget: new Element() });
    controller.selectZoom({ currentTarget: controller.zoomThumbnailsTarget.children[1] });
    assert.equal(controller.zoomTarget.src, '/image-2.jpg');
    assert.equal(controller.zoomTarget.alt, 'Image 2');
    assert.equal(controller.positionTarget.textContent, '2 / 2');
    assert.equal(thumbnails[0].getAttribute('aria-pressed'), 'true');
});

test('native Escape cancellation uses the same close and scroll restoration path', () => {
    const { controller, document } = gallery();
    controller.open({ currentTarget: new Element() });
    controller.close({ preventDefault() {} });
    assert.equal(controller.dialogTarget.open, false);
    assert.equal(document.body.style.overflow, 'auto');
});

test('closing restores the original overflow styles and opener focus', () => {
    const { controller, document } = gallery();
    const opener = new Element();
    controller.open({ currentTarget: opener });
    controller.close();
    assert.equal(controller.dialogTarget.open, false);
    assert.equal(document.body.style.overflow, 'auto');
    assert.equal(document.documentElement.style.overflow, 'scroll');
    assert.equal(opener.focused, true);
});

test('only clicks outside the dialog bounds close the modal', () => {
    const { controller } = gallery();
    controller.open({ currentTarget: new Element() });
    controller.backdrop({ target: controller.dialogTarget, clientX: 500, clientY: 300 });
    assert.equal(controller.dialogTarget.open, true);
    controller.backdrop({ target: controller.dialogTarget, clientX: 20, clientY: 20 });
    assert.equal(controller.dialogTarget.open, false);
});

test('single images can zoom and missing real images cannot open a dialog', () => {
    const { controller } = gallery(1);
    controller.open({ currentTarget: new Element() });
    assert.equal(controller.zoomTarget.src, '/image-1.jpg');
    assert.equal(controller.previousTarget.hidden, true);
    assert.equal(controller.nextTarget.hidden, true);
    const placeholder = gallery(0).controller;
    placeholder.open({ currentTarget: new Element() });
    assert.notEqual(placeholder.dialogTarget.open, true);
});

test('disconnect closes an open dialog and removes the scroll lock', () => {
    const { controller, document } = gallery();
    controller.open({ currentTarget: new Element() });
    controller.disconnect();
    assert.equal(controller.dialogTarget.open, false);
    assert.equal(document.body.style.overflow, 'auto');
});

test('normal thumbnail selection retains accessible state and removes stale dimensions', () => {
    const { controller, thumbnails } = gallery();
    thumbnails[1].children[0].removeAttribute('width');
    thumbnails[1].children[0].removeAttribute('height');
    controller.select({ currentTarget: thumbnails[1] });
    assert.equal(controller.mainTarget.src, '/image-2.jpg');
    assert.equal(controller.mainTarget.getAttribute('width'), null);
    assert.equal(controller.mainTarget.getAttribute('height'), null);
    assert.equal(thumbnails[0].getAttribute('aria-pressed'), 'false');
    assert.equal(thumbnails[1].getAttribute('aria-pressed'), 'true');
});
