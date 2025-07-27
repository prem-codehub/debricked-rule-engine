<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DependencyFile extends Model
{
    protected $fillable = [
        'dependency_upload_id',
        'ci_upload_id',
        'filename',
        'path',
        'vulnerabilities_found',
        'progress',
        'raw_data'
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];

    public function upload(): HasOne
    {
        return $this->hasOne(DependencyUpload::class, 'id', 'dependency_upload_id');
    }
}
