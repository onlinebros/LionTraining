/*
 * Upload a video recorded somewhere else.
 *
 * Same wall as the studio: PHP will not take a 400 MB body. So the file is
 * sliced and fed through ChunkedUploader, which handles ordering and retries.
 * The only real difference is that here the bytes already exist, so the whole
 * file can be queued up front instead of arriving live.
 *
 * The browser's idea of duration and dimensions is sent as a hint, but the
 * server re-measures with ffprobe and its answer wins — a browser reports
 * nothing useful for a codec it cannot decode.
 */
(function () {
    'use strict';

    var config = window.UPLOAD_CONFIG;
    if (!config || typeof ChunkedUploader === 'undefined') return;

    var drop     = document.getElementById('drop');
    var input    = document.getElementById('file');
    var picked   = document.getElementById('picked');
    var nameEl   = document.getElementById('file-name');
    var metaEl   = document.getElementById('file-meta');
    var progress = document.getElementById('progress');
    var progText = document.getElementById('progress-text');
    var button   = document.getElementById('upload-btn');
    var statusEl = document.getElementById('status');

    if (!drop || !input) return;

    var file = null;
    var busy = false;

    function bytes(n) {
        if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }

    function say(message, tone) {
        statusEl.textContent = message;
        statusEl.className = 'alert alert-' + (tone || 'light') + ' py-2 mt-3';
        statusEl.style.display = message ? 'block' : 'none';
    }

    function choose(selected) {
        if (!selected) return;

        if (selected.size > config.maxBytes) {
            say('That file is ' + bytes(selected.size) + '. The limit is '
                + bytes(config.maxBytes) + '.', 'danger');
            return;
        }

        // Deliberately permissive: browsers often report an empty type for a
        // file dragged off a phone, and the server checks it properly anyway.
        if (selected.type && selected.type.indexOf('video') !== 0) {
            say('That does not look like a video file.', 'warning');
            return;
        }

        file = selected;
        nameEl.textContent = file.name;
        metaEl.textContent = bytes(file.size);
        picked.style.display = 'block';
        drop.style.display = 'none';
        button.disabled = false;
        say('');

        probe(file);
    }

    /** Ask the browser for duration and size — a hint only; the server re-measures. */
    function probe(selected) {
        var video = document.createElement('video');
        video.preload = 'metadata';

        video.addEventListener('loadedmetadata', function () {
            file.durationHint = Math.round(video.duration) || null;
            file.widthHint    = video.videoWidth || null;
            file.heightHint   = video.videoHeight || null;

            if (file.durationHint) {
                var m = Math.floor(file.durationHint / 60), s = file.durationHint % 60;
                metaEl.textContent = bytes(file.size) + ' · ' + m + ':' + (s < 10 ? '0' : '') + s;
            }

            URL.revokeObjectURL(video.src);
        });

        video.addEventListener('error', function () { URL.revokeObjectURL(video.src); });
        video.src = URL.createObjectURL(selected);
    }

    function reset() {
        file = null;
        input.value = '';
        picked.style.display = 'none';
        drop.style.display = '';
        button.disabled = true;
        progress.style.width = '0';
        progText.textContent = '';
    }

    // ── Picking ───────────────────────────────────────────────────────────
    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () { choose(input.files[0]); });

    ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (e) {
            e.preventDefault();
            drop.classList.add('is-over');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function (e) {
            e.preventDefault();
            drop.classList.remove('is-over');
        });
    });
    drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) choose(e.dataTransfer.files[0]);
    });

    document.getElementById('clear-btn').addEventListener('click', function () {
        if (busy) return;
        reset();
    });

    // ── Sending ───────────────────────────────────────────────────────────
    button.addEventListener('click', function () {
        if (!file || busy) return;

        busy = true;
        button.disabled = true;
        button.textContent = 'Uploading…';
        say('Uploading. Keep this tab open until it finishes.', 'info');

        var uploader = new ChunkedUploader({
            createUrl: config.createUrl,
            csrf: config.csrf,
            chunkBytes: config.chunkBytes,
            onProgress: function (sent) {
                var pct = Math.min(100, (sent / file.size) * 100);
                progress.style.width = pct + '%';
                progText.textContent = bytes(sent) + ' of ' + bytes(file.size)
                    + ' (' + pct.toFixed(0) + '%)';
            },
            onError: function (error) {
                busy = false;
                button.disabled = false;
                button.textContent = 'Upload';
                say(error.message, 'danger');
            }
        });

        uploader.open({
            title: document.getElementById('field-title').value || file.name,
            category_id: document.getElementById('field-category').value,
            source: 'upload',
            has_mic_audio: 0,
            has_system_audio: 0
        }).then(function () {
            // The bytes already exist, so hand the whole file over at once and
            // let the uploader slice it. Blob.slice is cheap — nothing is read
            // into memory until a chunk is actually sent.
            uploader.push(file);

            return uploader.finish({
                title: document.getElementById('field-title').value || file.name,
                description: document.getElementById('field-description').value || null,
                category_id: document.getElementById('field-category').value || null,
                mime: file.type || 'video/mp4',
                duration_seconds: file.durationHint || null,
                width: file.widthHint || null,
                height: file.heightHint || null
            });
        }).then(function (result) {
            say('Uploaded. Opening it…', 'success');
            window.location.href = result.redirect_url;
        }).catch(function (error) {
            busy = false;
            button.disabled = false;
            button.textContent = 'Upload';
            say(error.message || 'The upload failed.', 'danger');
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!busy) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
