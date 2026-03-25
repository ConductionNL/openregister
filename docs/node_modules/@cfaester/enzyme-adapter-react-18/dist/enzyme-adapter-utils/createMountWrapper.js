"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
// @ts-nocheck
const react_1 = __importDefault(require("react"));
const prop_types_1 = __importDefault(require("prop-types"));
const RootFinder_1 = __importDefault(require("./RootFinder"));
const stringOrFunction = prop_types_1.default.oneOfType([prop_types_1.default.func, prop_types_1.default.string]);
const makeValidElementType = (adapter) => {
    if (!adapter) {
        return stringOrFunction;
    }
    function validElementTypeRequired(props, propName, ...args) {
        if (!adapter.isValidElementType) {
            return stringOrFunction.isRequired(props, propName, ...args);
        }
        const propValue = props[propName];
        if (adapter.isValidElementType(propValue)) {
            return null;
        }
        return new TypeError(`${propName} must be a valid element type!`);
    }
    function validElementType(props, propName, ...args) {
        const propValue = props[propName];
        if (propValue == null) {
            return null;
        }
        return validElementTypeRequired(props, propName, ...args);
    }
    validElementType.isRequired = validElementTypeRequired;
    return validElementType;
};
/**
 * This is a utility component to wrap around the nodes we are
 * passing in to `mount()`. Theoretically, you could do everything
 * we are doing without this, but this makes it easier since
 * `renderIntoDocument()` doesn't really pass back a reference to
 * the DOM node it rendered to, so we can't really "re-render" to
 * pass new props in.
 */
function createMountWrapper(node, options = {}) {
    const { adapter, wrappingComponent: WrappingComponent } = options;
    class WrapperComponent extends react_1.default.Component {
        constructor(...args) {
            super(...args);
            const { props, wrappingComponentProps, context } = this.props;
            this.state = {
                mount: true,
                props,
                wrappingComponentProps,
                context,
            };
        }
        setChildProps(newProps, newContext, callback = undefined) {
            const { props: oldProps, context: oldContext } = this.state;
            const props = { ...oldProps, ...newProps };
            const context = { ...oldContext, ...newContext };
            this.setState({ props, context }, callback);
        }
        setWrappingComponentProps(props, callback = undefined) {
            this.setState({ wrappingComponentProps: props }, callback);
        }
        render() {
            const { Component, refProp } = this.props;
            const { mount, props, wrappingComponentProps } = this.state;
            if (!mount)
                return null;
            const component = react_1.default.createElement(Component, { ref: refProp, ...props });
            if (WrappingComponent) {
                return (react_1.default.createElement(WrappingComponent, { ...wrappingComponentProps },
                    react_1.default.createElement(RootFinder_1.default, null, component)));
            }
            return component;
        }
        componentDidMount() {
            const { onRenderCb } = this.props;
            onRenderCb && onRenderCb(this);
        }
    }
    WrapperComponent.propTypes = {
        Component: makeValidElementType(adapter).isRequired,
        context: prop_types_1.default.object,
        props: prop_types_1.default.object.isRequired,
        refProp: prop_types_1.default.oneOfType([
            prop_types_1.default.string,
            prop_types_1.default.func,
            prop_types_1.default.shape({
                current: prop_types_1.default.any,
            }),
        ]),
        wrappingComponentProps: prop_types_1.default.object,
    };
    WrapperComponent.defaultProps = {
        refProp: null,
        context: null,
        wrappingComponentProps: null,
    };
    if (options.context && (node.type.contextTypes || options.childContextTypes)) {
        // For full rendering, we are using this wrapper component to provide context if it is
        // specified in both the options AND the child component defines `contextTypes` statically
        // OR the merged context types for all children (the node component or deeper children) are
        // specified in options parameter under childContextTypes.
        // In that case, we define both a `getChildContext()` function and a `childContextTypes` prop.
        const childContextTypes = {
            ...node.type.contextTypes,
            ...options.childContextTypes,
        };
        WrapperComponent.prototype.getChildContext = function getChildContext() {
            return this.state.context;
        };
        WrapperComponent.childContextTypes = childContextTypes;
    }
    return WrapperComponent;
}
exports.default = createMountWrapper;
