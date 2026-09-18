/*
 * Screen recording studio.
 *
 * Captures the screen with getDisplayMedia and, optionally, the webcam with
 * getUserMedia. The two are composited onto a canvas every frame — the webcam
 * is *baked into* the chosen corner rather than overlaid at playback time, so
 * the stored file is a single self-contained video that plays anywhere.
 *
 * The capture uploads while it is still running: MediaRecorder hands us the
 * file in timeslices, those are buffered into fixed-size chunks and POSTed in
 * order. A 40-minute walkthrough is therefore nearly finished uploading by the
 * time the presenter hits stop, and no single request has to carry the file.
 */
(function () {
    'use strict';

    var CORNERS = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];

    /*
     * MP4 first, on purpose. MediaRecorder's WebM output carries no duration in
     * its header, which leaves seek bars broken in several players; the MP4
     * muxer (Chrome 126+, Safari) writes a proper one. WebM is the fallback for
     * browsers without it — the video still plays, it just may not scrub.
     */
    var MIME_PREFERENCES = [
        'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
        'video/mp4',
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm'
    ];

    var WEBCAM_SIZES = { small: 0.16, medium: 0.22, large: 0.3 };

    // id is the DOM suffix, value is what the server stores as `source`.
    var MODES = [
        { id: 'screen',        value: 'screen' },
        { id: 'screen-camera', value: 'screen+camera' },
        { id: 'camera',        value: 'camera' }
    ];

    function pickMimeType() {
        if (typeof MediaRecorder === 'undefined') return null;
        for (var i = 0; i < MIME_PREFERENCES.length; i++) {
            if (MediaRecorder.isTypeSupported(MIME_PREFERENCES[i])) return MIME_PREFERENCES[i];
        }
        return null;
    }

    function bytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }

    function clock(seconds) {
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = Math.floor(seconds % 60);
        var mm = (h > 0 && m < 10 ? '0' : '') + m;
        return (h > 0 ? h + ':' : '') + mm + ':' + (s < 10 ? '0' : '') + s;
    }

    /* ── Chunked uploader ──────────────────────────────────────────────────── */
    /* Lives in chunked-uploader.js, shared with the upload page. */

    /* ── Studio ────────────────────────────────────────────────────────────── */

    function Studio(config) {
        this.config = config;
        this.settings = {
            mode: null,               // screen | screen+camera | camera
            webcamPosition: 'bottom-right',
            webcamSize: 'medium',
            webcamShape: 'rounded',
            mirror: true,
            includeCamera: false,
            includeScreen: false,
            includeMic: true,
            includeSystemAudio: true
        };

        this.displayStream = null;
        this.cameraStream = null;
        this.screenVideo = document.createElement('video');
        this.cameraVideo = document.createElement('video');
        [this.screenVideo, this.cameraVideo].forEach(function (v) {
            v.muted = true;
            v.playsInline = true;
        });

        this.canvas = document.getElementById('recorder-canvas');
        this.ctx = this.canvas.getContext('2d');
        this.frame = null;

        this.recorder = null;
        this.uploader = null;
        this.state = 'idle';       // idle | recording | paused | finishing
        this.startedAt = 0;
        this.pausedFor = 0;
        this.pausedAt = 0;
        this.poster = null;
        this.mimeType = pickMimeType();

        this.bind();
        this.render();
    }

    Studio.prototype.el = function (id) {
        return document.getElementById(id);
    };

    Studio.prototype.status = function (message, tone) {
        var box = this.el('recorder-status');
        box.textContent = message;
        box.className = 'alert alert-' + (tone || 'light') + ' py-2 mb-3';
        box.style.display = message ? 'block' : 'none';
    };

    Studio.prototype.bind = function () {
        var self = this;

        MODES.forEach(function (mode) {
            var button = self.el('mode-' + mode.id);
            if (!button) return;
            button.addEventListener('click', function () { self.setMode(mode.value); });
        });
        this.el('btn-start').addEventListener('click', function () { self.countdownThenStart(); });
        this.el('btn-pause').addEventListener('click', function () { self.togglePause(); });
        this.el('btn-stop').addEventListener('click', function () { self.stop(); });
        this.el('btn-discard').addEventListener('click', function () { self.discard(); });

        CORNERS.forEach(function (corner) {
            var button = self.el('corner-' + corner);
            if (!button) return;
            button.addEventListener('click', function () {
                self.settings.webcamPosition = corner;
                self.render();
            });
        });

        this.el('webcam-size').addEventListener('change', function (e) {
            self.settings.webcamSize = e.target.value;
        });
        this.el('webcam-shape').addEventListener('change', function (e) {
            self.settings.webcamShape = e.target.value;
        });
        this.el('webcam-mirror').addEventListener('change', function (e) {
            self.settings.mirror = e.target.checked;
        });
        this.el('include-mic').addEventListener('change', function (e) {
            self.settings.includeMic = e.target.checked;
        });
        this.el('include-system-audio').addEventListener('change', function (e) {
            self.settings.includeSystemAudio = e.target.checked;
        });

        window.addEventListener('beforeunload', function (event) {
            if (self.state === 'idle') return;
            event.preventDefault();
            event.returnValue = '';
        });
    };

    /* ── Sources ───────────────────────────────────────────────────────────── */

    /**
     * Choose what gets recorded: 'screen', 'screen+camera' or 'camera'.
     *
     * Each mode acquires only what it needs, so recording a straight-to-camera
     * announcement never opens a screen-share dialog at all.
     */
    Studio.prototype.setMode = function (mode) {
        var self = this;
        var needScreen = mode !== 'camera';
        var needCamera = mode !== 'screen';
        var chain = Promise.resolve();

        if (needScreen && !this.displayStream) {
            chain = chain.then(function () { return self.acquireScreen(); });
        }
        if (needCamera && !this.cameraStream) {
            chain = chain.then(function () { return self.acquireCamera(); });
        }

        chain.then(function () {
            self.settings.mode = mode;
            self.settings.includeScreen = needScreen && !!self.displayStream;
            self.settings.includeCamera = needCamera && !!self.cameraStream;

            // Drop a share we no longer need so the browser's "you are sharing"
            // bar goes away instead of sitting there implying we are still
            // watching the screen.
            if (!needScreen && self.displayStream && self.state === 'idle') {
                self.displayStream.getTracks().forEach(function (t) { t.stop(); });
                self.displayStream = null;
            }

            self.sizeCanvas();
            self.startCompositing();
            self.render();

            self.status(mode === 'camera'
                ? 'Webcam ready. Press Start when you are.'
                : 'Ready. Press Start when you are.', 'success');
        }).catch(function (error) {
            self.status(error.message, 'warning');
            self.render();
        });
    };

    Studio.prototype.acquireScreen = function () {
        var self = this;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            return Promise.reject(new Error(
                'This browser cannot capture the screen. Use Chrome, Edge or Firefox on a desktop.'
            ));
        }

        return navigator.mediaDevices.getDisplayMedia({
            video: { frameRate: { ideal: 30, max: 30 } },
            audio: true
        }).then(function (stream) {
            self.displayStream = stream;
            self.screenVideo.srcObject = stream;

            // The browser's own "Stop sharing" bar bypasses our UI entirely.
            // Treat it as the stop button so the upload still gets finalised.
            stream.getVideoTracks()[0].addEventListener('ended', function () {
                self.settings.includeScreen = false;
                if (self.state === 'recording' || self.state === 'paused') self.stop();
                else self.render();
            });

            return self.readyToDraw(self.screenVideo);
        }).catch(function (error) {
            throw new Error(error && error.name === 'NotAllowedError'
                ? 'Screen sharing was cancelled or blocked.'
                : 'The screen could not be captured.');
        });
    };

    Studio.prototype.acquireCamera = function () {
        var self = this;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Promise.reject(new Error('This browser cannot open a webcam.'));
        }

        return navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: this.settings.includeMic
        }).then(function (stream) {
            self.cameraStream = stream;
            self.cameraVideo.srcObject = stream;
            return self.readyToDraw(self.cameraVideo);
        }).catch(function (error) {
            var name = error && error.name;

            // These three are the ones people actually hit, and each has a
            // different fix — "check your permissions" helps with none of them.
            if (name === 'NotAllowedError') {
                throw new Error(
                    'The browser blocked the webcam. Click the camera icon in the address bar and allow it, then try again.'
                );
            }
            if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                throw new Error('No webcam was found on this computer.');
            }
            if (name === 'NotReadableError') {
                throw new Error('The webcam is already in use by another app — close Zoom, Teams or Meet and try again.');
            }

            throw new Error('The webcam could not be opened' + (name ? ' (' + name + ').' : '.'));
        });
    };

    /**
     * Resolve once a video element actually knows its dimensions.
     *
     * Sizing the canvas before this fires is what produced a mis-shaped frame:
     * videoWidth/videoHeight are 0 until metadata arrives.
     */
    Studio.prototype.readyToDraw = function (video) {
        video.play();

        if (video.videoWidth && video.videoHeight) return Promise.resolve();

        return new Promise(function (resolve) {
            var done = false;
            var finish = function () {
                if (done) return;
                done = true;
                resolve();
            };

            video.addEventListener('loadedmetadata', finish, { once: true });
            // Never hang the UI on a metadata event that does not come.
            setTimeout(finish, 3000);
        });
    };

    /* ── Compositing ───────────────────────────────────────────────────────── */

    /**
     * Shape the canvas to the source that fills it.
     *
     * Read from the video element's own videoWidth/videoHeight, NOT from the
     * track's getSettings(). getSettings() reports the display being shared,
     * which is not the same as the frame we receive when a single window or tab
     * is picked — trusting it gave the canvas the wrong aspect ratio and the
     * sides of the picture got cropped away.
     */
    Studio.prototype.sizeCanvas = function () {
        var source = this.settings.mode === 'camera' ? this.cameraVideo : this.screenVideo;

        var width = source.videoWidth || 1280;
        var height = source.videoHeight || 720;

        // Cap at 1080p. A 4K screen share triples the bitrate for detail nobody
        // watching a training video will ever see, and blows through the size
        // limit on a long recording.
        if (height > 1080) {
            width = Math.round(width * (1080 / height));
            height = 1080;
        }

        // H.264 encoders reject odd dimensions.
        width -= width % 2;
        height -= height % 2;

        // Resizing mid-recording would corrupt the stream the encoder is already
        // producing, so the shape is fixed once recording starts.
        if (this.state !== 'idle') return;

        this.canvas.width = width;
        this.canvas.height = height;
    };

    Studio.prototype.startCompositing = function () {
        if (this.frame) return;
        var self = this;

        (function draw() {
            self.frame = requestAnimationFrame(draw);
            self.drawFrame();
        })();
    };

    Studio.prototype.drawFrame = function () {
        var ctx = this.ctx;
        var w = this.canvas.width;
        var h = this.canvas.height;

        ctx.fillStyle = '#0b1020';
        ctx.fillRect(0, 0, w, h);

        if (this.settings.includeScreen && this.screenVideo.readyState >= 2) {
            // CONTAIN, never cover. Cropping a face to fill a frame is fine;
            // cropping a screen share silently eats whatever was down the left
            // and right edges — a sidebar, a column of numbers — and the
            // presenter cannot tell from the preview that it is gone.
            this.drawContain(this.screenVideo, 0, 0, w, h);
        } else if (this.settings.includeCamera && this.cameraVideo.readyState >= 2 && !this.settings.includeScreen) {
            // Webcam-only recording: the camera is the whole frame. The canvas
            // was shaped to the camera, so contain and cover agree here — and
            // if the camera reports an unexpected ratio, contain is still the
            // one that shows all of the speaker.
            this.drawContain(this.cameraVideo, 0, 0, w, h, this.settings.mirror);
            return;
        }

        if (this.settings.includeCamera && this.settings.includeScreen && this.cameraVideo.readyState >= 2) {
            this.drawWebcamPip();
        }
    };

    /** Fit a video inside a box, letterboxing rather than cropping. */
    Studio.prototype.drawContain = function (video, x, y, w, h, mirror) {
        var vw = video.videoWidth;
        var vh = video.videoHeight;
        if (!vw || !vh) return;

        var scale = Math.min(w / vw, h / vh);
        var dw = vw * scale;
        var dh = vh * scale;
        var dx = x + (w - dw) / 2;
        var dy = y + (h - dh) / 2;

        this.paint(video, dx, dy, dw, dh, mirror, x, w);
    };

    /** Fill a box with a video, cropping the overflow rather than squashing it. */
    Studio.prototype.drawCover = function (video, x, y, w, h, mirror) {
        var vw = video.videoWidth;
        var vh = video.videoHeight;
        if (!vw || !vh) return;

        var scale = Math.max(w / vw, h / vh);
        var dw = vw * scale;
        var dh = vh * scale;
        var dx = x + (w - dw) / 2;
        var dy = y + (h - dh) / 2;

        this.paint(video, dx, dy, dw, dh, mirror, x, w);
    };

    /** The one place a frame actually reaches the canvas, mirrored or not. */
    Studio.prototype.paint = function (video, dx, dy, dw, dh, mirror, boxX, boxW) {
        var ctx = this.ctx;

        if (!mirror) {
            ctx.drawImage(video, dx, dy, dw, dh);
            return;
        }

        // Reflect about the box's own centre line so the flip stays inside the
        // frame wherever that frame sits.
        ctx.save();
        ctx.translate(boxX + boxW / 2, 0);
        ctx.scale(-1, 1);
        ctx.translate(-(boxX + boxW / 2), 0);
        ctx.drawImage(video, dx, dy, dw, dh);
        ctx.restore();
    };

    Studio.prototype.drawWebcamPip = function () {
        var ctx = this.ctx;
        var w = this.canvas.width;
        var h = this.canvas.height;

        var boxW = Math.round(w * (WEBCAM_SIZES[this.settings.webcamSize] || WEBCAM_SIZES.medium));
        var boxH = this.settings.webcamShape === 'circle'
            ? boxW
            : Math.round(boxW * (this.cameraVideo.videoHeight / this.cameraVideo.videoWidth || 0.5625));

        var margin = Math.round(w * 0.02);
        var left = this.settings.webcamPosition.indexOf('left') !== -1;
        var top = this.settings.webcamPosition.indexOf('top') !== -1;
        var x = left ? margin : w - boxW - margin;
        var y = top ? margin : h - boxH - margin;

        ctx.save();

        ctx.shadowColor = 'rgba(0,0,0,0.45)';
        ctx.shadowBlur = Math.round(w * 0.012);
        ctx.shadowOffsetY = Math.round(w * 0.004);

        ctx.beginPath();
        if (this.settings.webcamShape === 'circle') {
            ctx.arc(x + boxW / 2, y + boxH / 2, boxW / 2, 0, Math.PI * 2);
        } else {
            var radius = Math.round(boxW * 0.06);
            roundRect(ctx, x, y, boxW, boxH, radius);
        }

        // Fill before clipping: a shadow is not drawn for a clipped image, so
        // the halo has to come from a solid shape underneath it.
        ctx.fillStyle = '#000';
        ctx.fill();
        ctx.shadowColor = 'transparent';
        ctx.clip();

        this.drawCover(this.cameraVideo, x, y, boxW, boxH, this.settings.mirror);

        ctx.restore();

        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.85)';
        ctx.lineWidth = Math.max(2, Math.round(w * 0.0022));
        ctx.beginPath();
        if (this.settings.webcamShape === 'circle') {
            ctx.arc(x + boxW / 2, y + boxH / 2, boxW / 2, 0, Math.PI * 2);
        } else {
            roundRect(ctx, x, y, boxW, boxH, Math.round(boxW * 0.06));
        }
        ctx.stroke();
        ctx.restore();
    };

    function roundRect(ctx, x, y, w, h, r) {
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    /* ── Audio ─────────────────────────────────────────────────────────────── */

    Studio.prototype.buildAudioTrack = function () {
        var sources = [];

        if (this.settings.includeSystemAudio && this.displayStream) {
            var systemTracks = this.displayStream.getAudioTracks();
            if (systemTracks.length) sources.push({ stream: new MediaStream(systemTracks), gain: 0.8 });
        }

        if (this.settings.includeMic && this.cameraStream) {
            var micTracks = this.cameraStream.getAudioTracks();
            if (micTracks.length) sources.push({ stream: new MediaStream(micTracks), gain: 1 });
        }

        if (!sources.length) return null;

        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        this.audioContext = new AudioContextClass();
        var destination = this.audioContext.createMediaStreamDestination();
        var self = this;

        sources.forEach(function (source) {
            var node = self.audioContext.createMediaStreamSource(source.stream);
            var gain = self.audioContext.createGain();
            // System audio sits under the narration rather than competing with
            // it — the voice is the point of a how-to.
            gain.gain.value = source.gain;
            node.connect(gain).connect(destination);
        });

        return destination.stream.getAudioTracks()[0] || null;
    };

    /* ── Recording ─────────────────────────────────────────────────────────── */

    Studio.prototype.countdownThenStart = function () {
        if (!this.settings.includeScreen && !this.settings.includeCamera) {
            this.status('Choose what to record first — screen, screen with webcam, or webcam only.', 'warning');
            return;
        }

        if (!this.mimeType) {
            this.status('This browser has no video recorder. Use Chrome, Edge or Firefox on a desktop.', 'danger');
            return;
        }

        var self = this;
        var overlay = this.el('recorder-countdown');
        var count = 3;

        overlay.style.display = 'flex';
        overlay.textContent = String(count);

        var tick = setInterval(function () {
            count -= 1;
            if (count > 0) {
                overlay.textContent = String(count);
                return;
            }
            clearInterval(tick);
            overlay.style.display = 'none';
            self.start();
        }, 900);
    };

    Studio.prototype.start = function () {
        var self = this;

        var stream = this.canvas.captureStream(30);
        var audioTrack = this.buildAudioTrack();
        if (audioTrack) stream.addTrack(audioTrack);

        this.uploader = new ChunkedUploader({
            createUrl: this.config.createUrl,
            csrf: this.config.csrf,
            chunkBytes: this.config.chunkBytes,
            onProgress: function (uploaded, pending) { self.renderProgress(uploaded, pending); },
            onError: function (error) { self.status('Upload problem: ' + error.message, 'danger'); }
        });

        this.uploader.open({
            title: this.el('field-title').value,
            category_id: this.el('field-category').value,
            source: this.settings.mode || 'screen',
            webcam_position: this.settings.webcamPosition,
            has_mic_audio: this.settings.includeMic && this.cameraStream ? 1 : 0,
            has_system_audio: this.settings.includeSystemAudio && this.displayStream ? 1 : 0
        }).then(function () {
            self.recorder = new MediaRecorder(stream, {
                mimeType: self.mimeType,
                videoBitsPerSecond: 2500000,
                audioBitsPerSecond: 128000
            });

            self.recorder.ondataavailable = function (event) {
                self.uploader.push(event.data);
            };

            self.recorder.onstop = function () {
                self.completeUpload();
            };

            // 3-second slices: small enough that the upload keeps pace with the
            // capture, large enough not to spam the queue with tiny blobs.
            self.recorder.start(3000);

            self.state = 'recording';
            self.startedAt = Date.now();
            self.pausedFor = 0;
            self.status('Recording. Everything on screen is being captured.', 'danger');
            self.startTimer();
            self.render();

            setTimeout(function () { self.capturePoster(); }, 1500);
        }).catch(function (error) {
            self.status(error.message || 'Could not start recording.', 'danger');
        });
    };

    Studio.prototype.togglePause = function () {
        if (!this.recorder) return;

        if (this.state === 'recording') {
            this.recorder.pause();
            this.state = 'paused';
            this.pausedAt = Date.now();
            this.status('Paused. The recording is not capturing.', 'warning');
        } else if (this.state === 'paused') {
            this.recorder.resume();
            this.state = 'recording';
            this.pausedFor += Date.now() - this.pausedAt;
            this.status('Recording.', 'danger');
        }

        this.render();
    };

    Studio.prototype.stop = function () {
        if (!this.recorder || this.state === 'finishing') return;

        this.state = 'finishing';
        this.stopTimer();
        this.status('Finishing the upload — keep this tab open.', 'info');
        this.render();

        if (this.recorder.state !== 'inactive') this.recorder.stop();
    };

    Studio.prototype.completeUpload = function () {
        var self = this;
        var duration = Math.round((Date.now() - this.startedAt - this.pausedFor) / 1000);

        this.uploader.finish({
            title: this.el('field-title').value || null,
            description: this.el('field-description').value || null,
            category_id: this.el('field-category').value || null,
            mime: (this.mimeType || 'video/webm').split(';')[0],
            duration_seconds: duration,
            width: this.canvas.width,
            height: this.canvas.height
        }, this.poster).then(function (result) {
            self.releaseSources();
            self.state = 'idle';
            self.status('Saved. Opening the recording…', 'success');
            window.location.href = result.redirect_url;
        }).catch(function (error) {
            self.state = 'idle';
            self.render();
            self.status(
                'The recording could not be saved: ' + error.message +
                ' Nothing was lost on the server — try Save again from the library.',
                'danger'
            );
        });
    };

    Studio.prototype.discard = function () {
        if (this.state !== 'idle' && !window.confirm('Discard this recording? It cannot be recovered.')) return;

        this.stopTimer();
        if (this.recorder && this.recorder.state !== 'inactive') {
            this.recorder.onstop = null;
            this.recorder.stop();
        }
        if (this.uploader) this.uploader.abort();

        this.releaseSources();
        this.state = 'idle';
        this.status('Discarded.', 'secondary');
        this.render();
    };

    Studio.prototype.releaseSources = function () {
        [this.displayStream, this.cameraStream].forEach(function (stream) {
            if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
        });

        this.displayStream = null;
        this.cameraStream = null;
        this.settings.mode = null;
        this.settings.includeScreen = false;
        this.settings.includeCamera = false;

        if (this.frame) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }

        if (this.audioContext) {
            this.audioContext.close();
            this.audioContext = null;
        }
    };

    /** Grab a poster frame off the compositing canvas — no server-side ffmpeg needed. */
    Studio.prototype.capturePoster = function () {
        var self = this;
        var scratch = document.createElement('canvas');
        scratch.width = 640;
        scratch.height = Math.round(640 * (this.canvas.height / this.canvas.width));
        scratch.getContext('2d').drawImage(this.canvas, 0, 0, scratch.width, scratch.height);

        scratch.toBlob(function (blob) {
            self.poster = blob;
        }, 'image/jpeg', 0.82);
    };

    /* ── UI ────────────────────────────────────────────────────────────────── */

    Studio.prototype.startTimer = function () {
        var self = this;
        this.stopTimer();

        this.timer = setInterval(function () {
            if (self.state !== 'recording') return;

            var elapsed = Math.round((Date.now() - self.startedAt - self.pausedFor) / 1000);
            self.el('recorder-timer').textContent = clock(elapsed);

            if (elapsed >= self.config.maxDuration) {
                self.status('Maximum recording length reached — saving.', 'warning');
                self.stop();
            }
        }, 500);
    };

    Studio.prototype.stopTimer = function () {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
    };

    Studio.prototype.renderProgress = function (uploaded, pending) {
        this.el('recorder-uploaded').textContent = bytes(uploaded)
            + (pending ? ' uploaded, ' + bytes(pending) + ' pending' : ' uploaded');
    };

    Studio.prototype.render = function () {
        var recording = this.state === 'recording' || this.state === 'paused';
        var hasSource = this.settings.includeScreen || this.settings.includeCamera;

        var self = this;
        MODES.forEach(function (mode) {
            var button = self.el('mode-' + mode.id);
            if (!button) return;
            // Switching source mid-recording would change the canvas shape the
            // encoder is already committed to.
            button.disabled = recording || self.state === 'finishing';
            button.classList.toggle('active', self.settings.mode === mode.value);
        });

        // The corner picker only means anything when the webcam is an inset.
        var pipMode = this.settings.mode === 'screen+camera';
        this.el('webcam-controls').style.display = pipMode ? '' : 'none';
        this.el('webcam-controls-na').style.display =
            this.settings.mode === 'camera' ? '' : 'none';

        this.el('btn-start').style.display = recording || this.state === 'finishing' ? 'none' : 'inline-block';
        this.el('btn-start').disabled = !hasSource;
        this.el('btn-pause').style.display = recording ? 'inline-block' : 'none';
        this.el('btn-pause').textContent = this.state === 'paused' ? 'Resume' : 'Pause';
        this.el('btn-stop').style.display = recording ? 'inline-block' : 'none';
        this.el('btn-discard').style.display = recording || this.state === 'finishing' ? 'inline-block' : 'none';

        this.el('recorder-indicator').style.display = this.state === 'recording' ? 'inline-flex' : 'none';

        CORNERS.forEach(function (corner) {
            var button = document.getElementById('corner-' + corner);
            if (button) button.classList.toggle('active', this.settings.webcamPosition === corner);
        }, this);

        this.el('recorder-placeholder').style.display = hasSource ? 'none' : 'flex';
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('recorder-canvas')) return;
        window.recorderStudio = new Studio(window.RECORDER_CONFIG);
    });
})();
