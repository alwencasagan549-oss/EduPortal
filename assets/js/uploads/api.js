/**
 * Upload API Communication
 * Handles all backend communication for the upload system.
 */

const API_BASE = new URL('../../../controllers/', import.meta.url);

function getCSRFToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content ||
         document.querySelector('input[name="csrf_token"]')?.value ||
         '';
}

export async function initiateUpload(file, { signal } = {}) {
  const formData = new FormData();
  formData.append('filename', file.name);
  formData.append('filesize', file.size.toString());
  formData.append('mimetype', file.type || 'application/octet-stream');
  formData.append('csrf_token', getCSRFToken());

  const response = await fetch(new URL('ajax_upload_initiate.php', API_BASE), {
    method: 'POST',
    credentials: 'same-origin',
    signal,
    headers: {
      'X-CSRF-Token': getCSRFToken()
    },
    body: formData
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error || `HTTP ${response.status}: ${response.statusText}`);
  }

  return response.json();
}

export async function finalizeUpload(uploadId, chunks, { signal } = {}) {
  const formData = new FormData();
  formData.append('uploadId', uploadId);
  formData.append('chunks', JSON.stringify(chunks));
  formData.append('csrf_token', getCSRFToken());

  const response = await fetch(new URL('ajax_upload_finalize.php', API_BASE), {
    method: 'POST',
    credentials: 'same-origin',
    signal,
    headers: {
      'X-CSRF-Token': getCSRFToken()
    },
    body: formData
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error || `HTTP ${response.status}: ${response.statusText}`);
  }

  return response.json();
}

export async function getUploadStatus(uploadId, { signal } = {}) {
  const url = new URL('ajax_upload_status.php', API_BASE);
  url.searchParams.set('uploadId', uploadId);
  url.searchParams.set('csrf_token', getCSRFToken());

  const response = await fetch(url.toString(), {
    credentials: 'same-origin',
    cache: 'no-store',
    signal,
    headers: {
      'X-CSRF-Token': getCSRFToken()
    }
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.error || `HTTP ${response.status}: ${response.statusText}`);
  }

  return response.json();
}

export async function checkServerHealth() {
  try {
    const response = await fetch(new URL('ajax_upload_initiate.php', API_BASE), {
      method: 'OPTIONS',
      credentials: 'same-origin'
    });
    return response.ok || response.status === 405;
  } catch {
    return false;
  }
}
