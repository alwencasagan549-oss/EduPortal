# EduPortal Chunked Upload System — Implementation Guide

## Overview

This document describes the production-ready chunked upload system implemented for the EduPortal LMS. The system replaces the legacy single-file upload with a resumable, chunked multipart upload pipeline backed by S3-compatible object storage.

## Architecture

### Design Principles

- **Direct-to-storage**: Files never touch the EduPortal backend. The backend only orchestrates presigned URL generation and metadata tracking.
- **Resumable uploads**: Clients can pause, resume, and retry individual chunks without restarting.
- **Security-first**: Magic-byte validation, UUID filenames, CSRF protection, session binding, and presigned URL expiry.
- **Progressive enhancement**: The frontend validates before uploading; the backend validates again with `finfo_file()`.

### Components

```
Frontend (ES Modules)
├── MagicBytesValidator.js   Client-side MIME signature validation
├── RetryPolicy.js           Exponential backoff with jitter
├── PreviewGenerator.js      Thumbnails and metadata for images, videos, docs
├── api.js                   Backend communication layer
├── ChunkedUploader.js       Core multipart upload orchestration
└── index.js                 UploadManager — public UI-facing API

Backend (PHP + PDO PostgreSQL)
├── src/ObjectStorageService.php   S3-compatible storage abstraction
├── controllers/ajax_upload_initiate.php   Create session + presigned URLs
├── controllers/ajax_upload_status.php     Resume coordination
├── controllers/ajax_upload_finalize.php   Complete multipart upload
└── migrations/create_upload_sessions_table.php
```

## Database Schema

### `upload_sessions` Table

| Column | Type | Purpose |
|---|---|---|
| `upload_id` | VARCHAR(64) | Public client-facing ID |
| `user_id` | INTEGER | Owner of the upload |
| `user_role` | VARCHAR(20) | teacher / student |
| `original_filename` | VARCHAR(255) | Sanitized original name |
| `stored_filename` | VARCHAR(255) | UUID-based stored name |
| `file_size` | BIGINT | Bytes |
| `mime_type` | VARCHAR(100) | Verified MIME type |
| `chunk_size` | INTEGER | Chunk size in bytes |
| `total_chunks` | INTEGER | Expected chunk count |
| `completed_chunks` | INTEGER[] | Uploaded part numbers |
| `presigned_urls` | JSONB | Per-chunk presigned URLs |
| `s3_upload_id` | VARCHAR(255) | S3 multipart upload ID |
| `object_key` | VARCHAR(255) | Final storage path |
| `status` | VARCHAR(20) | initiated / uploading / completed / failed / cancelled / expired |
| `expires_at` | TIMESTAMP | Session expiry |
| `created_at` | TIMESTAMP | Row creation |
| `updated_at` | TIMESTAMP | Last update |

### Applying the Migration

```bash
php migrations/create_upload_sessions_table.php
```

## Environment Configuration

Add these variables to your deployment environment (Aiven, Render, or local `credentials.php`):

```env
# S3-Compatible Object Storage (Aiven Object Storage, AWS S3, MinIO, etc.)
S3_BUCKET=eduportal-uploads
S3_REGION=us-east-1
S3_ACCESS_KEY=<your-access-key>
S3_SECRET_KEY=<your-secret-key>
S3_ENDPOINT=https://<bucket>.<region>.aivencloud.com:443
S3_UPLOAD_PREFIX=uploads
S3_PRESIGNED_URL_EXPIRY=900
S3_USE_PATH_STYLE=false
S3_VERIFY_SSL=true
```

### Installing Dependencies

```bash
composer install
```

This installs `aws/aws-sdk-php` for S3 presigned URL generation.

## Upload Flow

### Phase 1: Initiation

1. Client selects file and calls `UploadManager.handleFiles(files)`.
2. Frontend validates extension, size, MIME, and magic bytes.
3. Client sends `POST /controllers/ajax_upload_initiate.php` with filename, size, and MIME.
4. Backend:
   - Validates CSRF and session.
   - Checks extension whitelist and size limit.
   - Creates `upload_sessions` row.
   - Calls `ObjectStorageService::initiateMultipartUpload()` to get S3 `UploadId`.
   - Generates N presigned PUT URLs (one per chunk).
   - Stores URLs and S3 UploadId in the session row.
   - Returns `{ uploadId, s3UploadId, chunkSize, totalChunks, presignedUrls[] }` to client.

### Phase 2: Chunk Upload

1. Client iterates through `presignedUrls[]` and PUTs each chunk directly to S3.
2. Each PUT returns an `ETag` header, which the client stores.
3. Per-chunk progress is emitted via the `onProgress` event.
4. Failed chunks are retried with exponential backoff (1s → 2s → 4s, max 30s, with jitter).
5. Pause sends `AbortSignal` to cancel the in-flight XHR; resume re-fetches remaining presigned URLs.

### Phase 3: Finalization

1. After all chunks upload successfully, client sends `POST /controllers/ajax_upload_finalize.php` with:
   ```json
   {
     "uploadId": "abc123...",
     "chunks": [
       { "partNumber": 1, "etag": "abc123..." },
       { "partNumber": 2, "etag": "def456..." }
     ]
   }
   ```
2. Backend calls `S3Client::completeMultipartUpload()` with sorted parts.
3. On success, the session is marked `completed` and the final object URL is returned.
4. On failure, the multipart upload is aborted to avoid orphaned S3 resources.

## Security Controls

### Frontend
- Magic-byte validation prevents MIME spoofing before any network request.
- CSRF token injected via `<meta name="csrf-token">`.
- File size and extension enforced in JavaScript before upload initiation.

### Backend
- All API endpoints require `validate_csrf()` and `requireLogin()`.
- Session binding via `$_SESSION['_ip_fingerprint']` prevents hijacking.
- `finfo_file()` re-validates MIME on server side.
- Extension whitelist enforced at initiation.
- Presigned URLs expire in 5–15 minutes (configurable via `S3_PRESIGNED_URL_EXPIRY`).
- `upload_sessions.status` prevents reuse of expired or completed sessions.

### Storage
- Objects stored with UUID filenames (no user-controlled path).
- Accessible only via presigned download URLs generated on demand.
- S3 bucket should have `BlockPublicAccess` enabled; all access is presigned.

## Frontend Usage

```javascript
import { UploadManager } from '../assets/js/uploads/index.js';

const manager = new UploadManager(document.body, {
  dropZoneSelector: '#dropZone',
  queueSelector: '#uploadQueue',
  emptyStateSelector: '#uploadEmptyState',
  maxSize: 500 * 1024 * 1024, // 500MB
  autoStart: true
});

// Listen for completion
document.body.addEventListener('upload:onUploadComplete', (e) => {
  console.log('Upload complete:', e.detail.url);
});

// Manual control
manager.startUpload(id);
manager.pauseUpload(id);
manager.resumeUpload(id);
manager.cancelUpload(id);
manager.retryUpload(id);
```

## State Machine

```
  pending ──▶ uploading ──▶ completed
                     │
                     ├──▶ failed ──▶ (retry) ──▶ uploading
                     │
                     ├──▶ paused ──▶ (resume) ──▶ uploading
                     │
                     └──▶ cancelled
```

| State | Description |
|---|---|
| `pending` | File validated, awaiting start |
| `uploading` | Actively uploading chunks |
| `paused` | Upload paused by user or abort |
| `completed` | All chunks uploaded and finalized |
| `failed` | Non-retryable error or max retries exceeded |
| `cancelled` | User cancelled; session removed |

## Error Handling

- **Validation errors**: Non-retryable. File is rejected immediately.
- **Network errors**: Retried up to 3 times with exponential backoff.
- **Server errors (5xx)**: Retried with backoff.
- **Chunk upload failures**: Individual chunk retried; other chunks unaffected.
- **Finalization failures**: Upload marked failed; user can retry (re-uploads all chunks from remaining presigned URLs).

## Browser Compatibility

- XMLHttpRequest with `responseType` for ETag capture.
- `crypto.randomUUID()` for upload IDs.
- `File.prototype.slice()` for chunking.
- ES2022 private fields (`#private`) — transpile if supporting older browsers.

## Deployment Checklist

- [ ] Run migration: `php migrations/create_upload_sessions_table.php`
- [ ] Install Composer dependencies: `composer install`
- [ ] Set environment variables for object storage credentials
- [ ] Configure S3 bucket with presigned URL support and private ACL
- [ ] Set CORS policy on S3 bucket to allow PUT from your domain
- [ ] Verify `uploads/` directory permissions (if using local fallback)
- [ ] Test with files of varying sizes (1MB, 10MB, 100MB)
- [ ] Verify pause/resume across page reload (status endpoint)
- [ ] Monitor `upload_sessions` for orphaned `initiated` records (cleanup job)

## Cleanup Recommendations

Add a scheduled job to expire stale sessions:

```php
// controllers/process_job.php or a cron endpoint
$conn->query("
    UPDATE upload_sessions
    SET status = 'expired'
    WHERE status IN ('initiated', 'uploading')
    AND expires_at < NOW()
");
```
