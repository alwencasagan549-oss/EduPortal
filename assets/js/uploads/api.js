/**
 * Upload API Communication
 * Handles all backend communication for the upload system.
 */

const API_BASE = new URL('../../../controllers/', import.meta.url);
const TOKEN_TTL_MS = 5 * 60 * 1000;

let cachedToken = null;
let tokenFetchedAt = 0;

/**
 * The session CSRF token is minted per session and rotated by the server during
 * long-lived flows, so a token captured at page render can go stale mid-upload.
 * Cache it briefly and expose refreshCSRFToken() for responses that rotate it.
 */
export function getCSRFToken() {
  if (cachedToken && Date.now() - tokenFetchedAt < TOKEN_TTL_MS) {
    return cachedToken;
  }

  const token = document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="csrf_token"]')?.value
    || '';

  if (!token) {
    throw new Error('This page did not render a security token. Reload and try again.');
  }

  cachedToken = token;
  tokenFetchedAt = Date.now();
  return token;
}

export function refreshCSRFToken(token) {
  if (!token || typeof token !== 'string') return;
  cachedToken = token;
  tokenFetchedAt = Date.now();
  document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
    input.value = token;
  });
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) meta.content = token;
}

/**
 * Single response contract for every endpoint.
 *
 * Without this, a server that returned {"success": false} with HTTP 200 was
 * handed to callers as a success, so errors surfaced as a stuck progress bar
 * with no error state and no retries.
 */
async function parseApiResponse(response) {
  if (response.redirected) {
    const error = new Error('Your session has expired. Sign in again to continue.');
    error.sessionExpired = true;
    error.retryable = false;
    throw error;
  }

  const contentType = response.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    const error = new Error(`Unexpected response from the server (HTTP ${response.status}).`);
    error.status = response.status;
    error.retryable = response.status >= 500;
    throw error;
  }

  let data;
  try {
    data = await response.json();
  } catch {
    const error = new Error('The server returned an invalid response.');
    error.retryable = false;
    throw error;
  }

  if (!response.ok || data.success === false || data.error) {
    const error = new Error(data.error || `HTTP ${response.status}: ${response.statusText}`);
    error.status = response.status;
    error.sessionExpired = response.status === 401;
    // Only transport failures and server-side faults are worth replaying.
    error.retryable = response.status >= 500
      || response.status === 408
      || response.status === 429;
    throw error;
  }

  if (data.csrf_token) {
    refreshCSRFToken(data.csrf_token);
  }

  return data;
}

function withTimeout(timeoutMs, externalSignal) {
  const controller = new AbortController();
  const timer = setTimeout(
    () => controller.abort(new DOMException('Request timed out', 'TimeoutError')),
    timeoutMs
  );
  if (!externalSignal) {
    return { signal: controller.signal, done: () => clearTimeout(timer) };
  }
  const forward = () => controller.abort(externalSignal.reason);
  if (externalSignal.aborted) {
    forward();
  } else {
    externalSignal.addEventListener('abort', forward, { once: true });
  }
  return {
    signal: controller.signal,
    done: () => {
      clearTimeout(timer);
      externalSignal.removeEventListener('abort', forward);
    }
  };
}

async function postForm(url, formData, { signal, timeoutMs = 30000 } = {}) {
  const timeout = withTimeout(timeoutMs, signal);
  try {
    return await parseApiResponse(await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      signal: timeout.signal,
      headers: { 'X-CSRF-Token': getCSRFToken(), Accept: 'application/json' },
      body: formData
    }));
  } finally {
    timeout.done();
  }
}

export async function initiateUpload(file, { signal } = {}) {
  const formData = new FormData();
  formData.append('filename', file.name);
  formData.append('filesize', file.size.toString());
  formData.append('mimetype', file.type || 'application/octet-stream');
  formData.append('csrf_token', getCSRFToken());

  return postForm(new URL('ajax_upload_initiate.php', API_BASE), formData, { signal });
}

export async function finalizeUpload(uploadId, chunks, { signal } = {}) {
  const formData = new FormData();
  formData.append('uploadId', uploadId);
  formData.append('chunks', JSON.stringify(chunks));
  formData.append('csrf_token', getCSRFToken());

  // 60s: a large multipart completion is a server-side S3 call.
  return postForm(new URL('ajax_upload_finalize.php', API_BASE), formData, { signal, timeoutMs: 60000 });
}

/**
 * Records landed parts so an interrupted upload can resume. Fire-and-forget:
 * a failure here must never fail the upload itself, since finalize remains the
 * authoritative completion step.
 */
export async function reportUploadProgress(uploadId, chunks, { signal } = {}) {
  const formData = new FormData();
  formData.append('uploadId', uploadId);
  formData.append('chunks', JSON.stringify(chunks));
  formData.append('csrf_token', getCSRFToken());

  return postForm(new URL('ajax_upload_progress.php', API_BASE), formData, { signal, timeoutMs: 10000 });
}

export async function getUploadStatus(uploadId, { signal } = {}) {
  const url = new URL('ajax_upload_status.php', API_BASE);
  // The CSRF token travels in a header, never the query string, where it would
  // land in access logs, browser history and the Referer header.
  url.searchParams.set('uploadId', uploadId);

  const timeout = withTimeout(15000, signal);
  try {
    return await parseApiResponse(await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: timeout.signal,
      headers: { 'X-CSRF-Token': getCSRFToken(), Accept: 'application/json' }
    }));
  } finally {
    timeout.done();
  }
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
