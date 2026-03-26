import React from 'react';
import ReactDOM from 'react-dom';
import { EnzymeAdapter } from 'enzyme';
import { RootFinder } from './enzyme-adapter-utils';
declare class ReactEighteenAdapter extends EnzymeAdapter {
    constructor();
    createMountRenderer(options: any): {
        render(el: any, context: any, callback: any): any;
        unmount(): void;
        getNode(): any;
        wrap: any;
        simulateError(nodeHierarchy: any, rootNode: any, error: any): void;
        simulateEvent(node: any, event: any, mock: any): Promise<void>;
        batchedUpdates: typeof ReactDOM.unstable_batchedUpdates;
        getWrappingComponentRenderer(): any;
        wrapInvoke: any;
    };
    createShallowRenderer(options?: {}): {
        render(el: any, unmaskedContext: any, { providerValues }?: {
            providerValues?: Map<any, any>;
        }): any;
        unmount(): void;
        getNode(): any;
        simulateError(nodeHierarchy: any, rootNode: any, error: any): void;
        simulateEvent(node: any, event: any, ...args: any[]): void;
        batchedUpdates(fn: any): any;
        checkPropTypes(typeSpecs: any, values: any, location: any, hierarchy: any): boolean;
    };
    createStringRenderer(options: any): {
        render(el: any, context: any): any;
    };
    createRenderer(options: any): {
        render(el: any, context: any, callback: any): any;
        unmount(): void;
        getNode(): any;
        wrap: any;
        simulateError(nodeHierarchy: any, rootNode: any, error: any): void;
        simulateEvent(node: any, event: any, mock: any): Promise<void>;
        batchedUpdates: typeof ReactDOM.unstable_batchedUpdates;
        getWrappingComponentRenderer(): any;
        wrapInvoke: any;
    } | {
        render(el: any, unmaskedContext: any, { providerValues }?: {
            providerValues?: Map<any, any>;
        }): any;
        unmount(): void;
        getNode(): any;
        simulateError(nodeHierarchy: any, rootNode: any, error: any): void;
        simulateEvent(node: any, event: any, ...args: any[]): void;
        batchedUpdates(fn: any): any;
        checkPropTypes(typeSpecs: any, values: any, location: any, hierarchy: any): boolean;
    } | {
        render(el: any, context: any): any;
    };
    wrap(element: any): React.JSX.Element;
    nodeToElement(node: any): React.CElement<any, React.Component<any, any, any>>;
    matchesElementType(node: any, matchingType: any): any;
    elementToNode(element: any): any;
    nodeToHostNode(node: any, supportsArray?: boolean): any;
    displayNameOfNode(node: any): any;
    isValidElement(element: any): boolean;
    isValidElementType(object: any): boolean;
    isFragment(fragment: any): boolean;
    isCustomComponent(type: any): boolean;
    isContextConsumer(type: any): boolean;
    isCustomComponentElement(inst: any): boolean;
    getProviderFromConsumer(Consumer: any): any;
    createElement(...args: any[]): React.DetailedReactHTMLElement<React.InputHTMLAttributes<HTMLInputElement>, HTMLInputElement>;
    wrapWithWrappingComponent(node: any, options: any): {
        RootFinder: typeof RootFinder;
        node: any;
    };
}
export default ReactEighteenAdapter;
