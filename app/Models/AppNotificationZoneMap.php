<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static create(array $data)
 * @method static where(string $column, mixed $value)
 */
class AppNotificationZoneMap extends Model
{
    use HasFactory;

    protected $table = 'app_notification_zone_map';

    protected $fillable = [
        'notification_id',
        'zone_id',
    ];

    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'zone_id' => 'integer',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }
}
