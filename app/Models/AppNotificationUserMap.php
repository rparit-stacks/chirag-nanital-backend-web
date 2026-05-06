<?php

namespace App\Models;

use App\Enums\Notification\NotificationAudienceTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static create(array $data)
 * @method static where(string $column, mixed $value)
 */
class AppNotificationUserMap extends Model
{
    use HasFactory;

    protected $table = 'app_notification_user_map';

    protected $fillable = [
        'notification_id',
        'user_id',
        'user_type',
    ];

    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'user_id' => 'integer',
            'user_type' => NotificationAudienceTypeEnum::class,
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AppNotification::class, 'notification_id');
    }
}
