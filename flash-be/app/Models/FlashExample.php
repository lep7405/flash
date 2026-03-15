<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashExample extends Model
{
    protected $fillable = [
        'flash_id',
        'sentence',
        'pinyin',
        'translation_vi',
    ];

    public function flash(): BelongsTo
    {
        return $this->belongsTo(Flash::class);
    }
}
