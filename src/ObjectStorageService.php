<?php
/**
 * EduPortal Object Storage Service
 * Abstraction layer for S3-compatible object storage (AWS S3, MinIO, Aiven Object Storage, etc.)
 */
namespace EduPortal;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class ObjectStorageService
{
    private S3Client $s3;
    private string $bucket;
    private string $uploadPrefix;
    private int $presignedUrlExpiry;

    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'];
        $this->uploadPrefix = rtrim($config['upload_prefix'] ?? 'uploads/', '/') . '/';
        $this->presignedUrlExpiry = (int)($config['presigned_url_expiry'] ?? 900);

        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => $config['use_path_style'] ?? false,
            'credentials' => [
                'key' => $config['access_key'],
                'secret' => $config['secret_key'],
            ],
            'http' => $config['endpoint'] ? [
                'verify' => $config['verify_ssl'] ?? true,
            ] : [],
        ]);
    }

    public function initiateMultipartUpload(string $objectKey, string $mimeType): string
    {
        try {
            $result = $this->s3->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'ContentType' => $mimeType,
                'Metadata' => [
                    'upload-context' => 'eduportal',
                    'uploaded-at' => date('c'),
                ],
            ]);

            return $result['UploadId'];
        } catch (AwsException $e) {
            throw new \RuntimeException('Failed to initiate multipart upload: ' . $e->getMessage());
        }
    }

    public function generatePresignedUploadUrl(
        string $objectKey,
        string $uploadId,
        int $partNumber,
        int $chunkSize
    ): string {
        try {
            $command = $this->s3->getCommand('UploadPart', [
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'ContentLength' => $chunkSize,
            ]);

            return (string)$this->s3->createPresignedRequest($command, '+' . $this->presignedUrlExpiry . ' seconds');
        } catch (AwsException $e) {
            throw new \RuntimeException('Failed to generate presigned URL: ' . $e->getMessage());
        }
    }

    public function completeMultipartUpload(string $objectKey, string $uploadId, array $parts): array
    {
        try {
            usort($parts, function ($a, $b) {
                return $a['PartNumber'] <=> $b['PartNumber'];
            });

            $result = $this->s3->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ],
            ]);

            return [
                'object_key' => $result['ObjectKey'] ?? $objectKey,
                'location' => $result['Location'] ?? $this->getObjectUrl($objectKey),
                'etag' => $result['ETag'] ?? '',
                'version_id' => $result['VersionId'] ?? null,
            ];
        } catch (AwsException $e) {
            $this->abortMultipartUpload($objectKey, $uploadId);
            throw new \RuntimeException('Failed to complete multipart upload: ' . $e->getMessage());
        }
    }

    public function abortMultipartUpload(string $objectKey, string $uploadId): void
    {
        try {
            $this->s3->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            error_log('EduPortal: Failed to abort multipart upload: ' . $e->getMessage());
        }
    }

    public function getObjectUrl(string $objectKey): string
    {
        return $this->s3->getObjectUrl($this->bucket, ltrim($objectKey, '/'));
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getUploadPrefix(): string
    {
        return $this->uploadPrefix;
    }

    public static function fromEnv(): self
    {
        $config = [
            'bucket' => getenv('S3_BUCKET') ?: getenv('OBJECT_STORAGE_BUCKET') ?: 'eduportal-uploads',
            'region' => getenv('S3_REGION') ?: getenv('OBJECT_STORAGE_REGION') ?: 'us-east-1',
            'access_key' => getenv('S3_ACCESS_KEY') ?: getenv('OBJECT_STORAGE_ACCESS_KEY') ?: '',
            'secret_key' => getenv('S3_SECRET_KEY') ?: getenv('OBJECT_STORAGE_SECRET_KEY') ?: '',
            'endpoint' => getenv('S3_ENDPOINT') ?: getenv('OBJECT_STORAGE_ENDPOINT') ?: null,
            'upload_prefix' => getenv('S3_UPLOAD_PREFIX') ?: getenv('OBJECT_STORAGE_UPLOAD_PREFIX') ?: 'uploads/',
            'presigned_url_expiry' => (int)(getenv('S3_PRESIGNED_URL_EXPIRY') ?: 900),
            'use_path_style' => filter_var(getenv('S3_USE_PATH_STYLE') ?: false, FILTER_VALIDATE_BOOLEAN),
            'verify_ssl' => filter_var(getenv('S3_VERIFY_SSL') ?: true, FILTER_VALIDATE_BOOLEAN),
        ];

        if (empty($config['access_key']) || empty($config['secret_key'])) {
            throw new \RuntimeException(
                'Object storage credentials not configured. Set S3_ACCESS_KEY and S3_SECRET_KEY environment variables.'
            );
        }

        return new self($config);
    }
}
