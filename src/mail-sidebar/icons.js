/**
 * Resolve a schema's `icon` (a Material Design Icon name in PascalCase, e.g.
 * "ReceiptTextOutline") to a `vue-material-design-icons` component.
 *
 * We statically import the icons in this registry so the bundle only carries
 * the ones we map (rather than the whole ~7000-icon set via a dynamic
 * context). Unknown icon names fall back to a neutral shape so a card always
 * renders something sensible.
 *
 * @spec openspec/specs/integration-email/spec.md
 */
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ReceiptTextOutline from 'vue-material-design-icons/ReceiptTextOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import PhoneOutline from 'vue-material-design-icons/PhoneOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BookOpenOutline from 'vue-material-design-icons/BookOpenOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import CardAccountDetailsOutline from 'vue-material-design-icons/CardAccountDetailsOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'

const FALLBACK = ShapeOutline

const REGISTRY = {
	TrendingUp,
	ReceiptTextOutline,
	BriefcaseOutline,
	FileDocumentOutline,
	FileOutline,
	FolderOutline,
	AccountOutline,
	AccountGroupOutline,
	AccountMultipleOutline,
	OfficeBuildingOutline,
	HomeOutline,
	CalendarOutline,
	ClockOutline,
	EmailOutline,
	PhoneOutline,
	MapMarkerOutline,
	CurrencyEur,
	CartOutline,
	PackageVariantClosed,
	CubeOutline,
	ClipboardTextOutline,
	ClipboardListOutline,
	CheckCircleOutline,
	HandshakeOutline,
	TagOutline,
	BellOutline,
	BookOpenOutline,
	ScaleBalance,
	CardAccountDetailsOutline,
	LinkVariant,
}

/**
 * @param {string|null|undefined} name Schema icon name (PascalCase MDI name).
 * @return {object} A `vue-material-design-icons` component to render.
 */
export function schemaIconComponent(name) {
	if (!name) {
		return FALLBACK
	}
	return REGISTRY[name] || FALLBACK
}
