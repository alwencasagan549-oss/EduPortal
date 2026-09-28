/**
 * UploadManager
 * Public API for the upload system. Coordinates UI, validation, previews,
 * and the underlying chunked uploader.
 *
 * Rendering policy: untrusted values (file names, preview URLs, icons) are
 * never interpolated into an HTML string. `innerHTML` is used only for the
 * static shell plus values that pass through #escapeHtml; preview nodes are
 * built with DOM APIs and assigned via properties.
 */

import { ChunkedUploader } from './ChunkedUploader.js?v=20260924-uploads4';
import { PreviewGenerator } from './PreviewGenerator.js?v=20260924-uploads4';
import { performFullValidation } from './MagicBytesValidator.js?v=20260924-uploads4';

export class UploadManager {
  #uploader;
  #previews = new Map();
  #items = new Map();
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
      this.#triggerEvent('onUploadComplete', upload);
      // The file has left the browser; release any preview backing it.
      this.#releasePreview(upload.id);
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
      dropZone.addEventListener(eventName, event => {
        event.preventDefault();
        event.stopPropagation();
      });
    });

    // dragenter/dragleave fire for every descendant the pointer crosses, so a
    // plain class toggle strobes the highlight. Track nesting depth instead.
    let dragDepth = 0;
    dropZone.addEventListener('dragenter', () => {
      dragDepth += 1;
      dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragover', () => {
      dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) {
        dropZone.classList.remove('drag-over');
      }
    });

    dropZone.addEventListener('drop', (event) => {
      dragDepth = 0;
      dropZone.classList.remove('drag-over');
      this.#enqueue(Array.from(event.dataTransfer?.files ?? []));
    });

    if (fileInput) {
      fileInput.addEventListener('change', (event) => {
        this.#enqueue(Array.from(event.target.files ?? []));
        fileInput.value = '';
      });
    }
  }

  /** handleFiles is async; without a caller-side catch any rejection became an
   *  unhandled promise rejection with no UI feedback at all. */
  #enqueue(files) {
    if (files.length === 0) return;
    this.handleFiles(files).catch(error => {
      console.error('Upload queueing failed:', error);
      this.#triggerEvent('onUploadError', { upload: null, error: error.message, type: 'queue' });
    });
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

    // Render every row first with a placeholder so the queue never looks dead,
    // then resolve previews in parallel. Preview generation has a multi-second
    // timeout each; doing it sequentially before rendering left the drop zone
    // apparently unresponsive for up to 30s on a ten-file drop.
    for (const upload of uploads) {
      this.#createUploadItem(upload);
      if (this.#options.autoStart) {
        this.startUpload(upload.id);
      }
    }
    this.#updateEmptyState();

    await Promise.all(uploads.map(upload => this.#applyPreview(upload)));
  }

  async #applyPreview(upload) {
    try {
      const preview = await PreviewGenerator.generate(upload.file);
      if (!this.#items.has(upload.id)) return; // cancelled while generating
      this.#previews.set(upload.id, preview);
      this.#renderPreviewNode(upload);
    } catch (error) {
      this.#triggerEvent('onUploadError', { upload, error: error.message, type: 'preview' });
    }
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
    this.#releasePreview(id);
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
    this.#previews.forEach(preview => this.#revokePreviewUrl(preview));
    this.#previews.clear();
    this.#items.clear();
    this.#updateEmptyState();
  }

  /**
   * A blob: URL pins the whole File in the renderer's blob store until it is
   * revoked. Removing the <img> does not release it, so cancel, complete and
   * clear must all revoke explicitly.
   */
  #revokePreviewUrl(preview) {
    if (preview?.url && preview.url.startsWith('blob:')) {
      URL.revokeObjectURL(preview.url);
    }
  }

  #releasePreview(id) {
    const preview = this.#previews.get(id);
    if (preview) {
      this.#revokePreviewUrl(preview);
      this.#previews.delete(id);
    }
  }

  #createUploadItem(upload) {
    const queue = this.#container.querySelector(this.#options.queueSelector);
    if (!queue) return;

    const item = document.createElement('div');
    item.className = `upload-item state-${upload.state}`;
    item.dataset.uploadId = upload.id;

    const name = this.#escapeHtml(upload.file.name);
    const size = this.#escapeHtml(PreviewGenerator.formatSize(upload.file.size));
    item.innerHTML = `
      <div class="upload-preview"></div>
      <div class="upload-info">
        <div class="upload-header">
          <div class="upload-name" title="${name}">${name}</div>
          <div class="upload-size">${size}</div>
        </div>
        <div class="progress-container" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Number(upload.progress) || 0}">
          <div class="progress-bar" style="width: 0%"></div>
        </div>
        <div class="progress-text" aria-live="polite">0%</div>
        <div class="upload-actions"></div>
        <div class="upload-error" role="alert" aria-live="assertive" hidden></div>
      </div>
    `;

    // One delegated listener per item instead of rebinding on every re-render.
    const actions = item.querySelector('.upload-actions');
    if (actions) {
      actions.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        this.#handleAction(upload.id, button.dataset.action);
      });
    }

    queue.appendChild(item);
    this.#items.set(upload.id, item);
    this.#renderUploadItem(upload);
  }

  #renderActionButtons(upload) {
    const button = (action, icon, label, extraClass = '') => `
      <button type="button" class="btn-upload ${extraClass}" data-action="${action}" title="${label}" aria-label="${label}">
        <i class="fas fa-${icon}" aria-hidden="true"></i> ${label}
      </button>
    `;
    const cancel = (label = 'Cancel') => `
      <button type="button" class="btn-upload btn-cancel" data-action="cancel" title="${label}" aria-label="${label}">
        <i class="fas fa-times" aria-hidden="true"></i>
      </button>
    `;

    switch (upload.state) {
      case 'uploading':
        return button('pause', 'pause', 'Pause', 'btn-pause') + cancel();
      case 'paused':
        return button('resume', 'play', 'Resume', 'btn-resume') + cancel();
      case 'pending':
        return button('start', 'upload', 'Start upload', 'btn-start') + cancel();
      case 'failed':
        return button('retry', 'redo', 'Retry upload', 'btn-retry') + cancel('Remove');
      case 'completed':
        return '<span class="upload-success"><i class="fas fa-check-circle" aria-hidden="true"></i> Completed</span>';
      default:
        return '';
    }
  }

  #renderUploadItem(upload) {
    const item = this.#items.get(upload.id);
    if (!item) return;

    item.classList.remove(
      'state-pending', 'state-uploading', 'state-paused',
      'state-failed', 'state-completed', 'state-cancelled'
    );
    item.classList.add(`state-${upload.state}`);

    const actions = item.querySelector('.upload-actions');
    if (actions) {
      const focusedAction = document.activeElement?.dataset?.action;
      const next = this.#renderActionButtons(upload);
      if (actions.innerHTML !== next) {
        actions.innerHTML = next;
        // Replacing innerHTML destroys the focused element; put focus back.
        if (focusedAction) {
          actions.querySelector(`[data-action="${focusedAction}"]`)?.focus();
        }
      }
    }

    const errorEl = item.querySelector('.upload-error');
    if (errorEl) {
      // A live region must be rendered before its content changes, so toggle
      // `hidden` rather than display:none.
      const failed = upload.state === 'failed' && Boolean(upload.error);
      errorEl.textContent = failed ? upload.error : '';
      errorEl.hidden = !failed;
    }
  }

  #renderPreviewNode(upload) {
    const item = this.#items.get(upload.id);
    if (!item) return;

    const host = item.querySelector('.upload-preview');
    if (!host) return;

    const preview = this.#previews.get(upload.id);
    const filename = upload.file.name;

    if (preview?.url && (preview.type === 'image' || preview.type === 'video')) {
      const img = document.createElement('img');
      img.src = preview.url;
      img.alt = preview.type === 'video' ? 'Video preview' : filename;
      img.loading = 'lazy';
      host.replaceChildren(img);
      return;
    }

    // Icons come from a plain-object lookup keyed by extension; a null
    // prototype and a text node keep prototype keys from reaching innerHTML.
    const icon = document.createElement('div');
    icon.className = 'file-icon';
    icon.textContent = preview?.metadata?.icon || '📁';
    host.replaceChildren(icon);
  }

  #updateProgress(id, progress, loadedBytes, totalBytes, uploadedChunks, totalChunks) {
    const item = this.#items.get(id);
    if (!item) return;

    const safeProgress = Number(progress) || 0;
    const progressContainer = item.querySelector('.progress-container');
    const progressBar = item.querySelector('.progress-bar');
    const progressText = item.querySelector('.progress-text');

    if (progressContainer) {
      progressContainer.setAttribute('aria-valuenow', String(safeProgress));
      progressContainer.setAttribute(
        'aria-valuetext',
        `${safeProgress}% uploaded, ${uploadedChunks} of ${totalChunks} chunks`
      );
    }

    if (progressBar) {
      progressBar.style.width = `${safeProgress}%`;
    }

    if (progressText) {
      const loaded = PreviewGenerator.formatSize(loadedBytes);
      const total = PreviewGenerator.formatSize(totalBytes);
      progressText.textContent = `${safeProgress}% (${loaded} / ${total})`;
    }
  }

  #removeUploadItem(id) {
    const item = this.#items.get(id);
    if (!item) return;
    this.#items.delete(id);
    item.style.opacity = '0';
    item.style.transform = 'translateX(20px)';
    setTimeout(() => item.remove(), 300);
  }

  #updateEmptyState() {
    const emptyState = this.#container.querySelector(this.#options.emptyStateSelector);
    const queue = this.#container.querySelector(this.#options.queueSelector);
    if (!emptyState || !queue) return;

    emptyState.style.display = queue.children.length > 0 ? 'none' : 'block';
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
