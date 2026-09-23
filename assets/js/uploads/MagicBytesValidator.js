/**
 * Magic Bytes Validator
 * Validates file signatures to prevent MIME-type spoofing.
 */

const MAGIC_SIGNATURES = {
  'image/jpeg': [0xFF, 0xD8, 0xFF],
  'image/png': [0x89, 0x50, 0x4E, 0x47],
  'application/pdf': [0x25, 0x50, 0x44, 0x46],
  'application/zip': [0x50, 0x4B, 0x03, 0x04],
  'video/mp4': [0x00, 0x00, 0x00, 0x18, 0x66, 0x74, 0x79, 0x70],
  'application/msword': [0xD0, 0xCF, 0x11, 0xE0],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [0x50, 0x4B, 0x03, 0x04]
};

const EXTENSION_TO_MIME = {
  'pdf': 'application/pdf',
  'doc': 'application/msword',
  'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'zip': 'application/zip',
  'jpg': 'image/jpeg',
  'jpeg': 'image/jpeg',
  'png': 'image/png',
  'mp4': 'video/mp4',
  'txt': 'text/plain'
};

export async function validateMagicBytes(file, expectedType) {
  if (!expectedType) {
    return { valid: true, detectedType: file.type || 'unknown' };
  }

  const signature = MAGIC_SIGNATURES[expectedType];
  if (!signature) {
    return { valid: true, detectedType: expectedType, note: 'No signature check for this type' };
  }

  try {
    const readLength = Math.max(signature.length, 16);
    const buffer = await file.slice(0, readLength).arrayBuffer();
    const bytes = new Uint8Array(buffer);

    const matches = signature.every((byte, index) => bytes[index] === byte);
    const actualBytes = Array.from(bytes.slice(0, signature.length))
      .map(b => '0x' + b.toString(16).padStart(2, '0'))
      .join(' ');

    return {
      valid: matches,
      detectedType: matches ? expectedType : 'unknown',
      actualBytes,
      expectedSignature: signature.map(b => '0x' + b.toString(16).padStart(2, '0')).join(' ')
    };
  } catch (error) {
    return { valid: false, error: error.message };
  }
}

export function validateFileExtension(file, allowedExtensions) {
  const extension = file.name.split('.').pop()?.toLowerCase();
  const isValid = allowedExtensions.includes(extension);

  return {
    valid: isValid,
    extension,
    error: isValid ? null : `Extension ".${extension}" is not allowed. Allowed: ${allowedExtensions.join(', ')}`
  };
}

export function validateFileSize(file, maxSizeBytes) {
  const isValid = file.size <= maxSizeBytes;

  return {
    valid: isValid,
    size: file.size,
    maxSize: maxSizeBytes,
    error: isValid ? null : `File size ${formatBytes(file.size)} exceeds limit of ${formatBytes(maxSizeBytes)}`
  };
}

export function validateMimeType(file, allowedMimes) {
  const isValid = allowedMimes.includes(file.type);

  return {
    valid: isValid,
    mimeType: file.type,
    error: isValid ? null : `MIME type "${file.type}" is not allowed`
  };
}

export async function performFullValidation(file, options = {}) {
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
  const maxSize = options.maxSize || 500 * 1024 * 1024;

  const extensionValidation = validateFileExtension(file, allowedExtensions);
  if (!extensionValidation.valid) return extensionValidation;

  const sizeValidation = validateFileSize(file, maxSize);
  if (!sizeValidation.valid) return sizeValidation;

  const mimeValidation = validateMimeType(file, allowedMimes);
  if (!mimeValidation.valid) return mimeValidation;

  const expectedType = EXTENSION_TO_MIME[extensionValidation.extension] || file.type;
  const magicValidation = await validateMagicBytes(file, expectedType);

  if (!magicValidation.valid) {
    return {
      valid: false,
      error: `Invalid file signature. Expected ${expectedType}, got ${magicValidation.actualBytes}`
    };
  }

  return { valid: true, extension: extensionValidation.extension, mimeType: magicValidation.detectedType };
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
