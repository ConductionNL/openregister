"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.spyProperty = exports.spyMethod = exports.compareNodeTypeOf = exports.fakeDynamicImport = exports.getWrappingComponentMountRenderer = exports.wrapWithWrappingComponent = exports.getNodeFromRootFinder = exports.getMaskedContext = exports.simulateError = exports.getComponentStack = exports.propsWithKeysAndRef = exports.findElement = exports.elementToTree = exports.ensureKeyOrUndefined = exports.flatten = exports.isArrayLike = exports.nodeTypeFromType = exports.displayNameOfNode = exports.assertDomAvailable = exports.withSetStateAllowed = exports.propFromEvent = exports.mapNativeEventNames = exports.RootFinder = exports.wrap = exports.createRenderWrapper = exports.createMountWrapper = void 0;
// @ts-nocheck
const function_prototype_name_1 = __importDefault(require("function.prototype.name"));
const has_1 = __importDefault(require("has"));
const createMountWrapper_1 = __importDefault(require("./createMountWrapper"));
exports.createMountWrapper = createMountWrapper_1.default;
const createRenderWrapper_1 = __importDefault(require("./createRenderWrapper"));
exports.createRenderWrapper = createRenderWrapper_1.default;
const wrapWithSimpleWrapper_1 = __importDefault(require("./wrapWithSimpleWrapper"));
exports.wrap = wrapWithSimpleWrapper_1.default;
const RootFinder_1 = __importDefault(require("./RootFinder"));
exports.RootFinder = RootFinder_1.default;
function mapNativeEventNames(event) {
    const nativeToReactEventMap = {
        compositionend: 'compositionEnd',
        compositionstart: 'compositionStart',
        compositionupdate: 'compositionUpdate',
        keydown: 'keyDown',
        keyup: 'keyUp',
        keypress: 'keyPress',
        contextmenu: 'contextMenu',
        dblclick: 'doubleClick',
        doubleclick: 'doubleClick', // kept for legacy. TODO: remove with next major.
        dragend: 'dragEnd',
        dragenter: 'dragEnter',
        dragexist: 'dragExit',
        dragleave: 'dragLeave',
        dragover: 'dragOver',
        dragstart: 'dragStart',
        mousedown: 'mouseDown',
        mousemove: 'mouseMove',
        mouseout: 'mouseOut',
        mouseover: 'mouseOver',
        mouseup: 'mouseUp',
        touchcancel: 'touchCancel',
        touchend: 'touchEnd',
        touchmove: 'touchMove',
        touchstart: 'touchStart',
        canplay: 'canPlay',
        canplaythrough: 'canPlayThrough',
        durationchange: 'durationChange',
        loadeddata: 'loadedData',
        loadedmetadata: 'loadedMetadata',
        loadstart: 'loadStart',
        ratechange: 'rateChange',
        timeupdate: 'timeUpdate',
        volumechange: 'volumeChange',
        beforeinput: 'beforeInput',
        mouseenter: 'mouseEnter',
        mouseleave: 'mouseLeave',
        transitionend: 'transitionEnd',
        animationstart: 'animationStart',
        animationiteration: 'animationIteration',
        animationend: 'animationEnd',
        pointerdown: 'pointerDown',
        pointermove: 'pointerMove',
        pointerup: 'pointerUp',
        pointercancel: 'pointerCancel',
        gotpointercapture: 'gotPointerCapture',
        lostpointercapture: 'lostPointerCapture',
        pointerenter: 'pointerEnter',
        pointerleave: 'pointerLeave',
        pointerover: 'pointerOver',
        pointerout: 'pointerOut',
        auxclick: 'auxClick',
    };
    return nativeToReactEventMap[event] || event;
}
exports.mapNativeEventNames = mapNativeEventNames;
// 'click' => 'onClick'
// 'mouseEnter' => 'onMouseEnter'
function propFromEvent(event) {
    const nativeEvent = mapNativeEventNames(event);
    return `on${nativeEvent[0].toUpperCase()}${nativeEvent.slice(1)}`;
}
exports.propFromEvent = propFromEvent;
function withSetStateAllowed(fn) {
    // NOTE(lmr):
    // this is currently here to circumvent a React bug where `setState()` is
    // not allowed without global being defined.
    let cleanup = false;
    if (typeof global.document === 'undefined') {
        cleanup = true;
        global.document = {};
    }
    const result = fn();
    if (cleanup) {
        // This works around a bug in node/jest in that developers aren't able to
        // delete things from global when running in a node vm.
        global.document = undefined;
        delete global.document;
    }
    return result;
}
exports.withSetStateAllowed = withSetStateAllowed;
function assertDomAvailable(feature) {
    if (!global || !global.document || !global.document.createElement) {
        throw new Error(`Enzyme's ${feature} expects a DOM environment to be loaded, but found none`);
    }
}
exports.assertDomAvailable = assertDomAvailable;
function displayNameOfNode(node) {
    if (!node)
        return null;
    const { type } = node;
    if (!type)
        return null;
    return type.displayName || (typeof type === 'function' ? (0, function_prototype_name_1.default)(type) : type.name || type);
}
exports.displayNameOfNode = displayNameOfNode;
function nodeTypeFromType(type) {
    if (typeof type === 'string') {
        return 'host';
    }
    if (type && type.prototype && type.prototype.isReactComponent) {
        return 'class';
    }
    return 'function';
}
exports.nodeTypeFromType = nodeTypeFromType;
function getIteratorFn(obj) {
    const iteratorFn = obj &&
        ((typeof Symbol === 'function' &&
            typeof Symbol.iterator === 'symbol' &&
            obj[Symbol.iterator]) ||
            obj['@@iterator']);
    if (typeof iteratorFn === 'function') {
        return iteratorFn;
    }
    return undefined;
}
function isIterable(obj) {
    return !!getIteratorFn(obj);
}
function isArrayLike(obj) {
    return Array.isArray(obj) || (typeof obj !== 'string' && isIterable(obj));
}
exports.isArrayLike = isArrayLike;
function flatten(arrs) {
    // optimize for the most common case
    if (Array.isArray(arrs)) {
        return arrs.reduce((flatArrs, item) => flatArrs.concat(isArrayLike(item) ? flatten(item) : item), []);
    }
    // fallback for arbitrary iterable children
    let flatArrs = [];
    const iteratorFn = getIteratorFn(arrs);
    const iterator = iteratorFn.call(arrs);
    let step = iterator.next();
    while (!step.done) {
        const item = step.value;
        let flatItem;
        if (isArrayLike(item)) {
            flatItem = flatten(item);
        }
        else {
            flatItem = item;
        }
        flatArrs = flatArrs.concat(flatItem);
        step = iterator.next();
    }
    return flatArrs;
}
exports.flatten = flatten;
function ensureKeyOrUndefined(key) {
    return key || (key === '' ? '' : undefined);
}
exports.ensureKeyOrUndefined = ensureKeyOrUndefined;
function elementToTree(el, recurse = elementToTree) {
    if (typeof recurse !== 'function' && arguments.length === 3) {
        // special case for backwards compat for `.map(elementToTree)`
        recurse = elementToTree;
    }
    if (el === null || typeof el !== 'object' || !('type' in el)) {
        return el;
    }
    const { type, props, key, ref } = el;
    const { children } = props;
    let rendered = null;
    if (isArrayLike(children)) {
        rendered = flatten(children).map((x) => recurse(x));
    }
    else if (typeof children !== 'undefined') {
        rendered = recurse(children);
    }
    const nodeType = nodeTypeFromType(type);
    if (nodeType === 'host' && props.dangerouslySetInnerHTML) {
        if (props.children != null) {
            const error = new Error('Can only set one of `children` or `props.dangerouslySetInnerHTML`.');
            error.name = 'Invariant Violation';
            throw error;
        }
    }
    return {
        nodeType,
        type,
        props,
        key: ensureKeyOrUndefined(key),
        ref,
        instance: null,
        rendered,
    };
}
exports.elementToTree = elementToTree;
function mapFind(arraylike, mapper, finder) {
    let found;
    const isFound = Array.prototype.find.call(arraylike, (item) => {
        found = mapper(item);
        return finder(found);
    });
    return isFound ? found : undefined;
}
function findElement(el, predicate) {
    if (el === null || typeof el !== 'object' || !('type' in el)) {
        return undefined;
    }
    if (predicate(el)) {
        return el;
    }
    const { rendered } = el;
    if (isArrayLike(rendered)) {
        return mapFind(rendered, (x) => findElement(x, predicate), (x) => typeof x !== 'undefined');
    }
    return findElement(rendered, predicate);
}
exports.findElement = findElement;
function propsWithKeysAndRef(node) {
    if (node.ref !== null || node.key !== null) {
        return {
            ...node.props,
            key: node.key,
            ref: node.ref,
        };
    }
    return node.props;
}
exports.propsWithKeysAndRef = propsWithKeysAndRef;
function getComponentStack(hierarchy, getNodeType = nodeTypeFromType, getDisplayName = displayNameOfNode) {
    const tuples = hierarchy
        .filter((node) => node.type !== RootFinder_1.default)
        .map((x) => [getNodeType(x.type), getDisplayName(x)])
        .concat([['class', 'WrapperComponent']]);
    return tuples
        .map(([, name], i, arr) => {
        const [, closestComponent] = arr.slice(i + 1).find(([nodeType]) => nodeType !== 'host') || [];
        return `\n    in ${name}${closestComponent ? ` (created by ${closestComponent})` : ''}`;
    })
        .join('');
}
exports.getComponentStack = getComponentStack;
function simulateError(error, catchingInstance, rootNode, // TODO: remove `rootNode` next semver-major
hierarchy, getNodeType = nodeTypeFromType, getDisplayName = displayNameOfNode, catchingType = {}) {
    const instance = catchingInstance || {};
    const { componentDidCatch } = instance;
    const { getDerivedStateFromError } = catchingType;
    if (!componentDidCatch && !getDerivedStateFromError) {
        throw error;
    }
    if (getDerivedStateFromError) {
        const stateUpdate = getDerivedStateFromError.call(catchingType, error);
        instance.setState(stateUpdate);
    }
    if (componentDidCatch) {
        const componentStack = getComponentStack(hierarchy, getNodeType, getDisplayName);
        componentDidCatch.call(instance, error, { componentStack });
    }
}
exports.simulateError = simulateError;
function getMaskedContext(contextTypes, unmaskedContext) {
    if (!contextTypes || !unmaskedContext) {
        return {};
    }
    return Object.fromEntries(Object.keys(contextTypes).map((key) => [key, unmaskedContext[key]]));
}
exports.getMaskedContext = getMaskedContext;
function getNodeFromRootFinder(isCustomComponent, tree, options) {
    if (!isCustomComponent(options.wrappingComponent)) {
        return tree.rendered;
    }
    const rootFinder = findElement(tree, (node) => node.type === RootFinder_1.default);
    if (!rootFinder) {
        throw new Error('`wrappingComponent` must render its children!');
    }
    return rootFinder.rendered;
}
exports.getNodeFromRootFinder = getNodeFromRootFinder;
function wrapWithWrappingComponent(createElement, node, options) {
    const { wrappingComponent, wrappingComponentProps } = options;
    if (!wrappingComponent) {
        return node;
    }
    return createElement(wrappingComponent, wrappingComponentProps, createElement(RootFinder_1.default, null, node));
}
exports.wrapWithWrappingComponent = wrapWithWrappingComponent;
function getWrappingComponentMountRenderer({ toTree, getMountWrapperInstance }) {
    return {
        getNode() {
            const instance = getMountWrapperInstance();
            return instance ? toTree(instance).rendered : null;
        },
        render(el, context, callback) {
            const instance = getMountWrapperInstance();
            if (!instance) {
                throw new Error('The wrapping component may not be updated if the root is unmounted.');
            }
            return instance.setWrappingComponentProps(el.props, callback);
        },
    };
}
exports.getWrappingComponentMountRenderer = getWrappingComponentMountRenderer;
function fakeDynamicImport(moduleToImport) {
    return Promise.resolve({ default: moduleToImport });
}
exports.fakeDynamicImport = fakeDynamicImport;
function compareNodeTypeOf(node, matchingTypeOf) {
    if (!node) {
        return false;
    }
    return node.$$typeof === matchingTypeOf;
}
exports.compareNodeTypeOf = compareNodeTypeOf;
// TODO: when enzyme v3.12.0 is required, delete this
function spyMethod(instance, methodName, getStub = () => { }) {
    let lastReturnValue;
    const originalMethod = instance[methodName];
    const hasOwn = (0, has_1.default)(instance, methodName);
    let descriptor;
    if (hasOwn) {
        descriptor = Object.getOwnPropertyDescriptor(instance, methodName);
    }
    Object.defineProperty(instance, methodName, {
        configurable: true,
        enumerable: !descriptor || !!descriptor.enumerable,
        value: getStub(originalMethod) ||
            function spied(...args) {
                const result = originalMethod.apply(this, args);
                lastReturnValue = result;
                return result;
            },
    });
    return {
        restore() {
            if (hasOwn) {
                if (descriptor) {
                    Object.defineProperty(instance, methodName, descriptor);
                }
                else {
                    instance[methodName] = originalMethod;
                }
            }
            else {
                delete instance[methodName];
            }
        },
        getLastReturnValue() {
            return lastReturnValue;
        },
    };
}
exports.spyMethod = spyMethod;
// TODO: when enzyme v3.12.0 is required, delete this
function spyProperty(instance, propertyName, handlers = {}) {
    const originalValue = instance[propertyName];
    const hasOwn = (0, has_1.default)(instance, propertyName);
    let descriptor;
    if (hasOwn) {
        descriptor = Object.getOwnPropertyDescriptor(instance, propertyName);
    }
    let wasAssigned = false;
    let holder = originalValue;
    const getV = handlers.get
        ? () => {
            const value = descriptor && descriptor.get ? descriptor.get.call(instance) : holder;
            return handlers.get.call(instance, value);
        }
        : () => holder;
    const set = handlers.set
        ? (newValue) => {
            wasAssigned = true;
            const handlerNewValue = handlers.set.call(instance, holder, newValue);
            holder = handlerNewValue;
            if (descriptor && descriptor.set) {
                descriptor.set.call(instance, holder);
            }
        }
        : (v) => {
            wasAssigned = true;
            holder = v;
        };
    Object.defineProperty(instance, propertyName, {
        configurable: true,
        enumerable: !descriptor || !!descriptor.enumerable,
        get: getV,
        set,
    });
    return {
        restore() {
            if (hasOwn) {
                if (descriptor) {
                    Object.defineProperty(instance, propertyName, descriptor);
                }
                else {
                    instance[propertyName] = holder;
                }
            }
            else {
                delete instance[propertyName];
            }
        },
        wasAssigned() {
            return wasAssigned;
        },
    };
}
exports.spyProperty = spyProperty;
