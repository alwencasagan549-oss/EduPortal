/**
 * ChunkedUploader
 * Core upload orchestration with multipart upload support, retry logic,
 * pause/resume, and progress tracking.
 */

import { initiateUpload, finalizeUpload, getUploadStatus, reportUploadProgress } from './api.js?v=20260924-uploads4';
import { RetryPolicy } from './RetryPolicy.js?v=20260924-uploads4';

/** crypto.randomUUID is [SecureContext]-only; fall back outside secure contexts. */
const newUploadKey = () => (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
  ? crypto.randomUUID()
  : `up_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`);

const isTransient = error => error?.retryable === true || error?.name === 'NetworkError';

export class ChunkedUploader {
  #queue = new Map();
  #retryPolicy = new RetryPolicy({
    maxRetries: 3,
    baseDelay: 1000,
    maxDelay: 30000,
    jitter: true
  });
  #defaultChunkSize = 8 * 1024 * 1024;
  #maxFileSize = 500 * 1024 * 1024;
  #listeners = {
    onStateChange: [],
    onProgress: [],
    onError: [],
    onComplete: []
  };

  on(event, callback) {
    if (this.#listeners[event]) {
      this.#listeners[event].push(callback);
    }
  }

  off(event, callback) {
    if (this.#listeners[event]) {
      this.#listeners[event] = this.#listeners[event].filter(cb => cb !== callback);
    }
  }

  #emit(event, data) {
    this.#listeners[event]?.forEach(callback => {
      try {
        callback(data);
      } catch (error) {
        console.error(`Uploader listener error [${event}]:`, error);
      }
    });
  }

  getQueue() {
    return Array.from(this.#queue.values());
  }

  getUpload(id) {
    return this.#queue.get(id);
  }

  async addFiles(files, options = {}) {
    const uploads = [];
    const allowedExtensions = options.allowedExtensions || ['pdf', 'doc', 'docx', 'zip', 'jpg', 'jpeg', 'png', 'mp4', 'txt'];
    const allowedMimes = options.allowedMimes || [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/zip',
      'image/jpeg',
      'image/png',
      'video/mp4',
      'text/plain'
    ];
    const maxSize = options.maxSize || this.#maxFileSize;

    for (const file of files) {
      const validation = this.#validateFile(file, allowedExtensions, allowedMimes, maxSize);
      if (!validation.valid) {
        this.#emit('onError', {
          file,
          error: validation.error,
          type: 'validation'
        });
        continue;
      }

      const id = newUploadKey();
      const chunkSize = this.#calculateChunkSize(file.size);
      const totalChunks = Math.ceil(file.size / chunkSize);

      const upload = {
        id,
        file,
        state: 'pending',
        progress: 0,
        loadedBytes: 0,
        chunkSize,
        totalChunks,
        completedChunks: [],
        completedParts: {},
        abortController: null,
        error: null,
        retryCount: 0,
        presignedUrls: [],
        presignedUrlsAreRemaining: false,
        uploadId: null,
        s3UploadId: null,
        objectKey: null
      };

      this.#queue.set(id, upload);
      uploads.push(upload);
      this.#emit('onStateChange', upload);
    }

    return uploads;
  }

  #validateFile(file, allowedExtensions, allowedMimes, maxSize) {
    const extension = file.name.split('.').pop()?.toLowerCase();

    if (!allowedExtensions.includes(extension)) {
      return {
        valid: false,
        error: `Extension ".${extension}" is not allowed. Allowed: ${allowedExtensions.join(', ')}`
      };
    }

    if (file.size > maxSize) {
      return {
        valid: false,
        error: `File size ${this.#formatBytes(file.size)} exceeds limit of ${this.#formatBytes(maxSize)}`
      };
    }

    if (!allowedMimes.includes(file.type)) {
      return {
        valid: false,
        error: `File type "${file.type}" is not allowed`
      };
    }

    return { valid: true };
  }

  #calculateChunkSize(fileSize) {
    if (fileSize <= 10 * 1024 * 1024) return fileSize;
    if (fileSize <= 100 * 1024 * 1024) return 5 * 1024 * 1024;
    return this.#defaultChunkSize;
  }

  async startUpload(id, resume = false) {
    const upload = this.#queue.get(id);
    if (!upload || upload.state === 'uploading') return;

    if (upload.state === 'paused' && !resume) {
      return;
    }

    upload.state = 'uploading';
    upload.error = null;
    upload.abortController = new AbortController();
    this.#emit('onStateChange', upload);

    try {
      if (!resume || upload.completedChunks.length === 0 || !upload.uploadId) {
        const initResult = await this.#retryPolicy.execute(
          () => initiateUpload(upload.file, { signal: upload.abortController.signal }),
          isTransient,
          upload.abortController.signal
        );

        this.#assertActive(upload);
        if (!initResult.success) {
          throw new Error(initResult.error || 'Failed to initiate upload');
        }

        upload.uploadId = initResult.uploadId;
        upload.s3UploadId = initResult.s3UploadId;
        // Fresh initiate returns a bare URL list aligned with part 1..N.
        upload.presignedUrls = (initResult.presignedUrls || []).map((url, index) => ({
          partNumber: index + 1,
          url
        }));
        upload.presignedUrlsAreRemaining = false;
        upload.chunkSize = initResult.chunkSize || upload.chunkSize;
        upload.totalChunks = initResult.totalChunks || upload.totalChunks;
        upload.objectKey = initResult.objectKey;
      } else {
        const status = await getUploadStatus(upload.uploadId, { signal: upload.abortController.signal });
        this.#assertActive(upload);
        if (!status.success) {
          throw new Error(status.error || 'Failed to get upload status');
        }
        if (status.status === 'expired' || status.status === 'aborted') {
          throw new Error('This upload session has expired. Start the upload again.');
        }

        // The server is authoritative on which parts landed, and it tags each
        // remaining URL with its part number. Re-deriving part numbers from
        // array position would silently attach ETags to the wrong parts.
        upload.completedChunks = (status.completedChunks || []).slice().sort((a, b) => a - b);
        upload.completedParts = Object.fromEntries(
          upload.completedChunks
            .filter(part => upload.completedParts[part])
            .map(part => [part, upload.completedParts[part]])
        );
        upload.chunkSize = status.chunkSize || upload.chunkSize;
        upload.totalChunks = status.totalChunks || upload.totalChunks;
        upload.presignedUrls = status.remainingPresignedUrls || [];
        upload.presignedUrlsAreRemaining = true;
      }

      await this.#uploadChunks(upload);
    } catch (error) {
      if (!this.#queue.has(id) || upload.state === 'cancelled') {
        return;
      }
      if (error.name === 'AbortError') {
        upload.state = 'paused';
        upload.error = 'Upload paused';
        this.#emit('onStateChange', upload);
        return;
      }
      upload.state = 'failed';
      upload.error = upload.error || error.message;
      upload.retryCount++;
      this.#emit('onStateChange', upload);
      this.#emit('onError', { upload, error: upload.error, type: 'upload' });
    } finally {
      if (this.#queue.has(id)) {
        upload.abortController = null;
      }
    }
  }

  async #uploadChunks(upload) {
    const { id, file, chunkSize, presignedUrls, abortController, totalChunks } = upload;

    if (upload.completedChunks.length >= totalChunks) {
      await this.#finalizeUpload(upload);
      return;
    }

    const completedSet = new Set(upload.completedChunks);
    const pending = presignedUrls.filter(entry =>
      Number.isInteger(entry?.partNumber)
      && entry.partNumber >= 1
      && !completedSet.has(entry.partNumber));

    let sinceLastReport = 0;

    for (const { partNumber, url: presignedUrl } of pending) {
      this.#assertActive(upload);

      const start = (partNumber - 1) * chunkSize;
      if (start >= file.size) continue;
      const end = Math.min(start + chunkSize, file.size);
      const chunkBlob = file.slice(start, end);

      try {
        const etag = await this.#retryPolicy.execute(
          () => this.#uploadChunk(presignedUrl, chunkBlob, abortController.signal),
          isTransient,
          abortController.signal
        );

        if (!etag) {
          throw new Error(`Storage service did not return an ETag for chunk ${partNumber}`);
        }

        upload.completedParts[partNumber] = etag;
        if (!completedSet.has(partNumber)) {
          upload.completedChunks.push(partNumber);
          completedSet.add(partNumber);
        }
        upload.loadedBytes = Math.max(upload.loadedBytes, end);
        upload.progress = Math.min(100, Math.round((upload.loadedBytes / file.size) * 100));

        this.#emit('onProgress', {
          id,
          progress: upload.progress,
          loadedBytes: upload.loadedBytes,
          totalBytes: file.size,
          chunkIndex: partNumber,
          totalChunks,
          uploadedChunks: upload.completedChunks.length
        });
      } catch (error) {
        if (error.name === 'AbortError') {
          throw error;
        }
        upload.error = `Chunk ${partNumber} failed: ${error.message}`;
        // Persist whatever landed before the failure so resume starts here.
        await this.#reportProgress(upload);
        throw error;
      }

      // Checkpoint periodically so an interrupted large upload resumes from
      // roughly where it stopped rather than from the beginning.
      if (++sinceLastReport >= 3) {
        sinceLastReport = 0;
        await this.#reportProgress(upload);
      }
    }

    // Persist which parts landed so a later resume resumes instead of restarting.
    await this.#reportProgress(upload);

    await this.#finalizeUpload(upload);
  }

  async #reportProgress(upload) {
    if (!upload.uploadId || upload.completedChunks.length === 0) return;
    try {
      await reportUploadProgress(upload.uploadId, upload.completedChunks, {
        signal: upload.abortController?.signal
      });
    } catch {
      // Best effort only: finalizeUpload is the authoritative completion step.
    }
  }

  async #finalizeUpload(upload) {
    const chunks = upload.completedChunks
      .slice()
      .sort((a, b) => a - b)
      .map(partNumber => ({
        partNumber,
        etag: upload.completedParts?.[partNumber] || ''
      }));

    const missingPart = chunks.find(({ etag }) => !etag);
    if (missingPart) {
      throw new Error(`Missing ETag for uploaded part ${missingPart.partNumber}. Check the R2 CORS ExposeHeaders setting.`);
    }

    const result = await finalizeUpload(upload.uploadId, chunks, { signal: upload.abortController.signal });
    this.#assertActive(upload);

    if (!result.success) {
      throw new Error(result.error || 'Failed to finalize upload');
    }

    upload.state = 'completed';
    upload.progress = 100;
    upload.loadedBytes = upload.file.size;
    upload.objectKey = result.objectKey;
    upload.url = result.url;
    this.#emit('onStateChange', upload);
    this.#emit('onProgress', {
      id: upload.id,
      progress: 100,
      loadedBytes: upload.file.size,
      totalBytes: upload.file.size,
      chunkIndex: upload.totalChunks,
      totalChunks: upload.totalChunks,
      uploadedChunks: upload.totalChunks
    });
    this.#emit('onComplete', upload);
  }

  #uploadChunk(presignedUrl, blob, signal) {
    return new Promise((resolve, reject) => {
      if (signal.aborted) {
        reject(new DOMException('Upload aborted', 'AbortError'));
        return;
      }

      const xhr = new XMLHttpRequest();
      let settled = false;
      let timeout = null;

      const cleanup = () => {
        window.clearTimeout(timeout);
        signal.removeEventListener('abort', handleAbort);
        xhr.onload = null;
        xhr.onerror = null;
        xhr.onabort = null;
      };

      const settle = callback => {
        if (settled) return;
        settled = true;
        cleanup();
        callback();
      };

      const handleAbort = () => {
        settle(() => {
          if (xhr.readyState !== XMLHttpRequest.UNSENT) {
            xhr.abort();
          }
          reject(new DOMException('Upload aborted', 'AbortError'));
        });
      };

      signal.addEventListener('abort', handleAbort, { once: true });
      if (signal.aborted) {
        handleAbort();
        return;
      }

      try {
        xhr.open('PUT', presignedUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.responseType = 'text';
      } catch (error) {
        settle(() => reject(error));
        return;
      }

      timeout = setTimeout(() => {
        settle(() => {
          xhr.abort();
          reject(new DOMException('Request timeout', 'TimeoutError'));
        });
      }, 60000);

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          const etag = xhr.getResponseHeader('ETag') || '';
          settle(() => resolve(etag.trim()));
        } else {
          const error = new Error(`Chunk upload failed: HTTP ${xhr.status}`);
          error.status = xhr.status;
          settle(() => reject(error));
        }
      };

      xhr.onerror = () => {
        const error = new DOMException('Network error during chunk upload', 'NetworkError');
        error.status = 0;
        settle(() => reject(error));
      };

      xhr.onabort = () => {
        settle(() => reject(new DOMException('Upload aborted', 'AbortError')));
      };

      try {
        xhr.send(blob);
      } catch (error) {
        settle(() => reject(error));
      }
    });
  }

  pauseUpload(id) {
    const upload = this.#queue.get(id);
    if (!upload || upload.state !== 'uploading') return;

    upload.abortController?.abort();
  }

  cancelUpload(id) {
    const upload = this.#queue.get(id);
    if (!upload) return;

    upload.abortController?.abort();
    upload.state = 'cancelled';
    upload.error = 'Upload cancelled';
    this.#emit('onStateChange', upload);
    this.#queue.delete(id);
  }

  cancelAll() {
    for (const id of this.#queue.keys()) {
      this.cancelUpload(id);
    }
  }

  retryUpload(id) {
    const upload = this.#queue.get(id);
    if (!upload || upload.state !== 'failed') return;

    upload.state = 'pending';
    upload.error = null;
    upload.retryCount = 0;
    upload.progress = 0;
    upload.loadedBytes = 0;
    upload.completedChunks = [];
    upload.completedParts = {};
    upload.presignedUrls = [];
    upload.presignedUrlsAreRemaining = false;
    upload.uploadId = null;
    upload.s3UploadId = null;
    upload.objectKey = null;
    this.#emit('onStateChange', upload);
    this.#emit('onProgress', {
      id: upload.id,
      progress: 0,
      loadedBytes: 0,
      totalBytes: upload.file.size,
      chunkIndex: 0,
      totalChunks: upload.totalChunks,
      uploadedChunks: 0
    });
    this.startUpload(id, false);
  }

  removeUpload(id) {
    this.cancelUpload(id);
  }

  #assertActive(upload) {
    if (!this.#queue.has(upload.id) || upload.state === 'cancelled' || upload.abortController?.signal.aborted) {
      throw new DOMException('Upload aborted', 'AbortError');
    }
  }

  #formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
