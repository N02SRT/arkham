<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\Storage;

class BarcodeJob extends Model
{
    use HasUuids;
    // Ensure UUID PKs behave
    public $incrementing = false;
    protected $keyType = 'string';

    // Force the same connection everywhere
    protected $connection = 'mysql'; // <-- adjust if your primary is named differently

    protected $fillable = [
        'id','order_no','root','batch_id','total_jobs','processed_jobs','failed_jobs',
        'zip_rel_path','started_at','finished_at'
    ];

    protected $appends = ['zip_url'];

    public function getZipUrlAttribute()
    {
        $rel = $this->zip_rel_path ?? null;
        if (!$rel) return null;

        $disk = Storage::disk(config('filesystems.default', 'public'));

        // If you're on S3, generate a signed URL (adjust expiry as needed).
        try {
            $driver = config('filesystems.disks.' . $disk->getDriver()->getAdapter()->getPathPrefix());
        } catch (\Throwable $e) { /* ignore */ }

        $driverName = config('filesystems.default');

        if ($driverName === 's3') {
            return Storage::disk('s3')->temporaryUrl($rel, now()->addHour());
        }

        // Local/public: ensure storage link exists (php artisan storage:link)
        // Prefer Storage::url(); fallback to asset()
        $url = Storage::url($rel);
        return $url ?: asset('storage/'.$rel);
    }


}
