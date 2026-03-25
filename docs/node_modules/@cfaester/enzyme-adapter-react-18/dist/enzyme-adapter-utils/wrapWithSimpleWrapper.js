"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
// @ts-nocheck
const react_1 = __importDefault(require("react"));
const prop_types_1 = __importDefault(require("prop-types"));
const propTypes = {
    children: prop_types_1.default.node,
};
const defaultProps = {
    children: undefined,
};
const Wrapper = Object.assign(({ children }) => children, { propTypes, defaultProps });
function wrap(element) {
    return react_1.default.createElement(Wrapper, null, element);
}
exports.default = wrap;
