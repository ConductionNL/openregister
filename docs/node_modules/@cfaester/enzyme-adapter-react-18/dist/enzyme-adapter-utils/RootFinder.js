"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
// @ts-nocheck
const react_1 = __importDefault(require("react"));
const prop_types_1 = __importDefault(require("prop-types"));
class RootFinder extends react_1.default.Component {
    render() {
        const { children } = this.props;
        return children;
    }
}
exports.default = RootFinder;
RootFinder.propTypes = {
    children: prop_types_1.default.node,
};
RootFinder.defaultProps = {
    children: null,
};
