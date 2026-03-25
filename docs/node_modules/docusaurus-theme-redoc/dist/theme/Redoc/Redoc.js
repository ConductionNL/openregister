"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const react_1 = __importDefault(require("react"));
const clsx_1 = __importDefault(require("clsx"));
require("../../global");
const redoc_1 = require("redoc");
const useSpecOptions_1 = require("../../utils/useSpecOptions");
require("./styles.css");
const ServerRedoc_1 = __importDefault(require("./ServerRedoc"));
/*!
 * Redocusaurus
 * https://redocusaurus.vercel.app/
 * (c) 2024 Rohit Gohri
 * Released under the MIT License
 */
function Redoc(props) {
    const { className, optionsOverrides, spec, url, themeId, isSpecFile } = props;
    const { options } = (0, useSpecOptions_1.useSpecOptions)(themeId, optionsOverrides);
    const isDevMode = process.env.NODE_ENV === 'development';
    if ((isDevMode && isSpecFile === false) || !spec) {
        return (react_1.default.createElement("div", { className: (0, clsx_1.default)(['redocusaurus', className]) },
            react_1.default.createElement(redoc_1.RedocStandalone, { specUrl: url, options: options })));
    }
    return react_1.default.createElement(ServerRedoc_1.default, { ...props, spec: spec });
}
exports.default = Redoc;
//# sourceMappingURL=Redoc.js.map