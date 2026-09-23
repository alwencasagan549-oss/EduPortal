/**
 * ChunkedUploader
 * Core upload orchestration with multipart upload support, retry logic,
 * pause/resume, and progress tracking.
 */

import { initiateUpload, finalizeUpload, getUploadStatus } from './api.js';
import { RetryPolicy } from './RetryPolicy.js';

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

      const id = crypto.randomUUID();
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
        abortController: null,
        error: null,
        retryCount: 0,
        presignedUrls: [],
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
      if (!resume || upload.completedChunks.length === 0) {
        const initResult = await this.#retryPolicy.execute(
          () => initiateUpload(upload.file),
          (error) => error.name === 'NetworkError' || error.status >= 500
        );

        if (!initResult.success) {
          throw new Error(initResult.error || 'Failed to initiate upload');
        }

        upload.uploadId = initResult.uploadId;
        upload.s3UploadId = initResult.s3UploadId;
        upload.presignedUrls = initResult.presignedUrls;
        upload.chunkSize = initResult.chunkSize || upload.chunkSize;
        upload.totalChunks = initResult.totalChunks || upload.totalChunks;
        upload.objectKey = initResult.objectKey;
      } else {
        const status = await getUploadStatus(upload.uploadId);
        if (!status.success) {
          throw new Error(status.error || 'Failed to get upload status');
        }
        upload.presignedUrls = status.remainingPresignedUrls;
      }

      await this.#uploadChunks(upload);
    } catch (error) {
      if (error.name === 'AbortError') {
        upload.state = 'paused';
        upload.error = 'Upload paused';
      } else {
        upload.state = 'failed';
        upload.error = error.message;
        upload.retryCount++;
      }
      this.#emit('onStateChange', upload);
      this.#emit('onError', { upload, error: upload.error, type: 'upload' });
    }
  }

  async #uploadChunks(upload) {
    const { id, file, chunkSize, presignedUrls, abortController, totalChunks } = upload;
    const startChunk = upload.completedChunks.length;

    if (startChunk >= totalChunks) {
      await this.#finalizeUpload(upload);
      return;
    }

    const remainingPresignedUrls = presignedUrls.slice(startChunk);

    for (let i = 0; i < remainingPresignedUrls.length; i++) {
      if (abortController.signal.aborted) {
        throw new DOMException('Upload aborted', 'AbortError');
      }

      const chunkIndex = startChunk + i;
      const presignedUrl = remainingPresignedUrls[i];
      const start = chunkIndex * chunkSize;
      const end = Math.min(start + chunkSize, file.size);
      const chunkBlob = file.slice(start, end);

      try {
        const etag = await this.#retryPolicy.execute(
          () => this.#uploadChunk(presignedUrl, chunkBlob, abortController.signal),
          (error) => error.name === 'NetworkError' || error.status >= 500
        );

        upload.completedChunks.push(chunkIndex + 1);
        upload.loadedBytes = end;
        upload.progress = Math.round((upload.loadedBytes / file.size) * 100);

        this.#emit('onProgress', {
          id,
          progress: upload.progress,
          loadedBytes: upload.loadedBytes,
          totalBytes: file.size,
          chunkIndex: chunkIndex + 1,
          totalChunks,
          uploadedChunks: upload.completedChunks.length
        });
      } catch (error) {
        upload.error = `Chunk ${chunkIndex + 1} failed: ${error.message}`;
        upload.state = 'failed';
        upload.retryCount++;
        this.#emit('onStateChange', upload);
        this.#emit('onError', { upload, error: upload.error, type: 'chunk' });
        throw error;
      }
    }

    await this.#finalizeUpload(upload);
  }

  async #finalizeUpload(upload) {
    const chunks = upload.completedChunks.map((partNumber, index) => ({
      partNumber,
      etag: upload.presignedUrls[partNumber - 1]?.etag || ''
    }));

    const result = await finalizeUpload(upload.uploadId, chunks);

    if (!result.success) {
      throw new Error(result.error || 'Failed to finalize upload');
    }

    upload.state = 'completed';
    upload.progress = 100;
    upload.loadedBytes = upload.file.size;
    upload.objectKey = result.objectKey;
    upload.url = result.url;
    this.#emit('onStateChange', upload);
    this.#emit('onComplete', upload);
  }

  #uploadChunk(presignedUrl, blob, signal) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();

      xhr.open('PUT', presignedUrl, true);
      xhr.setRequestHeader('Content-Type', 'application/octet-stream');
      xhr.responseType = 'text';

      const timeout = setTimeout(() => {
        xhr.abort();
        reject(new DOMException('Request timeout', 'TimeoutError'));
      }, 60000);

      xhr.onload = () => {
        clearTimeout(timeout);

        if (xhr.status >= 200 && xhr.status < 300) {
          const etag = xhr.getResponseHeader('ETag') || '';
          resolve(etag.replace(/"/g, ''));
        } else {
          const error = new Error(`Chunk upload failed: HTTP ${xhr.status}`);
          error.status = xhr.status;
          reject(error);
        }
      };

      xhr.onerror = () => {
        clearTimeout(timeout);
        const error = new DOMException('Network error during chunk upload', 'NetworkError');
        error.status = 0;
        reject(error);
      };

      xhr.onabort = () => {
        clearTimeout(timeout);
        reject(new DOMException('Upload aborted', 'AbortError'));
      };

      signal.addEventListener('abort', () => {
        clearTimeout(timeout);
        xhr.abort();
      }, { once: true });

      try {
        xhr.send(blob);
      } catch (error) {
        clearTimeout(timeout);
        reject(error);
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

  retryUpload(id) {
    const upload = this.#queue.get(id);
    if (!upload || upload.state !== 'failed') return;

    upload.state = 'pending';
    upload.error = null;
    upload.retryCount = 0;
    this.startUpload(id, true);
  }

  removeUpload(id) {
    const upload = this.#queue.get(id);
    if (upload?.state === 'uploading') {
      upload.abortController?.abort();
    }
    this.#queue.delete(id);
  }

  #formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
