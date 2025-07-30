<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DependencyUpload extends Model
{
    protected $fillable = [
        'user_id',
        'ci_upload_id',
        'commit_name',
        'repository_name',
        'file_paths',
        'status',
        'vulnerability_count',
        'error_message',
    ];

    protected $casts = [
        'file_paths' => 'array',
    ];

    /**
     * Get the files associated with the dependency upload.
     */
    public function files(): HasMany
    {
        return $this->hasMany(DependencyFile::class);
    }

    /**
     * Get the user who uploaded the dependency.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

}