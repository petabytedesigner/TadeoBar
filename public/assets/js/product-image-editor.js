(function () {
    'use strict';

    const MIN_WIDTH = 600;
    const MIN_HEIGHT = 1000;
    const RATIO_MIN = 0.55;
    const RATIO_MAX = 0.82;
    const SOURCE_MAX_BYTES = 10485760;
    const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    const EDITOR_LIBRARY_SRC = '/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js?v=4.9.1';

    let editorLibraryPromise = null;

    function getSelectedMode(radios) {
        const selected = Array.from(radios).find(function (radio) {
            return radio.checked;
        });
        return selected ? selected.value : 'auto';
    }

    function setSelectedMode(radios, mode) {
        radios.forEach(function (radio) {
            radio.checked = radio.value === mode;
        });
    }

    function ensureEditorLibrary() {
        if (window.FilerobotImageEditor) {
            return Promise.resolve(window.FilerobotImageEditor);
        }

        if (editorLibraryPromise) {
            return editorLibraryPromise;
        }

        editorLibraryPromise = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = EDITOR_LIBRARY_SRC;
            script.async = true;
            script.setAttribute('data-filerobot-library', '1');
            script.onload = function () {
                if (window.FilerobotImageEditor) {
                    resolve(window.FilerobotImageEditor);
                    return;
                }
                editorLibraryPromise = null;
                reject(new Error('Skedari i editorit u ngarkua, por editori nuk u inicializua.'));
            };
            script.onerror = function () {
                editorLibraryPromise = null;
                reject(new Error('Editori i imazhit nuk u ngarkua dot.'));
            };
            document.head.appendChild(script);
        });

        return editorLibraryPromise;
    }

    function fileFromSavedImage(savedImage) {
        const mimeType = savedImage.mimeType || 'image/webp';
        const extension = (savedImage.extension || mimeType.split('/')[1] || 'webp').replace('jpeg', 'jpg');
        const fileName = 'bar-tadeo-edited-' + Date.now() + '.' + extension;

        if (savedImage.imageBase64) {
            const parts = savedImage.imageBase64.split(',');
            if (parts.length !== 2) {
                return Promise.reject(new Error('Editori nuk ktheu një imazh të vlefshëm.'));
            }

            const binary = atob(parts[1]);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i += 1) {
                bytes[i] = binary.charCodeAt(i);
            }

            return Promise.resolve(new File([bytes], fileName, {
                type: mimeType,
                lastModified: Date.now(),
            }));
        }

        if (savedImage.imageCanvas && typeof savedImage.imageCanvas.toBlob === 'function') {
            return new Promise(function (resolve, reject) {
                savedImage.imageCanvas.toBlob(function (blob) {
                    if (!blob) {
                        reject(new Error('Editori nuk arriti të krijojë imazhin final.'));
                        return;
                    }

                    resolve(new File([blob], fileName, {
                        type: blob.type || mimeType,
                        lastModified: Date.now(),
                    }));
                }, mimeType, typeof savedImage.quality === 'number' ? savedImage.quality : 0.92);
            });
        }

        return Promise.reject(new Error('Editori nuk ktheu të dhëna për imazhin final.'));
    }

    function replaceInputFile(input, file) {
        if (typeof DataTransfer !== 'function') {
            throw new Error('Shfletuesi nuk mbështet zëvendësimin e sigurt të skedarit pas përpunimit.');
        }

        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
    }

    function validateSourceFile(file) {
        if (!file) {
            return 'Zgjidh fillimisht një imazh.';
        }
        if (file.type && !ALLOWED_MIMES.includes(file.type)) {
            return 'Lejohen vetëm JPG, PNG ose WEBP.';
        }
        if (file.size <= 0 || file.size > SOURCE_MAX_BYTES) {
            return 'Imazhi burim duhet të jetë maksimumi 10 MB.';
        }
        return '';
    }

    function validateEditedResult(savedImage) {
        const width = Number(savedImage.width || (savedImage.imageCanvas && savedImage.imageCanvas.width) || 0);
        const height = Number(savedImage.height || (savedImage.imageCanvas && savedImage.imageCanvas.height) || 0);

        if (!width || !height) {
            return 'Dimensionet e rezultatit final nuk u lexuan dot.';
        }

        if (width < MIN_WIDTH || height < MIN_HEIGHT) {
            return 'Rezultati final duhet të jetë të paktën 600×1000 px.';
        }

        const ratio = width / height;
        if (ratio < RATIO_MIN || ratio > RATIO_MAX) {
            return 'Rezultati final duhet të jetë vertikal, me raport gjerësi/lartësi 0.55–0.82. Zgjidh 9:16, 2:3, 3:4 ose 4:5 te Prerja.';
        }

        return '';
    }

    function buildTranslations() {
        return {
            name: 'Emri',
            save: 'Apliko',
            saveAs: 'Ruaj si',
            back: 'Mbrapa',
            loading: 'Duke ngarkuar…',
            resetOperations: 'Rivendos të gjitha ndryshimet',
            changesLoseWarningHint: 'Nëse e rivendos, të gjitha ndryshimet do të humbasin. Të vazhdojmë?',
            discardChangesWarningHint: 'Nëse e mbyll editorin, ndryshimet e fundit nuk do të ruhen.',
            cancel: 'Anulo',
            apply: 'Apliko',
            warning: 'Kujdes',
            confirm: 'Konfirmo',
            discardChanges: 'Hidh ndryshimet',
            undoTitle: 'Zhbëj ndryshimin e fundit',
            redoTitle: 'Ribëj ndryshimin',
            showImageTitle: 'Shfaq imazhin origjinal',
            zoomInTitle: 'Zmadho',
            zoomOutTitle: 'Zvogëlo',
            toggleZoomMenuTitle: 'Kontrollet e zmadhimit',
            adjustTab: 'Rregullo',
            finetuneTab: 'Përmirëso',
            filtersTab: 'Filtra',
            watermarkTab: 'Shenjë uji',
            annotateTabLabel: 'Shënime',
            resize: 'Përmasa',
            resizeTab: 'Përmasa',
            imageName: 'Emri i imazhit',
            invalidImageError: 'Imazhi nuk është i vlefshëm.',
            uploadImageError: 'Gabim gjatë ngarkimit të imazhit.',
            cropTool: 'Prerje',
            original: 'Origjinal',
            custom: 'E personalizuar',
            square: 'Katror',
            landscape: 'Horizontal',
            portrait: 'Vertikal',
            ellipse: 'Elips',
            arrowTool: 'Shigjetë',
            blurTool: 'Mjegullim',
            brightnessTool: 'Ndriçim',
            contrastTool: 'Kontrast',
            unFlipX: 'Hiq kthimin horizontal',
            flipX: 'Kthe horizontalisht',
            unFlipY: 'Hiq kthimin vertikal',
            flipY: 'Kthe vertikalisht',
            hsvTool: 'Ngjyrat',
            hue: 'Toni',
            brightness: 'Ndriçim',
            saturation: 'Saturim',
            value: 'Vlera',
            importing: 'Duke importuar…',
            addImage: '+ Shto imazh',
            uploadImage: 'Ngarko imazh',
            fromGallery: 'Nga galeria',
            penTool: 'Laps',
            polygonTool: 'Poligon',
            rectangleTool: 'Drejtkëndësh',
            resizeWidthTitle: 'Gjerësia në piksel',
            resizeHeightTitle: 'Lartësia në piksel',
            toggleRatioLockTitle: 'Kyç/çkyç raportin',
            resetSize: 'Rikthe përmasat origjinale',
            rotateTool: 'Rrotullo',
            textTool: 'Tekst',
            fontFamily: 'Fonti',
            size: 'Madhësia',
            letterSpacing: 'Hapësira mes shkronjave',
            lineHeight: 'Lartësia e rreshtit',
            warmthTool: 'Ngrohtësi',
            addWatermark: '+ Shto shenjë uji',
            addTextWatermark: '+ Shto shenjë uji me tekst',
            uploadWatermark: 'Ngarko shenjë uji',
            addWatermarkAsText: 'Shto si tekst',
            opacity: 'Opaciteti',
            transparency: 'Transparenca',
            position: 'Pozicioni',
            saveAsModalTitle: 'Apliko imazhin',
            extension: 'Prapashtesa',
            format: 'Formati',
            nameIsRequired: 'Emri është i detyrueshëm.',
            quality: 'Cilësia',
            width: 'Gjerësia',
            height: 'Lartësia',
            actualSize: 'Madhësia reale (100%)',
            fitSize: 'Përshtat në ekran',
            download: 'Shkarko',
            barTadeo916: '9:16',
            barTadeo916Desc: '9:16',
            barTadeo23: '2:3',
            barTadeo23Desc: '2:3',
            barTadeo34: '3:4',
            barTadeo34Desc: '3:4',
            barTadeo45: '4:5',
            barTadeo45Desc: '4:5',
        };
    }

    document.querySelectorAll('[data-product-image-editor-root]').forEach(function (root) {
        const input = root.querySelector('[data-product-image-input]');
        const radios = root.querySelectorAll('[data-image-mode]');
        const editNowButton = root.querySelector('[data-edit-now]');
        const openEditorButton = root.querySelector('[data-open-image-editor]');
        const editorActions = root.querySelector('[data-editor-actions]');
        const notice = root.querySelector('[data-editor-notice]');
        const shell = document.querySelector('[data-product-image-editor-shell]');
        const editorContainer = shell ? shell.querySelector('[data-product-image-editor-container]') : null;
        const editorWarning = shell ? shell.querySelector('[data-product-image-editor-warning]') : null;
        const existingImage = root.getAttribute('data-existing-image') || '';
        const form = root.closest('form');

        if (!input || !radios.length || !shell || !editorContainer) {
            return;
        }

        let editorInstance = null;
        let editorObjectUrl = '';
        let hasAppliedEdit = false;
        let suppressInputEditor = false;
        let warningTimer = 0;

        function setNotice(message, type) {
            if (!notice) {
                return;
            }
            notice.hidden = !message;
            notice.className = 'product-image-editor-notice' + (type ? ' is-' + type : '');
            notice.textContent = message || '';
        }

        function showEditorWarning(message) {
            if (!editorWarning) {
                return;
            }
            window.clearTimeout(warningTimer);
            editorWarning.textContent = message;
            editorWarning.hidden = false;
            warningTimer = window.setTimeout(function () {
                editorWarning.hidden = true;
            }, 6500);
        }

        function currentFile() {
            return input.files && input.files[0] ? input.files[0] : null;
        }

        function syncModeUi() {
            const mode = getSelectedMode(radios);
            if (editorActions) {
                editorActions.hidden = mode !== 'edit' || (!currentFile() && !existingImage);
            }
            if (editNowButton && mode !== 'auto') {
                editNowButton.hidden = true;
            }
        }

        function closeEditor() {
            const instance = editorInstance;
            editorInstance = null;

            if (instance) {
                try {
                    instance.terminate();
                } catch (error) {
                    // The editor may already be terminated by its own close flow.
                }
            }

            if (editorObjectUrl) {
                URL.revokeObjectURL(editorObjectUrl);
                editorObjectUrl = '';
            }

            editorContainer.innerHTML = '';
            shell.hidden = true;
            shell.classList.remove('is-open');
            document.body.classList.remove('product-image-editor-open');
            if (editorWarning) {
                editorWarning.hidden = true;
            }
        }

        function applyEditedImage(savedImage) {
            const validationError = validateEditedResult(savedImage);
            if (validationError) {
                showEditorWarning(validationError);
                return;
            }

            fileFromSavedImage(savedImage).then(function (file) {
                if (file.size > SOURCE_MAX_BYTES) {
                    showEditorWarning('Rezultati i editorit kalon 10 MB. Ule cilësinë ose përmasat dhe provo përsëri.');
                    return;
                }

                replaceInputFile(input, file);
                hasAppliedEdit = true;
                suppressInputEditor = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                suppressInputEditor = false;
                closeEditor();
                syncModeUi();
                setNotice('Ndryshimet u aplikuan. Ky imazh do të ruhet me produktin.', 'ok');
            }).catch(function (error) {
                showEditorWarning(error && error.message ? error.message : 'Imazhi i përpunuar nuk u përgatit dot.');
            });
        }

        function renderEditor(source, FIE) {
            let editorSource = source;

            closeEditor();

            if (source instanceof File) {
                editorObjectUrl = URL.createObjectURL(source);
                editorSource = editorObjectUrl;
            }

            shell.hidden = false;
            shell.classList.add('is-open');
            document.body.classList.add('product-image-editor-open');

            const TABS = FIE.TABS;
            const TOOLS = FIE.TOOLS;
            const baseName = source instanceof File && source.name
                ? source.name.replace(/\.[^.]+$/, '')
                : 'bar-tadeo-product';

            const config = {
                source: editorSource,
                useBackendTranslations: false,
                language: 'sq',
                translations: buildTranslations(),
                defaultSavedImageName: baseName,
                defaultSavedImageType: 'webp',
                defaultSavedImageQuality: 0.92,
                closeAfterSave: false,
                avoidChangesNotSavedAlertOnLeave: false,
                tabsIds: [
                    TABS.ADJUST,
                    TABS.FINETUNE,
                    TABS.FILTERS,
                    TABS.RESIZE,
                    TABS.ANNOTATE,
                    TABS.WATERMARK,
                ],
                defaultTabId: TABS.ADJUST,
                defaultToolId: TOOLS.CROP,
                Crop: {
                    presetsItems: [
                        { titleKey: 'barTadeo916', descriptionKey: 'barTadeo916Desc', ratio: 9 / 16 },
                        { titleKey: 'barTadeo23', descriptionKey: 'barTadeo23Desc', ratio: 2 / 3 },
                        { titleKey: 'barTadeo34', descriptionKey: 'barTadeo34Desc', ratio: 3 / 4 },
                        { titleKey: 'barTadeo45', descriptionKey: 'barTadeo45Desc', ratio: 4 / 5 },
                    ],
                },
                Rotate: {
                    angle: 90,
                    componentType: 'slider',
                },
                annotationsCommon: {
                    fill: '#d8b86a',
                    stroke: '#d8b86a',
                },
                Text: {
                    text: 'Bar Tadeo',
                    fontFamily: 'Arial',
                    fonts: ['Arial', 'Tahoma', 'Sans-serif'],
                },
                theme: {
                    palette: {
                        'bg-secondary': '#111214',
                        'bg-primary': '#191b1f',
                        'bg-primary-active': '#25282e',
                        'accent-primary': '#d8b86a',
                        'accent-primary-active': '#e8cb82',
                        'icons-primary': '#f4f1e8',
                        'icons-secondary': '#c3beb0',
                        'borders-secondary': '#2d3036',
                        'borders-primary': '#41454d',
                        'borders-strong': '#d8b86a',
                        'warning': '#e3a85a',
                    },
                    typography: {
                        fontFamily: 'Arial, sans-serif',
                    },
                },
                onSave: function (savedImage) {
                    applyEditedImage(savedImage);
                },
            };

            editorInstance = new FIE(editorContainer, config);
            editorInstance.render({
                onClose: function () {
                    closeEditor();
                },
            });
        }

        function openEditor(source) {
            if (source instanceof File) {
                const sourceError = validateSourceFile(source);
                if (sourceError) {
                    setNotice(sourceError, 'error');
                    return;
                }
            }

            setNotice('Po ngarkohet editori…', '');
            ensureEditorLibrary().then(function (FIE) {
                setNotice('', '');
                renderEditor(source, FIE);
            }).catch(function (error) {
                setNotice(error && error.message ? error.message : 'Editori i imazhit nuk u ngarkua dot.', 'error');
            });
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                setNotice('', '');
                syncModeUi();
                if (radio.checked && radio.value === 'edit') {
                    const file = currentFile();
                    if (file) {
                        openEditor(file);
                    }
                }
            });
        });

        input.addEventListener('change', function () {
            const file = currentFile();
            if (!file) {
                hasAppliedEdit = false;
                setNotice('', '');
                syncModeUi();
                return;
            }

            if (!suppressInputEditor) {
                hasAppliedEdit = false;
                setNotice('', '');
            }

            syncModeUi();

            if (!suppressInputEditor && getSelectedMode(radios) === 'edit') {
                openEditor(file);
            }
        });

        input.addEventListener('product-image-validation', function (event) {
            if (!editNowButton) {
                return;
            }

            const detail = event.detail || {};
            editNowButton.hidden = !(getSelectedMode(radios) === 'auto' && detail.ratioInvalid === true && detail.canEdit === true);
        });

        if (editNowButton) {
            editNowButton.addEventListener('click', function () {
                const file = currentFile();
                if (!file) {
                    return;
                }
                setSelectedMode(radios, 'edit');
                setNotice('', '');
                syncModeUi();
                openEditor(file);
            });
        }

        if (openEditorButton) {
            openEditorButton.addEventListener('click', function () {
                setNotice('', '');
                const file = currentFile();
                if (file) {
                    openEditor(file);
                    return;
                }
                if (existingImage) {
                    openEditor(existingImage);
                }
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (getSelectedMode(radios) !== 'edit') {
                    return;
                }

                const file = currentFile();
                if (file && !hasAppliedEdit) {
                    event.preventDefault();
                    setNotice('Mënyra EDITO është aktive. Hape editorin dhe shtyp Apliko para se të ruash produktin.', 'error');
                    openEditor(file);
                }
            });
        }

        syncModeUi();
    });
}());
