# Arkham Barcode Generation API Documentation

## Base URL

```
https://your-arkham-domain.com/api
```

## Overview

The Arkham API allows you to generate barcode packages (UPC-12 and EAN-13) in various formats (JPG, PDF, EPS, XLS). Jobs are processed asynchronously, and you can either poll for status or receive a webhook callback when the job completes.

---

## Endpoints

### 1. Create Barcode Job

Create a new barcode generation job.

**Endpoint:** `POST /api/barcodes`

**Request Body:**

```json
{
  "order_no": "ORDER-305528",
  "start": "00000000000",
  "end": "00000009999",
  "callback_url": "https://staging.speedybarcodes.com/api/arkham/webhook",
  "callback_token": "optional-per-job-secret",
  "formats": ["jpg", "pdf", "xls"]
}
```

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_no` | string | Yes | Order identifier (max 255 characters) |
| `start` | string | Yes | 11-digit UPC base (no check digit). Must be numeric and exactly 11 digits |
| `end` | string | Yes | 11-digit UPC base (no check digit). Must be numeric, exactly 11 digits, and >= start |
| `callback_url` | string | No | URL to receive webhook when job completes |
| `callback_token` | string | No | Optional secret for HMAC signature verification (max 255 characters) |
| `formats` | array | No | Array of desired output formats. Valid values: `"jpg"`, `"pdf"`, `"eps"`, `"xls"`. If omitted, defaults to JPG + XLS enabled, PDF/EPS from server config |

**Format Options:**

- `"jpg"` - JPEG raster images (UPC-12 and EAN-13)
- `"pdf"` - PDF vector files (UPC-12 and EAN-13)
- `"eps"` - EPS vector files (UPC-12 and EAN-13)
- `"xls"` - Excel spreadsheet with UPC-12 number list (CSV format with .xls extension)

**Note:** If a job already exists for the same `order_no`, the old job and its files will be deleted before creating the new job.

**Success Response (201 Created):**

```json
{
  "success": true,
  "message": "Barcode job created successfully",
  "job": {
    "id": "019ae342-07a7-7361-9143-e03a0cd85c30",
    "order_no": "ORDER-305528",
    "batch_id": "a08130d4-5e53-4108-8604-8c341e25fe59",
    "total_jobs": 50,
    "processed_jobs": 0,
    "failed_jobs": 0,
    "started_at": "2025-12-03T12:34:56.000000Z",
    "status_url": "https://your-arkham-domain.com/api/barcodes/019ae342-07a7-7361-9143-e03a0cd85c30/status",
    "download_url": null
  }
}
```

**Error Responses:**

- `422 Unprocessable Entity` - Validation failed
  ```json
  {
    "error": "Validation failed",
    "message": "End must be >= start."
  }
  ```

- `422 Unprocessable Entity` - Invalid format value
  ```json
  {
    "message": "The formats.0 must be one of the following: jpg, pdf, eps, xls.",
    "errors": {
      "formats.0": ["The formats.0 must be one of the following: jpg, pdf, eps, xls."]
    }
  }
  ```

---

### 2. Get Job Status

Retrieve the current status of a barcode job.

**Endpoint:** `GET /api/barcodes/{job_id}`

**Alternative Endpoint:** `GET /api/barcodes/{job_id}/status`

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `job_id` | string (UUID) | Yes | The job ID returned from the create endpoint |

**Success Response (200 OK):**

```json
{
  "id": "019ae342-07a7-7361-9143-e03a0cd85c30",
  "order_no": "ORDER-305528",
  "batch_id": "a08130d4-5e53-4108-8604-8c341e25fe59",
  "total_jobs": 50,
  "processed_jobs": 45,
  "failed_jobs": 0,
  "percentage": 90,
  "finished": false,
  "started_at": "2025-12-03T12:34:56.000000Z",
  "finished_at": null,
  "zip_url": null
}
```

**When Job is Complete:**

```json
{
  "id": "019ae342-07a7-7361-9143-e03a0cd85c30",
  "order_no": "ORDER-305528",
  "batch_id": "a08130d4-5e53-4108-8604-8c341e25fe59",
  "total_jobs": 50,
  "processed_jobs": 50,
  "failed_jobs": 0,
  "percentage": 100,
  "finished": true,
  "started_at": "2025-12-03T12:34:56.000000Z",
  "finished_at": "2025-12-03T12:45:23.000000Z",
  "zip_url": "https://your-arkham-domain.com/api/barcodes/019ae342-07a7-7361-9143-e03a0cd85c30/download"
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Job UUID |
| `order_no` | string | Order identifier |
| `batch_id` | string | Laravel batch ID (for internal tracking) |
| `total_jobs` | integer | Total number of chunk jobs |
| `processed_jobs` | integer | Number of completed chunk jobs |
| `failed_jobs` | integer | Number of failed chunk jobs |
| `percentage` | integer | Completion percentage (0-100) |
| `finished` | boolean | Whether the job is complete |
| `started_at` | string (ISO 8601) | Job start timestamp |
| `finished_at` | string (ISO 8601) or null | Job completion timestamp (null if not finished) |
| `zip_url` | string or null | Download URL (null until job completes) |

**Error Responses:**

- `404 Not Found` - Job ID not found

---

### 3. Download Job Package

Download the completed barcode package as a ZIP file.

**Endpoint:** `GET /api/barcodes/{job_id}/download`

**URL Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `job_id` | string (UUID) | Yes | The job ID returned from the create endpoint |

**Success Response (200 OK):**

- **Content-Type:** `application/zip`
- **Content-Disposition:** `attachment; filename="barcodes-{order_no}-{job_id}.zip"`
- **Body:** ZIP file containing the barcode package

**ZIP Package Structure:**

```
order-20251203-123456-XXXXXX/
├── UPC-12/
│   ├── JPG/
│   │   ├── UPC-12-000000000000.jpg
│   │   ├── UPC-12-000000000001.jpg
│   │   └── ...
│   ├── PDF/
│   │   ├── UPC-12-000000000000.pdf
│   │   └── ...
│   ├── EPS/
│   │   ├── UPC-12-000000000000.eps
│   │   └── ...
│   └── UPC-12 XLS Number List - Order # ORDER-305528.xls
├── EAN-13/
│   ├── JPG/
│   │   ├── EAN-13-0000000000000.jpg
│   │   └── ...
│   ├── PDF/
│   │   └── ...
│   └── EPS/
│       └── ...
├── !Read Me First.pdf
├── Speedy Invoice-ORDER-305528.pdf
└── Speedy Certificate-ORDER-305528.pdf
```

**Note:** Only directories and files for requested formats will be included. For example, if `formats: ["xls"]` was specified, only the XLS file will be in the ZIP (no JPG/PDF/EPS directories).

**Error Responses:**

- `404 Not Found` - Job ID not found or ZIP file not yet available

---

## Webhook Callback

When a job completes, if you provided a `callback_url`, Arkham will POST a webhook to that URL.

**Webhook Request:**

- **Method:** `POST`
- **URL:** The `callback_url` you provided
- **Content-Type:** `application/json`
- **Headers:**
  - `X-Arkham-Signature` (if `callback_token` was provided): HMAC-SHA256 signature of the JSON body

**Webhook Payload:**

```json
{
  "job_id": "019ae342-07a7-7361-9143-e03a0cd85c30",
  "order_no": "ORDER-305528",
  "status": "ready",
  "download_url": "https://your-arkham-domain.com/api/barcodes/019ae342-07a7-7361-9143-e03a0cd85c30/download",
  "finished_at": "2025-12-03T12:45:23.000000Z"
}
```

**Signature Verification:**

If you provided a `callback_token`, verify the webhook signature:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ARKHAM_SIGNATURE'] ?? null;
$expectedSignature = hash_hmac('sha256', $payload, $yourStoredCallbackToken);

if (!hash_equals($expectedSignature, $signature)) {
    // Invalid signature - reject the request
    http_response_code(401);
    exit;
}
```

**Webhook Response:**

Your webhook endpoint should return a `200 OK` status. Arkham will log the response status but will not retry failed webhooks.

---

## Examples

### Example 1: Create Job with All Formats

```bash
curl -X POST https://your-arkham-domain.com/api/barcodes \
  -H "Content-Type: application/json" \
  -d '{
    "order_no": "ORDER-305528",
    "start": "00000000000",
    "end": "00000000099",
    "callback_url": "https://staging.speedybarcodes.com/api/arkham/webhook",
    "callback_token": "my-secret-token-123",
    "formats": ["jpg", "pdf", "eps", "xls"]
  }'
```

### Example 2: Create Job with Only JPG and XLS

```bash
curl -X POST https://your-arkham-domain.com/api/barcodes \
  -H "Content-Type: application/json" \
  -d '{
    "order_no": "ORDER-305529",
    "start": "00000000000",
    "end": "00000000099",
    "formats": ["jpg", "xls"]
  }'
```

### Example 3: Create Job with Only Excel Spreadsheet

```bash
curl -X POST https://your-arkham-domain.com/api/barcodes \
  -H "Content-Type: application/json" \
  -d '{
    "order_no": "ORDER-305530",
    "start": "00000000000",
    "end": "00000000099",
    "formats": ["xls"]
  }'
```

### Example 4: Check Job Status

```bash
curl https://your-arkham-domain.com/api/barcodes/019ae342-07a7-7361-9143-e03a0cd85c30/status
```

### Example 5: Download Completed Job

```bash
curl -O -J https://your-arkham-domain.com/api/barcodes/019ae342-07a7-7361-9143-e03a0cd85c30/download
```

---

## Notes

1. **Job Processing:** Jobs are processed asynchronously in chunks. Large ranges are automatically split into smaller chunks for parallel processing.

2. **Duplicate Orders:** If you create a new job with the same `order_no` as an existing job, the old job and its files will be automatically deleted.

3. **Format Defaults:** If `formats` is not specified:
   - JPG is enabled by default
   - XLS is enabled by default
   - PDF/EPS follow server configuration (`barcodes.enable_pdf` and `barcodes.enable_eps`)

4. **XLS Generation:** The XLS file can be generated even if JPG format is not requested. It contains a simple CSV list of all UPC-12 codes in the range.

5. **File Naming:**
   - JPG files: `UPC-12-{12digits}.jpg` and `EAN-13-{13digits}.jpg`
   - PDF files: `UPC-12-{12digits}.pdf` and `EAN-13-{13digits}.pdf`
   - EPS files: `UPC-12-{12digits}.eps` and `EAN-13-{13digits}.eps`
   - XLS file: `UPC-12 XLS Number List - Order # {order_no}.xls`

6. **UPC Base Format:** The `start` and `end` parameters must be exactly 11 digits (no check digit). The system automatically calculates and appends the check digit to create valid 12-digit UPC-A codes.

7. **EAN-13 Generation:** EAN-13 codes are automatically generated by prefixing `0` to each UPC-12 code and recalculating the check digit.

---

## Error Handling

All endpoints return standard HTTP status codes:

- `200 OK` - Success
- `201 Created` - Job created successfully
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation error
- `500 Internal Server Error` - Server error

Error responses include a JSON body with error details:

```json
{
  "error": "Error type",
  "message": "Human-readable error message"
}
```

---

## Rate Limiting

Currently, there are no rate limits enforced. However, very large jobs (10,000+ barcodes) may take significant time to process. Consider breaking large orders into smaller batches if you need faster turnaround.

---

## Support

For issues or questions, please contact your system administrator or check the application logs.

