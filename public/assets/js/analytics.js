(function () {
    'use strict';

    if (window.__tadeoAnalyticsTracked) {
        return;
    }

    if (window.location.pathname.indexOf('/tadeo-admin/') === 0) {
        return;
    }

    window.__tadeoAnalyticsTracked = true;

    var url = '/api/track-visit.php';

    try {
        if (navigator.sendBeacon) {
            var blob = new Blob(['{}'], { type: 'application/json' });
            navigator.sendBeacon(url, blob);
            return;
        }
    } catch (error) {
        // Fallback to fetch below.
    }

    try {
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: '{}'
        }).catch(function () {});
    } catch (error) {
        // Analytics must never break the public menu.
    }
})();
