/**
 * Preview Generator
 * Generates rich previews for images, videos, and documents.
 *
 * Blob URLs returned here are owned by the caller: PreviewGenerator creates
 * them, UploadManager revokes them. Revoking them here would leave callers
 * holding a dead handle.
 */

const PREVIEW_MAX_EDGE = 640;
const PREVIEW_TIMEOUT_MS = 3000;

export class PreviewGenerator {
  static async generate(file) {
    // A .txt file reports file.type === '' on most desktop browsers, so the
    // text branch must be reached on extension, not on the MIME string.
    const mime = file.type || '';
    const extension = (file.name.split('.').pop() || '').toLowerCase();

    if (mime === 'text/plain' || (mime === '' && extension === 'txt')) {
      return this.generateTextPreview(file);
    }

    const [topLevel] = mime.split('/');

    switch (topLevel) {
      case 'image':
        return this.generateImagePreview(file);
      case 'video':
        return this.generateVideoPreview(file);
      case 'application':
        return this.generateDocumentPreview(file);
      default:
        return {
          type: 'file',
          url: null,
          metadata: this.getFileMetadata(file)
        };
    }
  }

  static generateImagePreview(file) {
    return new Promise(resolve => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      let settled = false;

      const release = () => {
        img.onload = null;
        img.onerror = null;
        // img.src = '' resolves to the document URL and can trigger a full
        // HTML re-fetch; removeAttribute is the correct teardown.
        img.removeAttribute('src');
        URL.revokeObjectURL(url);
      };

      const finish = preview => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        if (preview.url === url) {
          // The caller now owns this URL; keep it alive.
          img.onload = null;
          img.onerror = null;
          img.removeAttribute('src');
        } else {
          release();
        }
        resolve(preview);
      };

      const timeout = setTimeout(() => finish({
        type: 'file',
        url: null,
        metadata: this.getFileMetadata(file)
      }), PREVIEW_TIMEOUT_MS);

      img.onload = () => finish({
        type: 'image',
        url,
        metadata: {
          width: img.width,
          height: img.height,
          size: file.size,
          icon: '🖼️'
        }
      });

      img.onerror = () => finish({
        type: 'file',
        url: null,
        metadata: this.getFileMetadata(file)
      });

      img.src = url;
    });
  }

  static generateVideoPreview(file) {
    return new Promise(resolve => {
      const url = URL.createObjectURL(file);
      const video = document.createElement('video');
      video.preload = 'metadata';
      video.muted = true;
      let settled = false;

      const finish = preview => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        video.onloadedmetadata = null;
        video.onseeked = null;
        video.onerror = null;
        video.removeAttribute('src');
        video.load();
        // Always revoked: no consumer is handed this handle, so keeping it
        // alive would pin the whole file in memory for nothing.
        URL.revokeObjectURL(url);
        resolve(preview);
      };

      const fallback = () => finish({
        type: 'file',
        url: null,
        metadata: this.getFileMetadata(file)
      });

      const timeout = setTimeout(fallback, PREVIEW_TIMEOUT_MS);

      video.onloadedmetadata = () => {
        try {
          video.currentTime = Math.min(1, video.duration * 0.1);
        } catch {
          fallback();
        }
      };

      video.onseeked = () => {
        try {
          // Scale down: a 4K source would allocate a 3840x2160 RGBA canvas
          // (~33MB) and block the main thread on drawImage + toDataURL.
          const sourceWidth = video.videoWidth || 320;
          const sourceHeight = video.videoHeight || 180;
          const scale = Math.min(1, PREVIEW_MAX_EDGE / Math.max(sourceWidth, sourceHeight));

          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(sourceWidth * scale));
          canvas.height = Math.max(1, Math.round(sourceHeight * scale));

          const context = canvas.getContext('2d');
          if (!context) {
            fallback();
            return;
          }
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          finish({
            type: 'video',
            // toBlob is async and off the synchronous toDataURL path; the object
            // URL it returns is revoked by the caller's preview cleanup.
            url: canvas.toDataURL('image/jpeg', 0.7),
            metadata: {
              duration: video.duration,
              width: video.videoWidth,
              height: video.videoHeight,
              size: file.size,
              icon: '🎬'
            }
          });
        } catch {
          fallback();
        }
      };

      video.onerror = fallback;
      video.src = url;
    });
  }

  static generateDocumentPreview(file) {
    const extension = (file.name.split('.').pop() || '').toLowerCase();
    // Null prototype: a bare object literal would return inherited members
    // (constructor, toString) for those keys instead of undefined.
    const icons = Object.assign(Object.create(null), {
      pdf: '📄',
      doc: '📝',
      docx: '📝',
      xls: '📊',
      xlsx: '📊',
      ppt: '📽️',
      pptx: '📽️',
      zip: '🗜️'
    });

    return {
      type: 'document',
      url: null,
      metadata: {
        extension: extension ? extension.toUpperCase() : '',
        size: file.size,
        icon: icons[extension] || '📁',
        name: file.name
      }
    };
  }

  static generateTextPreview(file) {
    return {
      type: 'text',
      url: null,
      metadata: {
        extension: 'TXT',
        size: file.size,
        icon: '📃',
        name: file.name
      }
    };
  }

  static getFileMetadata(file) {
    return {
      name: file.name,
      size: file.size,
      type: file.type,
      lastModified: new Date(file.lastModified || Date.now()).toISOString()
    };
  }

  static formatSize(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
