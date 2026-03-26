import createMountWrapper from './createMountWrapper';
import createRenderWrapper from './createRenderWrapper';
import wrap from './wrapWithSimpleWrapper';
import RootFinder from './RootFinder';
export { createMountWrapper, createRenderWrapper, wrap, RootFinder };
export declare function mapNativeEventNames(event: any): any;
export declare function propFromEvent(event: any): string;
export declare function withSetStateAllowed(fn: any): any;
export declare function assertDomAvailable(feature: any): void;
export declare function displayNameOfNode(node: any): any;
export declare function nodeTypeFromType(type: any): "portal" | "host" | "class" | "function";
export declare function isArrayLike(obj: any): boolean;
export declare function flatten(arrs: any): any;
export declare function ensureKeyOrUndefined(key: any): any;
export declare function elementToTree(el: any, recurse?: typeof elementToTree): any;
export declare function findElement(el: any, predicate: any): any;
export declare function propsWithKeysAndRef(node: any): any;
export declare function getComponentStack(hierarchy: any, getNodeType?: typeof nodeTypeFromType, getDisplayName?: typeof displayNameOfNode): any;
export declare function simulateError(error: any, catchingInstance: any, rootNode: any, // TODO: remove `rootNode` next semver-major
hierarchy: any, getNodeType?: typeof nodeTypeFromType, getDisplayName?: typeof displayNameOfNode, catchingType?: {}): void;
export declare function getMaskedContext(contextTypes: any, unmaskedContext: any): {
    [k: string]: any;
};
export declare function getNodeFromRootFinder(isCustomComponent: any, tree: any, options: any): any;
export declare function wrapWithWrappingComponent(createElement: any, node: any, options: any): any;
export declare function getWrappingComponentMountRenderer({ toTree, getMountWrapperInstance }: {
    toTree: any;
    getMountWrapperInstance: any;
}): {
    getNode(): any;
    render(el: any, context: any, callback: any): any;
};
export declare function fakeDynamicImport(moduleToImport: any): Promise<{
    default: any;
}>;
export declare function compareNodeTypeOf(node: any, matchingTypeOf: any): boolean;
export declare function spyMethod(instance: any, methodName: any, getStub?: any): {
    restore(): void;
    getLastReturnValue(): any;
};
export declare function spyProperty(instance: any, propertyName: any, handlers?: {}): {
    restore(): void;
    wasAssigned(): boolean;
};
