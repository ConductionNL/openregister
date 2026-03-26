"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ServerStyles = ServerStyles;
const react_1 = __importDefault(require("react"));
require("../../global");
/**
 * Don't hydrate/replace server styles
 * @see https://github.com/facebook/react/issues/10923#issuecomment-338715787
 */
function ServerStyles(_props) {
    return react_1.default.createElement("div", { className: "redocusaurus-styles" });
}
//# sourceMappingURL=Styles.js.map