"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const react_1 = __importStar(require("react"));
const Redoc_1 = __importDefault(require("@theme/Redoc"));
const useSpecData_1 = __importDefault(require("@theme/useSpecData"));
require("../ApiSchema/styles.css");
const ApiDocMdx = ({ id }) => {
    const specProps = (0, useSpecData_1.default)(id);
    const optionsOverrides = (0, react_1.useMemo)(() => {
        return {
            theme: {
                // TODO: Investigate what the best breakpoints should be
                breakpoints: {
                    medium: '130rem',
                    large: '130rem',
                },
            },
        };
    }, []);
    return react_1.default.createElement(Redoc_1.default, { ...specProps, optionsOverrides: optionsOverrides });
};
exports.default = ApiDocMdx;
//# sourceMappingURL=ApiDocMdx.js.map