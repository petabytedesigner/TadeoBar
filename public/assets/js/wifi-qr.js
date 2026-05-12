(function () {
    'use strict';

    const VERSION = 5;
    const SIZE = 21 + (VERSION - 1) * 4;
    const DATA_CODEWORDS = 108;
    const ECC_CODEWORDS = 26;
    const ECL_FORMAT_BITS_LOW = 1;
    const MASK_PATTERN = 0;

    const EXP = new Array(512);
    const LOG = new Array(256).fill(0);

    (function initGaloisField() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) {
                x ^= 0x11D;
            }
        }
        for (let i = 255; i < 512; i++) {
            EXP[i] = EXP[i - 255];
        }
    })();

    function gfMul(a, b) {
        return a === 0 || b === 0 ? 0 : EXP[LOG[a] + LOG[b]];
    }

    function polyMul(a, b) {
        const result = new Array(a.length + b.length - 1).fill(0);
        for (let i = 0; i < a.length; i++) {
            for (let j = 0; j < b.length; j++) {
                result[i + j] ^= gfMul(a[i], b[j]);
            }
        }
        return result;
    }

    function rsGenerator(degree) {
        let gen = [1];
        for (let i = 0; i < degree; i++) {
            gen = polyMul(gen, [1, EXP[i]]);
        }
        return gen;
    }

    function rsRemainder(data, degree) {
        const gen = rsGenerator(degree);
        const msg = data.slice();
        for (let i = 0; i < degree; i++) {
            msg.push(0);
        }

        for (let i = 0; i < data.length; i++) {
            const factor = msg[i];
            if (factor === 0) {
                continue;
            }
            for (let j = 0; j < gen.length; j++) {
                msg[i + j] ^= gfMul(gen[j], factor);
            }
        }

        return msg.slice(data.length);
    }

    class BitBuffer {
        constructor() {
            this.bits = [];
        }

        append(value, length) {
            for (let i = length - 1; i >= 0; i--) {
                this.bits.push(((value >>> i) & 1) !== 0);
            }
        }

        appendBytes(bytes) {
            bytes.forEach((byte) => this.append(byte, 8));
        }

        toCodewords() {
            const result = [];
            for (let i = 0; i < this.bits.length; i += 8) {
                let value = 0;
                for (let j = 0; j < 8; j++) {
                    value = (value << 1) | (this.bits[i + j] ? 1 : 0);
                }
                result.push(value);
            }
            return result;
        }
    }

    function utf8Bytes(text) {
        return Array.from(new TextEncoder().encode(text));
    }

    function makeDataCodewords(text) {
        const bytes = utf8Bytes(text);
        if (bytes.length > 106) {
            throw new Error('WiFi QR text is too long for the local QR generator.');
        }

        const bb = new BitBuffer();
        bb.append(0x4, 4); // Byte mode
        bb.append(bytes.length, 8); // Version 1-9 byte count length
        bb.appendBytes(bytes);

        const maxBits = DATA_CODEWORDS * 8;
        const terminator = Math.min(4, maxBits - bb.bits.length);
        for (let i = 0; i < terminator; i++) {
            bb.bits.push(false);
        }
        while (bb.bits.length % 8 !== 0) {
            bb.bits.push(false);
        }

        const data = bb.toCodewords();
        const pad = [0xEC, 0x11];
        let padIndex = 0;
        while (data.length < DATA_CODEWORDS) {
            data.push(pad[padIndex % 2]);
            padIndex++;
        }

        return data;
    }

    function createMatrix() {
        return Array.from({ length: SIZE }, () => new Array(SIZE).fill(false));
    }

    function setFunction(modules, functions, x, y, dark) {
        if (x < 0 || y < 0 || x >= SIZE || y >= SIZE) {
            return;
        }
        modules[y][x] = dark;
        functions[y][x] = true;
    }

    function drawFinder(modules, functions, x, y) {
        for (let dy = -1; dy <= 7; dy++) {
            for (let dx = -1; dx <= 7; dx++) {
                const xx = x + dx;
                const yy = y + dy;
                const inCore = dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6;
                const dark = inCore && (
                    dx === 0 || dx === 6 || dy === 0 || dy === 6 ||
                    (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4)
                );
                setFunction(modules, functions, xx, yy, dark);
            }
        }
    }

    function drawAlignment(modules, functions, cx, cy) {
        if (functions[cy][cx]) {
            return;
        }
        for (let dy = -2; dy <= 2; dy++) {
            for (let dx = -2; dx <= 2; dx++) {
                const dist = Math.max(Math.abs(dx), Math.abs(dy));
                setFunction(modules, functions, cx + dx, cy + dy, dist !== 1);
            }
        }
    }

    function reserveFormatAreas(modules, functions) {
        for (let i = 0; i <= 5; i++) setFunction(modules, functions, 8, i, false);
        setFunction(modules, functions, 8, 7, false);
        setFunction(modules, functions, 8, 8, false);
        setFunction(modules, functions, 7, 8, false);
        for (let i = 9; i < 15; i++) setFunction(modules, functions, 14 - i, 8, false);

        for (let i = 0; i < 8; i++) setFunction(modules, functions, SIZE - 1 - i, 8, false);
        for (let i = 8; i < 15; i++) setFunction(modules, functions, 8, SIZE - 15 + i, false);
        setFunction(modules, functions, 8, SIZE - 8, true);
    }

    function drawFunctionPatterns(modules, functions) {
        drawFinder(modules, functions, 0, 0);
        drawFinder(modules, functions, SIZE - 7, 0);
        drawFinder(modules, functions, 0, SIZE - 7);

        for (let i = 8; i < SIZE - 8; i++) {
            const dark = i % 2 === 0;
            setFunction(modules, functions, i, 6, dark);
            setFunction(modules, functions, 6, i, dark);
        }

        const align = [6, 30];
        align.forEach((x) => align.forEach((y) => drawAlignment(modules, functions, x, y)));
        reserveFormatAreas(modules, functions);
    }

    function maskBit(x, y) {
        return ((x + y) & 1) === 0;
    }

    function drawCodewords(modules, functions, codewords) {
        let bitIndex = 0;
        let upward = true;
        const totalBits = codewords.length * 8;

        for (let right = SIZE - 1; right >= 1; right -= 2) {
            if (right === 6) {
                right--;
            }

            for (let vert = 0; vert < SIZE; vert++) {
                const y = upward ? SIZE - 1 - vert : vert;
                for (let j = 0; j < 2; j++) {
                    const x = right - j;
                    if (functions[y][x]) {
                        continue;
                    }

                    let dark = false;
                    if (bitIndex < totalBits) {
                        const byte = codewords[Math.floor(bitIndex / 8)];
                        dark = ((byte >>> (7 - (bitIndex % 8))) & 1) !== 0;
                    }
                    if (maskBit(x, y)) {
                        dark = !dark;
                    }
                    modules[y][x] = dark;
                    bitIndex++;
                }
            }
            upward = !upward;
        }
    }

    function getFormatBits(eclBits, mask) {
        const data = (eclBits << 3) | mask;
        let bits = data << 10;
        const generator = 0x537;

        for (let i = 14; i >= 10; i--) {
            if (((bits >>> i) & 1) !== 0) {
                bits ^= generator << (i - 10);
            }
        }
        return ((data << 10) | bits) ^ 0x5412;
    }

    function getBit(value, index) {
        return ((value >>> index) & 1) !== 0;
    }

    function drawFormatBits(modules, functions) {
        const bits = getFormatBits(ECL_FORMAT_BITS_LOW, MASK_PATTERN);

        for (let i = 0; i <= 5; i++) setFunction(modules, functions, 8, i, getBit(bits, i));
        setFunction(modules, functions, 8, 7, getBit(bits, 6));
        setFunction(modules, functions, 8, 8, getBit(bits, 7));
        setFunction(modules, functions, 7, 8, getBit(bits, 8));
        for (let i = 9; i < 15; i++) setFunction(modules, functions, 14 - i, 8, getBit(bits, i));

        for (let i = 0; i < 8; i++) setFunction(modules, functions, SIZE - 1 - i, 8, getBit(bits, i));
        for (let i = 8; i < 15; i++) setFunction(modules, functions, 8, SIZE - 15 + i, getBit(bits, i));
        setFunction(modules, functions, 8, SIZE - 8, true);
    }

    function encode(text) {
        const data = makeDataCodewords(text);
        const ecc = rsRemainder(data, ECC_CODEWORDS);
        const codewords = data.concat(ecc);

        const modules = createMatrix();
        const functions = createMatrix();
        drawFunctionPatterns(modules, functions);
        drawCodewords(modules, functions, codewords);
        drawFormatBits(modules, functions);

        return modules;
    }

    function toSvg(modules) {
        const quiet = 4;
        const dim = modules.length + quiet * 2;
        const parts = [
            '<svg class="wifi-qr-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim + '" role="img" aria-label="WiFi QR Code" shape-rendering="crispEdges">',
            '<rect width="100%" height="100%" fill="#fff"/>'
        ];

        const path = [];
        for (let y = 0; y < modules.length; y++) {
            for (let x = 0; x < modules.length; x++) {
                if (modules[y][x]) {
                    path.push('M' + (x + quiet) + ',' + (y + quiet) + 'h1v1h-1z');
                }
            }
        }
        parts.push('<path d="' + path.join('') + '" fill="#000"/>');
        parts.push('</svg>');
        return parts.join('');
    }

    function renderBox(box) {
        const payload = box.getAttribute('data-qr-payload') || '';
        if (!payload) {
            box.textContent = 'QR nuk është i disponueshëm.';
            return;
        }

        try {
            box.innerHTML = toSvg(encode(payload));
        } catch (error) {
            box.textContent = 'QR nuk u krijua dot: ' + error.message;
        }
    }

    function init() {
        document.querySelectorAll('[data-qr-payload]').forEach(renderBox);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.TadeoWifiQr = { encode, toSvg };
})();
