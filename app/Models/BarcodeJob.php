<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
}
