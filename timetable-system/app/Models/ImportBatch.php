<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $fillable = [
        'file_name',
        'sheet_name',
        'total_rows',
        'success_rows',
        'failed_rows',
        'error_log',
    ];
}