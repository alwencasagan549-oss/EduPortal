/**
 * UploadManager
 * Public API for the upload system. Coordinates UI, validation, previews,
 * and the underlying chunked uploader.
 */

import { ChunkedUploader } from './ChunkedUploader.js?v=20260924-uploads3';
import { PreviewGenerator } from './PreviewGenerator.js?v=20260924-uploads3';
import { performFullValidation } from './MagicBytesValidator.js?v=20260924-uploads3';

const trustedHtml = markup => window.EduPortalTrustedTypes
  ? window.EduPortalTrustedTypes.createHTML(markup)
  : markup;

export class UploadManager {
  #uploader;
  #previews = new Map();
  #container;
  #options = {
    allowedExtensions: ['pdf', 'doc', 'docx', 'zip', 'jpg', 'jpeg', 'png', 'mp4', 'txt'],
    maxSize: 500 * 1024 * 1024,
    autoStart: true,
    dropZoneSelector: '#dropZone',
    queueSelector: '#uploadQueue',
    emptyStateSelector: '#uploadEmptyState'
  };

  constructor(container, options = {}) {
    this.#container = container;
    this.#options = { ...this.#options, ...options };

    this.#uploader = new ChunkedUploader();
    this.#setupListeners();
    this.#attachDropZone();
  }

  #setupListeners() {
    this.#uploader.on('onStateChange', (upload) => {
      this.#renderUploadItem(upload);
    });

    this.#uploader.on('onProgress', ({ id, progress, loadedBytes, totalBytes, uploadedChunks, totalChunks }) => {
      this.#updateProgress(id, progress, loadedBytes, totalBytes, uploadedChunks, totalChunks);
    });

    this.#uploader.on('onComplete', upload => {
      PreviewGenerator.generate(upload.file)
        .then(preview => {
          this.#previews.set(upload.id, preview);
          this.#renderUploadItem(upload);
          this.#triggerEvent('onUploadComplete', upload);
        })
        .catch(error => {
          this.#triggerEvent('onUploadError', { upload, error: error.message, type: 'preview' });
        });
    });

    this.#uploader.on('onError', ({ upload, error, type }) => {
      console.error(`Upload error [${type}]:`, error);
      this.#triggerEvent('onUploadError', { upload, error, type });
    });
  }

  #attachDropZone() {
    const dropZone = this.#container.querySelector(this.#options.dropZoneSelector);
    if (!dropZone) return;

    const fileInput = this.#container.querySelector('input[type="file"]');

    dropZone.addEventListener('click', event => {
      if (event.target.closest('button, input, a')) {
        return;
      }
      fileInput?.click();
    });

    dropZone.addEventListener('keydown', event => {
      if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button, input, a')) {
        event.preventDefault();
        fileInput?.click();
      }
    });

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
    });

    ['dragenter', 'dragover'].forEach(eventName => {
      dropZone.addEventListener(eventName, () => {
        dropZone.classList.add('drag-over');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropZone.addEventListener(eventName, () => {
        dropZone.classList.remove('drag-over');
      });
    });

    dropZone.addEventListener('drop', (e) => {
      const files = Array.from(e.dataTransfer.files);
      this.handleFiles(files);
    });

    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        this.handleFiles(files);
        fileInput.value = '';
      });
    }
  }

  async handleFiles(files) {
    const validFiles = [];

    for (const file of files) {
      const validation = await performFullValidation(file, {
        allowedExtensions: this.#options.allowedExtensions,
        maxSize: this.#options.maxSize
      });

      if (!validation.valid) {
        this.#triggerEvent('onValidationError', { file, error: validation.error });
        continue;
      }

      validFiles.push(file);
    }

    if (validFiles.length === 0) return;

    const uploads = await this.#uploader.addFiles(validFiles, {
      allowedExtensions: this.#options.allowedExtensions,
      maxSize: this.#options.maxSize
    });

    for (const upload of uploads) {
      let preview;
      try {
        preview = await PreviewGenerator.generate(upload.file);
      } catch (error) {
        preview = {
          type: 'file',
          url: null,
          metadata: PreviewGenerator.getFileMetadata(upload.file)
        };
        this.#triggerEvent('onUploadError', { upload, error: error.message, type: 'preview' });
      }
      this.#previews.set(upload.id, preview);
      this.#createUploadItem(upload, preview);

      if (this.#options.autoStart) {
        this.startUpload(upload.id);
      }
    }

    this.#updateEmptyState();
  }

  startUpload(id) {
    this.#uploader.startUpload(id);
  }

  pauseUpload(id) {
    this.#uploader.pauseUpload(id);
  }

  resumeUpload(id) {
    this.#uploader.startUpload(id, true);
  }

  cancelUpload(id) {
    this.#uploader.cancelUpload(id);
    this.#removeUploadItem(id);
    this.#updateEmptyState();
  }

  retryUpload(id) {
    this.#uploader.retryUpload(id);
  }

  getUpload(id) {
    return this.#uploader.getUpload(id);
  }

  getQueue() {
    return this.#uploader.getQueue();
  }

  clear() {
    this.#uploader.cancelAll();
    const queue = this.#container.querySelector(this.#options.queueSelector);
    if (queue) {
      queue.replaceChildren();
    }
    this.#previews.forEach(preview => {
      if (preview.url?.startsWith('blob:')) {
        URL.revokeObjectURL(preview.url);
      }
    });
    this.#previews.clear();
    this.#updateEmptyState();
  }

  #createUploadItem(upload, preview) {
    const queue = this.#container.querySelector(this.#options.queueSelector);
    if (!queue) return;

    const item = document.createElement('div');
    item.className = `upload-item state-${upload.state}`;
    item.dataset.uploadId = upload.id;
    item.innerHTML = trustedHtml(this.#buildUploadItemHTML(upload, preview));

    queue.appendChild(item);
    this.#renderUploadItem(upload);
  }

  #buildUploadItemHTML(upload, preview) {
    const previewHTML = this.#renderPreview(preview, upload.file.name);
    const actionButtons = this.#renderActionButtons(upload);

    return `
      <div class="upload-preview">${previewHTML}</div>
      <div class="upload-info">
        <div class="upload-header">
          <div class="upload-name" title="${this.#escapeHtml(upload.file.name)}">${this.#escapeHtml(upload.file.name)}</div>
          <div class="upload-size">${PreviewGenerator.formatSize(upload.file.size)}</div>
        </div>
        <div class="progress-container" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${upload.progress}">
          <div class="progress-bar" style="width: ${upload.progress}%"></div>
        </div>
        <div class="progress-text" aria-live="polite">${upload.progress}%</div>
        <div class="upload-actions">${actionButtons}</div>
        <div class="upload-error" role="alert" aria-live="assertive" style="display: none;"></div>
      </div>
    `;
  }

  #renderPreview(preview, filename) {
    if (preview.type === 'image' && preview.url) {
      return `<img src="${preview.url}" alt="${this.#escapeHtml(filename)}" loading="lazy">`;
    }
    if (preview.type === 'video' && preview.url) {
      return `<img src="${preview.url}" alt="Video preview" loading="lazy">`;
    }
    const icon = preview.metadata?.icon || '📁';
    return `<div class="file-icon">${icon}</div>`;
  }

  #renderActionButtons(upload) {
    if (upload.state === 'uploading') {
      return `
        <button type="button" class="btn-upload btn-pause" data-action="pause" title="Pause">
          <i class="fas fa-pause"></i> Pause
        </button>
        <button type="button" class="btn-upload btn-cancel" data-action="cancel" title="Cancel">
          <i class="fas fa-times"></i>
        </button>
      `;
    }

    if (upload.state === 'paused') {
      return `
        <button type="button" class="btn-upload btn-resume" data-action="resume" title="Resume">
          <i class="fas fa-play"></i> Resume
        </button>
        <button type="button" class="btn-upload btn-cancel" data-action="cancel" title="Cancel">
          <i class="fas fa-times"></i>
        </button>
      `;
    }

    if (upload.state === 'pending') {
      return `
        <button type="button" class="btn-upload btn-start" data-action="start" title="Start">
          <i class="fas fa-upload"></i> Upload
        </button>
        <button type="button" class="btn-upload btn-cancel" data-action="cancel" title="Cancel">
          <i class="fas fa-times"></i>
        </button>
      `;
    }

    if (upload.state === 'failed') {
      return `
        <button type="button" class="btn-upload btn-retry" data-action="retry" title="Retry">
          <i class="fas fa-redo"></i> Retry
        </button>
        <button type="button" class="btn-upload btn-cancel" data-action="cancel" title="Remove">
          <i class="fas fa-trash"></i>
        </button>
      `;
    }

    if (upload.state === 'completed') {
      return `<span class="upload-success"><i class="fas fa-check-circle"></i> Completed</span>`;
    }

    return '';
  }

  #renderUploadItem(upload) {
    const item = this.#container.querySelector(`[data-upload-id="${upload.id}"]`);
    if (!item) return;

    item.classList.remove('state-pending', 'state-uploading', 'state-paused', 'state-failed', 'state-completed', 'state-cancelled');
    item.classList.add(`state-${upload.state}`);

    const actionsContainer = item.querySelector('.upload-actions');
    if (actionsContainer) {
      const preview = this.#previews.get(upload.id);
      actionsContainer.innerHTML = trustedHtml(this.#renderActionButtons(upload));

      actionsContainer.querySelectorAll('button').forEach(button => {
        button.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          const action = button.dataset.action;
          this.#handleAction(upload.id, action);
        });
      });
    }

    const errorEl = item.querySelector('.upload-error');
    if (errorEl) {
      if (upload.state === 'failed' && upload.error) {
        errorEl.textContent = upload.error;
        errorEl.style.display = 'block';
      } else {
        errorEl.style.display = 'none';
      }
    }
  }

  #updateProgress(id, progress, loadedBytes, totalBytes, uploadedChunks, totalChunks) {
    const item = this.#container.querySelector(`[data-upload-id="${id}"]`);
    if (!item) return;

    const progressContainer = item.querySelector('.progress-container');
    const progressBar = item.querySelector('.progress-bar');
    const progressText = item.querySelector('.progress-text');

    if (progressContainer) {
      progressContainer.setAttribute('aria-valuenow', String(progress));
      progressContainer.setAttribute('aria-valuetext', `${progress}% uploaded, ${uploadedChunks} of ${totalChunks} chunks`);
    }

    if (progressBar) {
      progressBar.style.width = `${progress}%`;
    }

    if (progressText) {
      const loaded = PreviewGenerator.formatSize(loadedBytes);
      const total = PreviewGenerator.formatSize(totalBytes);
      progressText.textContent = `${progress}% (${loaded} / ${total})`;
    }
  }

  #removeUploadItem(id) {
    const item = this.#container.querySelector(`[data-upload-id="${id}"]`);
    if (item) {
      item.style.opacity = '0';
      item.style.transform = 'translateX(20px)';
      setTimeout(() => item.remove(), 300);
    }
  }

  #updateEmptyState() {
    const emptyState = this.#container.querySelector(this.#options.emptyStateSelector);
    const queue = this.#container.querySelector(this.#options.queueSelector);
    if (!emptyState || !queue) return;

    const hasItems = queue.children.length > 0;
    emptyState.style.display = hasItems ? 'none' : 'block';
  }

  #handleAction(id, action) {
    switch (action) {
      case 'start':
        this.startUpload(id);
        break;
      case 'pause':
        this.pauseUpload(id);
        break;
      case 'resume':
        this.resumeUpload(id);
        break;
      case 'cancel':
        this.cancelUpload(id);
        break;
      case 'retry':
        this.retryUpload(id);
        break;
    }
  }

  #triggerEvent(name, detail) {
    const event = new CustomEvent(`upload:${name}`, { detail, bubbles: true });
    this.#container.dispatchEvent(event);
  }

  #escapeHtml(text) {
    const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(text ?? '').replace(/[&<>"']/g, character => entities[character]);
  }
}
