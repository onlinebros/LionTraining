/*
 * Chunked uploader.
 *
 * Shared by the recording studio and the "upload a video" page, because both
 * face the same wall: PHP will not accept a 400 MB body, and a single failed
 * request must not cost the whole upload. So the file goes up in ordered
 * pieces, each retried on its own, with the server treating a re-sent offset it
 * already holds as a no-op.
 *
 * The studio feeds it live from MediaRecorder while recording; the upload page
 * feeds it a File the browser already has. Neither cares how the other works.
 *
 * Usage:
 *   var up = new ChunkedUploader({ createUrl, csrf, chunkBytes, onProgress, onError });
 *   up.open({ ...fields })          // opens the session, returns a promise
 *   up.push(blobOrSlice)            // queue bytes
 *   up.finish({ ...fields }, poster) // flush, then finalise
 *   up.abort()                      // give up, let the server clean up
 */
(function (global) {
    'use strict';

    function ChunkedUploader(options) {
        this.createUrl = options.createUrl;
        this.csrf = options.csrf;
        this.chunkBytes = options.chunkBytes;
        this.onProgress = options.onProgress || function () {};
        this.onError = options.onError || function () {};

        this.session = null;
        this.buffer = [];          // blobs not yet packed into a chunk
        this.buffered = 0;
        this.offset = 0;           // bytes the server has acknowledged
        this.failed = false;

        // Every send is chained onto this promise. Chunks must reach the server
        // in order — they are consecutive slices of one file — and the final
        // flush must not overtake a request that is still in flight.
        this.tail = Promise.resolve();
    }

    ChunkedUploader.prototype.open = function (payload) {
        var self = this;
        var body = new FormData();
        Object.keys(payload).forEach(function (key) {
            if (payload[key] !== null && payload[key] !== undefined) body.append(key, payload[key]);
        });

        return fetch(this.createUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) throw new Error('Could not start the upload session.');
            return response.json();
        }).then(function (session) {
            self.session = session;
            self.chunkBytes = session.chunk_bytes || self.chunkBytes;
            return session;
        });
    };

    ChunkedUploader.prototype.push = function (blob) {
        if (!blob || !blob.size) return;
        this.buffer.push(blob);
        this.buffered += blob.size;
        this.pump();
    };

    /** Queue a drain behind whatever is already uploading. */
    ChunkedUploader.prototype.pump = function (flush) {
        var self = this;

        this.tail = this.tail.then(function () {
            return self.drain(flush);
        });

        return this.tail;
    };

    /** Send whole chunks while we have them; on a flush, send the remainder too. */
    ChunkedUploader.prototype.drain = function (flush) {
        if (this.failed || !this.session) return Promise.resolve();
        if (!flush && this.buffered < this.chunkBytes) return Promise.resolve();
        if (this.buffered === 0) return Promise.resolve();

        var packed = new Blob(this.buffer, { type: 'application/octet-stream' });
        var take = flush ? packed.size : Math.min(this.chunkBytes, packed.size);
        var chunk = packed.slice(0, take);
        var remainder = packed.slice(take);

        this.buffer = remainder.size ? [remainder] : [];
        this.buffered = remainder.size;

        var self = this;
        var offset = this.offset;

        return this.send(chunk, offset).then(function () {
            self.offset += chunk.size;
            self.onProgress(self.offset, self.buffered);
            return self.drain(flush);
        }).catch(function (error) {
            self.failed = true;
            self.onError(error);
            throw error;
        });
    };

    /**
     * A dropped Wi-Fi packet mid-recording should not cost the presenter the
     * session, so a chunk is retried with backoff before we give up. The server
     * treats a re-sent offset it already holds as a no-op.
     */
    ChunkedUploader.prototype.send = function (chunk, offset, attempt) {
        attempt = attempt || 1;
        var self = this;
        var body = new FormData();
        body.append('offset', String(offset));
        body.append('chunk', chunk, 'chunk.bin');

        return fetch(this.session.chunk_url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            if (response.ok) return response.json();
            // 4xx means the server will never accept this chunk — retrying is
            // pointless and would only stall the queue behind it.
            if (response.status >= 400 && response.status < 500) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    throw new Error(data.message || 'The server rejected part of the recording.');
                });
            }
            throw new Error('Upload failed with status ' + response.status);
        }).catch(function (error) {
            if (attempt >= 4 || /rejected part/.test(error.message)) throw error;
            return new Promise(function (resolve) {
                setTimeout(resolve, attempt * 1500);
            }).then(function () {
                return self.send(chunk, offset, attempt + 1);
            });
        });
    };

    ChunkedUploader.prototype.finish = function (fields, poster) {
        var self = this;

        return this.pump(true).then(function () {
            var body = new FormData();
            Object.keys(fields).forEach(function (key) {
                if (fields[key] !== null && fields[key] !== undefined) body.append(key, fields[key]);
            });
            if (poster) body.append('poster', poster, 'poster.jpg');

            return fetch(self.session.finalize_url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': self.csrf, 'Accept': 'application/json' },
                body: body,
                credentials: 'same-origin'
            });
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || 'The recording could not be stored.');
                return data;
            });
        });
    };

    ChunkedUploader.prototype.abort = function () {
        if (!this.session) return Promise.resolve();
        return fetch(this.session.abort_url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).catch(function () { /* the prune command sweeps whatever is left */ });
    };

    global.ChunkedUploader = ChunkedUploader;
})(window);
