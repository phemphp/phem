// The checks of the browser engine and of the system, as in Base UI. The functions read the
// browser each time, so a test can replace `navigator`.

// WebKit: Safari, all the browsers of iOS and GNOME Web. Blink has no `-webkit-backdrop-filter`.
export function isWebKit() {
  return typeof CSS !== "undefined" && !!CSS.supports?.("-webkit-backdrop-filter:none")
}

// Gecko: Firefox. Firefox on iOS is WebKit.
export function isGecko() {
  return !isWebKit() && /firefox/i.test(navigator.userAgent)
}

// iPhone, iPad (also iPadOS that gives `MacIntel`) and iPod.
export function isIOS() {
  const platform = (navigator.platform ?? "").toLowerCase()

  return /^i(os$|p)/.test(platform) || (platform === "macintel" && navigator.maxTouchPoints > 1)
}

export function isAndroid() {
  return /android/i.test(navigator.userAgent)
}
