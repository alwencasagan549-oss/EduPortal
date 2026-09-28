/**
 * Magic Bytes Validator
 * Validates file signatures to reduce MIME-type spoofing.
 *
 * IMPORTANT: this is a client-side convenience check, not a security boundary.
 * Several signatures are shared across formats (50 4B 03 04 is both zip and
 * docx; D0 CF 11 E0 covers doc/xls/ppt) and only the first few bytes are
 * compared. The authoritative check is the server-side `finfo` verification in
 * controllers/ajax_upload_finalize.php.
 */

const MAGIC_SIGNATURES = {
  'image/jpeg': [0xFF, 0xD8, 0xFF],
  'image/png': [0x89, 0x50, 0x4E, 0x47],
  'application/pdf': [0x25, 0x50, 0x44, 0x46],
  'application/zip': [0x50, 0x4B, 0x03, 0x04],
  'application/msword': [0xD0, 0xCF, 0x11, 0xE0],
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [0x50, 0x4B, 0x03, 0x04],
  'application/x-zip-compressed': [0x50, 0x4B, 0x03, 0x04]
};

// ISO base media brands seen in the wild. The ftyp box *size* varies with the
// number of compatible brands (0x18 for a bare box, 0x20 for four), so the size
// must never be compared; only the box type and the major brand are stable.
const MP4_BRANDS = new Set([
  'isom', 'iso2', 'iso4', 'iso5', 'iso6',
  'mp41', 'mp42', 'avc1', 'dash', 'M4V ', 'qt  '
]);

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

const hex = bytes => Array.from(bytes)
  .map(b => '0x' + b.toString(16).padStart(2, '0'))
  .join(' ');

/**
 * ISO base media (MP4/MOV): bytes 4-7 are the 'ftyp' box type, bytes 8-11 the
 * major brand. Validating the box size, as a fixed byte array would, rejected
 * essentially every real video file.
 */
async function validateMp4(file) {
  const bytes = new Uint8Array(await file.slice(0, 12).arrayBuffer());
  const actualBytes = hex(bytes.slice(0, 12));
  if (bytes.length < 12) {
    return { valid: false, detectedType: 'unknown', actualBytes, error: 'File is too short to be an MP4' };
  }

  const boxType = String.fromCharCode(bytes[4], bytes[5], bytes[6], bytes[7]);
  const brand = String.fromCharCode(bytes[8], bytes[9], bytes[10], bytes[11]).trim();
  const valid = boxType === 'ftyp' && MP4_BRANDS.has(brand);

  return {
    valid,
    detectedType: valid ? 'video/mp4' : 'unknown',
    actualBytes,
    note: valid ? undefined : `ftyp box ${boxType === 'ftyp' ? 'present' : 'missing'}, brand "${brand}"`
  };
}

export async function validateMagicBytes(file, expectedType) {
  if (!expectedType) {
    return { valid: true, detectedType: file.type || 'unknown' };
  }

  if (expectedType === 'video/mp4') {
    try {
      return await validateMp4(file);
    } catch (error) {
      return { valid: false, error: error.message };
    }
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

    return {
      valid: matches,
      detectedType: matches ? expectedType : 'unknown',
      actualBytes: hex(bytes.slice(0, signature.length)),
      expectedSignature: hex(signature)
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
  const isValid = file.size > 0 && file.size <= maxSizeBytes;

  return {
    valid: isValid,
    size: file.size,
    maxSize: maxSizeBytes,
    error: file.size === 0
      ? 'The selected file is empty.'
      : (isValid ? null : `File size ${formatBytes(file.size)} exceeds limit of ${formatBytes(maxSizeBytes)}`)
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

  // A read failure is not a signature mismatch; reporting it as one told users
  // their file was corrupt when the real problem was an unreadable handle.
  if (magicValidation.error) {
    return {
      valid: false,
      error: `Could not read "${file.name}" to verify its type: ${magicValidation.error}`
    };
  }

  if (!magicValidation.valid) {
    return {
      valid: false,
      error: `Invalid file signature. Expected ${expectedType}, got ${magicValidation.actualBytes || 'no data'}`
        + (magicValidation.note ? ` (${magicValidation.note})` : '')
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
