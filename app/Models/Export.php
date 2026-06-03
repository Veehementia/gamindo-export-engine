<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $uuid
 * @property string $status
 * @property array  $definition
 */
class Export extends Model
{
    // Stati della macchina a stati dell'export.
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'version_id', 'format', 'status', 'definition',
        'progress', 'rows_estimated', 'rows_written',
        'cancel_requested', 'attempts',
        'file_path', 'file_name', 'file_size', 'error',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'definition' => 'array',
        'cancel_requested' => 'boolean',
        'progress' => 'integer',
        'rows_estimated' => 'integer',
        'rows_written' => 'integer',
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // L'id pubblico nelle rotte è lo uuid, non l'auto-increment.
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path;
    }
}
