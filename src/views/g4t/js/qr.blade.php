@verbatim
    /* ---------- QR codes (byte mode, versions 1–40, error correction M or L), drawn as SVG ---------- */

    var QR = (function () {
        // Indexed by version; [0] unused. From the QR code specification (ISO/IEC 18004), levels M and L.
        var ECC_PER_BLOCK = {
            M: [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
            L: [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30]
        };
        var BLOCKS = {
            M: [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
            L: [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25]
        };
        var FORMAT_LEVEL = { L: 1, M: 0 };

        function rawModules(ver) {
            var result = (16 * ver + 128) * ver + 64;
            if (ver >= 2) { var align = Math.floor(ver / 7) + 2; result -= (25 * align - 10) * align - 55; if (ver >= 7) { result -= 36; } }
            return result;
        }
        function dataCodewords(ver, ecl) { return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ecl][ver] * BLOCKS[ecl][ver]; }
        function gfMul(x, y) {
            var z = 0;
            for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11d); z ^= ((y >>> i) & 1) * x; }
            return z & 0xff;
        }
        function rsDivisor(degree) {
            var result = []; for (var i = 0; i < degree - 1; i++) { result.push(0); } result.push(1);
            var root = 1;
            for (var d = 0; d < degree; d++) {
                for (var j = 0; j < result.length; j++) { result[j] = gfMul(result[j], root); if (j + 1 < result.length) { result[j] ^= result[j + 1]; } }
                root = gfMul(root, 0x02);
            }
            return result;
        }
        function rsRemainder(data, divisor) {
            var result = divisor.map(function () { return 0; });
            data.forEach(function (b) {
                var factor = b ^ result.shift(); result.push(0);
                divisor.forEach(function (coef, i) { result[i] ^= gfMul(coef, factor); });
            });
            return result;
        }
        function utf8(text) { var s = unescape(encodeURIComponent(text)), out = []; for (var i = 0; i < s.length; i++) { out.push(s.charCodeAt(i)); } return out; }
        function alignmentPositions(ver, size) {
            if (ver === 1) { return []; }
            var count = Math.floor(ver / 7) + 2, step = ver === 32 ? 26 : Math.ceil((ver * 4 + 4) / (count * 2 - 2)) * 2, result = [6];
            for (var pos = size - 7; result.length < count; pos -= step) { result.splice(1, 0, pos); }
            return result;
        }

        function encode(text) {
            var bytes = utf8(text), ver, ecl;
            ['M', 'L'].some(function (level) {
                for (var v = 1; v <= 40; v++) {
                    var bits = 4 + (v <= 9 ? 8 : 16) + bytes.length * 8;
                    if (bits <= dataCodewords(v, level) * 8) { ver = v; ecl = level; return true; }
                }
                return false;
            });
            if (!ver) { return null; }

            // Data bits: byte mode, length, bytes, terminator, padding.
            var bb = [], put = function (val, len) { for (var i = len - 1; i >= 0; i--) { bb.push((val >>> i) & 1); } };
            put(4, 4); put(bytes.length, ver <= 9 ? 8 : 16); bytes.forEach(function (b) { put(b, 8); });
            var capacity = dataCodewords(ver, ecl) * 8;
            put(0, Math.min(4, capacity - bb.length));
            put(0, (8 - bb.length % 8) % 8);
            for (var pad = 0xec; bb.length < capacity; pad ^= 0xec ^ 0x11) { put(pad, 8); }
            var codewords = [];
            for (var i = 0; i < bb.length; i += 8) { var byte = 0; for (var j = 0; j < 8; j++) { byte = (byte << 1) | bb[i + j]; } codewords.push(byte); }

            // Blocks, error correction, interleaving.
            var numBlocks = BLOCKS[ecl][ver], eccLen = ECC_PER_BLOCK[ecl][ver], raw = Math.floor(rawModules(ver) / 8);
            var shortBlocks = numBlocks - raw % numBlocks, shortLen = Math.floor(raw / numBlocks) - eccLen, divisor = rsDivisor(eccLen), blocks = [], k = 0;
            for (var bi = 0; bi < numBlocks; bi++) {
                var dat = codewords.slice(k, k + shortLen + (bi < shortBlocks ? 0 : 1));
                k += dat.length;
                blocks.push({ data: dat, ecc: rsRemainder(dat, divisor) });
            }
            var all = [];
            for (var di = 0; di <= shortLen; di++) { blocks.forEach(function (b) { if (di < b.data.length) { all.push(b.data[di]); } }); }
            for (var ei = 0; ei < eccLen; ei++) { blocks.forEach(function (b) { all.push(b.ecc[ei]); }); }

            // Matrix with function patterns.
            var size = ver * 4 + 17, modules = [], fn = [];
            for (var y = 0; y < size; y++) { modules.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
            var setFn = function (x, y, dark) { modules[y][x] = dark; fn[y][x] = true; };
            for (var t = 0; t < size; t++) { setFn(6, t, t % 2 === 0); setFn(t, 6, t % 2 === 0); }
            [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
                for (var dy = -4; dy <= 4; dy++) { for (var dx = -4; dx <= 4; dx++) {
                    var xx = c[0] + dx, yy = c[1] + dy, dist = Math.max(Math.abs(dx), Math.abs(dy));
                    if (xx >= 0 && xx < size && yy >= 0 && yy < size) { setFn(xx, yy, dist !== 2 && dist !== 4); }
                } }
            });
            var aligns = alignmentPositions(ver, size), last = aligns.length - 1;
            aligns.forEach(function (ay, i) { aligns.forEach(function (ax, j) {
                if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) { return; }
                for (var dy = -2; dy <= 2; dy++) { for (var dx = -2; dx <= 2; dx++) { setFn(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1); } }
            }); });
            var drawFormat = function (mask) {
                var data = (FORMAT_LEVEL[ecl] << 3) | mask, rem = data;
                for (var i = 0; i < 10; i++) { rem = (rem << 1) ^ ((rem >>> 9) * 0x537); }
                var bits = ((data << 10) | rem) ^ 0x5412, bit = function (i) { return ((bits >>> i) & 1) !== 0; };
                for (var a = 0; a <= 5; a++) { setFn(8, a, bit(a)); }
                setFn(8, 7, bit(6)); setFn(8, 8, bit(7)); setFn(7, 8, bit(8));
                for (var b = 9; b < 15; b++) { setFn(14 - b, 8, bit(b)); }
                for (var c = 0; c < 8; c++) { setFn(size - 1 - c, 8, bit(c)); }
                for (var d = 8; d < 15; d++) { setFn(8, size - 15 + d, bit(d)); }
                setFn(8, size - 8, true);
            };
            drawFormat(0);
            if (ver >= 7) {
                var rem = ver;
                for (var r = 0; r < 12; r++) { rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25); }
                var vbits = ver << 12 | rem;
                for (var vi = 0; vi < 18; vi++) { var on = ((vbits >>> vi) & 1) !== 0, a1 = size - 11 + vi % 3, b1 = Math.floor(vi / 3); setFn(a1, b1, on); setFn(b1, a1, on); }
            }

            // Data in the zigzag order.
            var bitIndex = 0;
            for (var right = size - 1; right >= 1; right -= 2) {
                if (right === 6) { right = 5; }
                for (var vert = 0; vert < size; vert++) {
                    for (var jj = 0; jj < 2; jj++) {
                        var x = right - jj, upward = ((right + 1) & 2) === 0, yv = upward ? size - 1 - vert : vert;
                        if (!fn[yv][x] && bitIndex < all.length * 8) { modules[yv][x] = ((all[bitIndex >>> 3] >>> (7 - (bitIndex & 7))) & 1) !== 0; bitIndex++; }
                    }
                }
            }

            // The mask with the lowest penalty.
            var MASKS = [
                function (x, y) { return (x + y) % 2 === 0; }, function (x, y) { return y % 2 === 0; }, function (x) { return x % 3 === 0; },
                function (x, y) { return (x + y) % 3 === 0; }, function (x, y) { return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; },
                function (x, y) { return x * y % 2 + x * y % 3 === 0; }, function (x, y) { return (x * y % 2 + x * y % 3) % 2 === 0; },
                function (x, y) { return ((x + y) % 2 + x * y % 3) % 2 === 0; }
            ];
            var applyMask = function (m) { for (var y = 0; y < size; y++) { for (var x = 0; x < size; x++) { if (!fn[y][x] && MASKS[m](x, y)) { modules[y][x] = !modules[y][x]; } } } };
            var penalty = function () {
                var score = 0, dark = 0, line = function (get) {
                    for (var i = 0; i < size; i++) {
                        var run = 1, row = [];
                        for (var j = 0; j < size; j++) { row.push(get(i, j) ? 1 : 0); }
                        for (var j2 = 1; j2 <= size; j2++) {
                            if (j2 < size && row[j2] === row[j2 - 1]) { run++; } else { if (run >= 5) { score += run - 2; } run = 1; }
                        }
                        var str = row.join('');
                        ['10111010000', '00001011101'].forEach(function (p) { var at = str.indexOf(p); while (at !== -1) { score += 40; at = str.indexOf(p, at + 1); } });
                    }
                };
                line(function (i, j) { return modules[i][j]; });
                line(function (i, j) { return modules[j][i]; });
                for (var y = 0; y < size - 1; y++) { for (var x = 0; x < size - 1; x++) { var c = modules[y][x]; if (c === modules[y][x + 1] && c === modules[y + 1][x] && c === modules[y + 1][x + 1]) { score += 3; } } }
                for (var yy = 0; yy < size; yy++) { for (var xx = 0; xx < size; xx++) { if (modules[yy][xx]) { dark++; } } }
                return score + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
            };
            var best = 0, bestScore = Infinity;
            for (var m = 0; m < 8; m++) { applyMask(m); drawFormat(m); var p = penalty(); if (p < bestScore) { bestScore = p; best = m; } applyMask(m); }
            applyMask(best); drawFormat(best);
            return { size: size, version: ver, level: ecl, modules: modules };
        }

        function svg(text, px) {
            var qr = encode(text);
            if (!qr) { return null; }
            var quiet = 4, n = qr.size + quiet * 2, path = '';
            qr.modules.forEach(function (row, y) { row.forEach(function (dark, x) { if (dark) { path += 'M' + (x + quiet) + ' ' + (y + quiet) + 'h1v1h-1z'; } }); });
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' + px + '" height="' + px + '" viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="' + n + '" height="' + n + '" fill="#fff"/><path d="' + path + '" fill="#000"/></svg>';
        }
        return { encode: encode, svg: svg };
    })();

    /* ---------- share dialog: link + QR ---------- */

    OVERLAYS.share = function (o) {
        var code = QR.svg(o.url, 248), host = location.hostname;
        var local = /^(localhost|127\.|\[?::1\]?$|0\.0\.0\.0)/.test(host);
        return '<div class="g4t-login-dialog g4t-share" role="dialog" aria-modal="true" aria-labelledby="g4t-share-title"><div class="g4t-overlay-body">' +
            '<div class="g4t-env-head"><h2 id="g4t-share-title">' + esc(o.title) + '</h2><button type="button" class="g4t-file-clear" data-action="close-overlay" aria-label="Close">×</button></div>' +
            '<p class="g4t-muted" style="margin:0;font-size:13px">' + esc(o.hint) + '</p>' +
            '<div class="g4t-share-qr">' + (code || '<div class="g4t-muted">This link is too long for a QR code.</div>') + '</div>' +
            (local ? '<div class="g4t-hint">' + ICON.alert.replace('width="15" height="15"', 'width="14" height="14"') + '<span>The page runs on ' + esc(host) + ', so a phone cannot open it. Open the docs from your computer’s network address (e.g. http://192.168.1.10:' + esc(location.port || '80') + ') to share it with a device.</span></div>' : '') +
            '<div class="g4t-token-field"><input class="g4t-mono" readonly value="' + esc(o.url) + '" aria-label="Link" onfocus="this.select()"><button type="button" class="g4t-btn g4t-btn-primary" data-action="share-copy">' + ICON.copy + 'Copy</button></div>' +
            '</div></div>';
    };
    ACTIONS['share-copy'] = function () { if (state.overlay && state.overlay.url) { copy(state.overlay.url, 'Link copied'); } };
@endverbatim
