"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
// @ts-nocheck
const react_1 = __importDefault(require("react"));
function createRenderWrapper(node, context, childContextTypes) {
    class ContextWrapper extends react_1.default.Component {
        getChildContext() {
            return context;
        }
        render() {
            return node;
        }
    }
    ContextWrapper.childContextTypes = childContextTypes;
    return ContextWrapper;
}
exports.default = createRenderWrapper;
