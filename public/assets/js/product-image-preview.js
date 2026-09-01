(function () {
    'use strict';

    const MIN_WIDTH = 600;
    const MIN_HEIGHT = 1000;
    const RATIO_MIN = 0.55;
    const RATIO_MAX = 0.82;
    const SOURCE_WARNING_BYTES = 512000;
    const SOURCE_MAX_BYTES = 10485760;
    const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes < 0) {
            return '—';
        }

        if (bytes < 1024) {
            return bytes + ' B';
        }

        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function setText(root, selector, value) {
        const element = root.querySelector(selector);
        if (element) {
            element.textContent = value;
        }
    }

    function setStatus(root, type, message) {
        const status = root.querySelector('[data-preview-status]');
        if (!status) {
            return;
        }

        status.className = 'product-image-preview-status is-' + type;
        status.textContent = message;
    }

    function emitValidation(input, detail) {
        input.dispatchEvent(new CustomEvent('product-image-validation', {
            bubbles: false,
            detail: detail,
        }));
    }

    function resetPreview(root) {
        root.hidden = true;
        const image = root.querySelector('[data-preview-image]');
        if (image) {
            image.removeAttribute('src');
        }
    }

    function validateFile(root, file, width, height) {
        const ratio = height > 0 ? width / height : 0;
        const canEdit = width >= MIN_WIDTH && height >= MIN_HEIGHT;

        if (file.type && !ALLOWED_MIMES.includes(file.type)) {
            setStatus(root, 'error', 'Error: lejohet vetëm JPG, PNG ose WEBP.');
            return { valid: false, typeInvalid: true, ratioInvalid: false, canEdit: false };
        }

        if (file.size > SOURCE_MAX_BYTES) {
            setStatus(root, 'error', 'Error: file-i burim kalon limitin 10 MB.');
            return { valid: false, sizeInvalid: true, ratioInvalid: false, canEdit: false };
        }

        if (width < MIN_WIDTH || height < MIN_HEIGHT) {
            setStatus(root, 'error', 'Error: minimumi është 600×1000 px.');
            return { valid: false, dimensionsInvalid: true, ratioInvalid: false, canEdit: false };
        }

        if (ratio < RATIO_MIN || ratio > RATIO_MAX) {
            setStatus(root, 'error', 'Error: raporti duhet të jetë portrait, width/height 0.55–0.82.');
            return { valid: false, ratioInvalid: true, canEdit: canEdit };
        }

        if (file.size > SOURCE_WARNING_BYTES) {
            setStatus(
                root,
                'warning',
                'Warning: burimi është mbi 500 KB. Serveri do ta optimizojë; limiti final WEBP është 800 KB.'
            );
            return { valid: true, warning: true, ratioInvalid: false, canEdit: true };
        }

        setStatus(root, 'ok', 'OK: dimensionet, raporti dhe madhësia e burimit janë në rregull.');
        return { valid: true, ratioInvalid: false, canEdit: true };
    }

    document.querySelectorAll('[data-product-image-input]').forEach(function (input) {
        const targetId = input.getAttribute('data-preview-target');
        const root = targetId ? document.getElementById(targetId) : null;

        if (!root) {
            return;
        }

        let objectUrl = '';

        input.addEventListener('change', function () {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = '';
            }

            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                resetPreview(root);
                emitValidation(input, { valid: false, empty: true, ratioInvalid: false, canEdit: false });
                return;
            }

            root.hidden = false;
            setText(root, '[data-preview-name]', file.name || '—');
            setText(root, '[data-preview-size]', formatBytes(file.size));
            setText(root, '[data-preview-type]', file.type || 'I panjohur');
            setText(root, '[data-preview-dimensions]', 'Duke lexuar…');
            setText(root, '[data-preview-ratio]', 'Duke lexuar…');
            setStatus(root, 'loading', 'Po kontrollohet imazhi…');

            if (file.type && !ALLOWED_MIMES.includes(file.type)) {
                setText(root, '[data-preview-dimensions]', '—');
                setText(root, '[data-preview-ratio]', '—');
                setStatus(root, 'error', 'Error: lejohet vetëm JPG, PNG ose WEBP.');
                emitValidation(input, { valid: false, typeInvalid: true, ratioInvalid: false, canEdit: false });
                return;
            }

            objectUrl = URL.createObjectURL(file);
            const probe = new Image();

            probe.onload = function () {
                const width = probe.naturalWidth || 0;
                const height = probe.naturalHeight || 0;
                const ratio = height > 0 ? width / height : 0;
                const previewImage = root.querySelector('[data-preview-image]');

                if (previewImage) {
                    previewImage.src = objectUrl;
                    previewImage.alt = file.name || 'Preview i imazhit';
                }

                setText(root, '[data-preview-dimensions]', width + '×' + height + ' px');
                setText(root, '[data-preview-ratio]', ratio > 0 ? ratio.toFixed(3) : '—');

                const validation = validateFile(root, file, width, height);
                validation.width = width;
                validation.height = height;
                validation.ratio = ratio;
                emitValidation(input, validation);
            };

            probe.onerror = function () {
                setText(root, '[data-preview-dimensions]', '—');
                setText(root, '[data-preview-ratio]', '—');
                setStatus(root, 'error', 'Error: imazhi nuk u lexua dot nga browser-i.');
                emitValidation(input, { valid: false, unreadable: true, ratioInvalid: false, canEdit: false });
            };

            probe.src = objectUrl;
        });
    });
}());
