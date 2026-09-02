<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherImportBatch extends Model
{
    protected $fillable = [
        'file_path', 'original_filename', 'uploaded_by', 'router_id', 'status',
        'total_extracted', 'total_imported', 'total_duplicates', 'total_invalid',
        'extraction_meta', 'imported_at',
    ];

    protected $casts = [
        'extraction_meta' => 'array',
        'imported_at' => 'datetime',
    ];

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'import_batch_id');
    }
}
