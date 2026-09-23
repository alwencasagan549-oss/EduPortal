/**
 * Upload API Communication
 * Handles all backend communication for the upload system.
 */

const API_BASE = '/controllers';

function getCSRFToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content ||
         document.querySelector('input[name="csrf_token"]')?.value ||
         '';
}

function getBaseUrl() {
  return window.location.origin;
}

export async function initiateUpload(file) {
  const formData = new FormData();
  formData.append('filename', file.name);
  formData.append('filesize', file.size.toString());
  formData.append('mimetype', file.type || 'application/octet-stream');
  formData.append('csrf_token', getCSRFToken());

  const response = await fetch(`${API_BASE}/ajax_upload_initiate.php`, {
    method: 'POST',
    credentials: 'same-origin',
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

export async function finalizeUpload(uploadId, chunks) {
  const formData = new FormData();
  formData.append('uploadId', uploadId);
  formData.append('chunks', JSON.stringify(chunks));
  formData.append('csrf_token', getCSRFToken());

  const response = await fetch(`${API_BASE}/ajax_upload_finalize.php`, {
    method: 'POST',
    credentials: 'same-origin',
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

export async function getUploadStatus(uploadId) {
  const url = new URL(`${getBaseUrl()}${API_BASE}/ajax_upload_status.php`);
  url.searchParams.set('uploadId', uploadId);
  url.searchParams.set('csrf_token', getCSRFToken());

  const response = await fetch(url.toString(), {
    credentials: 'same-origin',
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
    const response = await fetch(`${getBaseUrl()}/controllers/ajax_upload_initiate.php`, {
      method: 'OPTIONS',
      credentials: 'same-origin'
    });
    return response.ok || response.status === 405;
  } catch {
    return false;
  }
}
