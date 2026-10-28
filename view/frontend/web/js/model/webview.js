/*browser:true*/
/*global define*/
/**
 * Known in-app browsers (webviews) that get the redirect instead of the popup (BNPL-1664, rules agreed for the pilot):
 * FBAN/FBAV, Instagram, Twitter, Line/, Android with wv, and webview or wv. A heuristic on the user agent: it does not
 * detect whether an app is installed or opens the store's domain.
 *
 * Not equivalent to angular.partner-core's WebViewDetectionService (3.2.1): that one matches "Line" without the slash
 * and "wv" anywhere in the user agent; here "wv" must be a whole token (as in Android WebView's "; wv)"), so words
 * that merely contain those letters do not turn the popup off.
 */
define([], function () {
    'use strict';

    function isWebView(userAgent) {
        var ua = typeof userAgent === 'string' ? userAgent : '';

        if (/FBAN|FBAV/.test(ua) || ua.indexOf('Instagram') !== -1 || ua.indexOf('Twitter') !== -1 ||
            ua.indexOf('Line/') !== -1
        ) {
            return true;
        }
        if (/android/i.test(ua) && /\bwv\b|webview/i.test(ua)) {
            return true;
        }

        return /webview|\bwv\b/i.test(ua);
    }

    return {
        isWebView: isWebView
    };
});
