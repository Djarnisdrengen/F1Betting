'use strict';

// Stack A only — used by Playwright specs to parse e2e_markers emitted by the backend.
// Markers are [key] value pairs in the page body after a POST-redirect-GET.

const MARKER_RE = /\[([^\]]+)\]\s*([^\n\r]+)/g;

function parseMarkers(text) {
    const result = {};
    for (const [, key, value] of text.matchAll(MARKER_RE)) {
        result[key] = value.trim();
    }
    return result;
}

function expectMarker(text, key, expectedValue) {
    const markers = parseMarkers(text);
    const actual = markers[key];
    if (actual === undefined) {
        throw new Error(`Expected marker [${key}] = ${expectedValue} but marker was not found in body`);
    }
    if (actual !== String(expectedValue)) {
        throw new Error(`Expected marker [${key}] = ${expectedValue} but got ${actual}`);
    }
}

module.exports = { parseMarkers, expectMarker };
