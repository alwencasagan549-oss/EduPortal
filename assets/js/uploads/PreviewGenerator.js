/**
 * Preview Generator
 * Generates rich previews for images, videos, and documents.
 */

export class PreviewGenerator {
  static async generate(file) {
    const type = file.type.split('/')[0];

    switch (type) {
      case 'image':
        return this.generateImagePreview(file);
      case 'video':
        return this.generateVideoPreview(file);
      case 'application':
        return this.generateDocumentPreview(file);
      default:
        if (file.type === 'text/plain') {
          return this.generateTextPreview(file);
        }
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
      const timeout = setTimeout(() => {
        if (settled) return;
        settled = true;
        img.onload = null;
        img.onerror = null;
        URL.revokeObjectURL(url);
        img.src = '';
        resolve({
          type: 'file',
          url: null,
          metadata: this.getFileMetadata(file)
        });
      }, 3000);
      const finish = preview => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        img.onload = null;
        img.onerror = null;
        if (preview.url !== url) {
          URL.revokeObjectURL(url);
        }
        resolve(preview);
      };

      img.onload = () => finish({
        type: 'image',
        url,
        previewUrl: url,
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
      video.crossOrigin = 'anonymous';
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
        URL.revokeObjectURL(url);
        resolve(preview);
      };

      const fallback = () => finish({
        type: 'file',
        url: null,
        metadata: this.getFileMetadata(file)
      });

      const timeout = setTimeout(fallback, 3000);

      video.onloadedmetadata = () => {
        try {
          video.currentTime = Math.min(1, video.duration * 0.1);
        } catch {
          fallback();
        }
      };

      video.onseeked = () => {
        try {
          const canvas = document.createElement('canvas');
          canvas.width = video.videoWidth || 320;
          canvas.height = video.videoHeight || 180;
          const context = canvas.getContext('2d');
          if (!context) {
            fallback();
            return;
          }
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          finish({
            type: 'video',
            url: canvas.toDataURL('image/jpeg', 0.7),
            previewUrl: url,
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
    const extension = file.name.split('.').pop()?.toLowerCase();
    const icons = {
      pdf: '📄',
      doc: '📝',
      docx: '📝',
      xls: '📊',
      xlsx: '📊',
      ppt: '📽️',
      pptx: '📽️',
      zip: '🗜️',
      txt: '📃'
    };

    return {
      type: 'document',
      url: null,
      previewUrl: null,
      metadata: {
        extension: extension?.toUpperCase(),
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
      previewUrl: null,
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
      lastModified: new Date(file.lastModified).toISOString()
    };
  }

  static formatSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
