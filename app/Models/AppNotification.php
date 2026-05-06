<?php

namespace App\Models;

use App\Enums\Notification\NotificationAudienceTypeEnum;
use App\Enums\Notification\NotificationTargetTypeEnum;
use App\Enums\SpatieMediaCollectionName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @method static create(array $data)
 * @method static where(string $column, mixed $value)
 * @method static find(mixed $id)
 */
class AppNotification extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $table = 'app_notifications';

    protected $fillable = [
        'audience_type',
        'title',
        'message',
        'target_type',
        'metadata',
        'created_by',
    ];

    protected $appends = ['notification_image'];

    protected function casts(): array
    {
        return [
            'audience_type' => NotificationAudienceTypeEnum::class,
            'target_type' => NotificationTargetTypeEnum::class,
            'metadata' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function userMaps(): HasMany
    {
        return $this->hasMany(AppNotificationUserMap::class, 'notification_id');
    }

    public function zoneMaps(): HasMany
    {
        return $this->hasMany(AppNotificationZoneMap::class, 'notification_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(SpatieMediaCollectionName::APP_NOTIFICATION_IMAGE())->singleFile();
    }

    public function getNotificationImageAttribute(): ?string
    {
        $url = $this->getFirstMediaUrl(SpatieMediaCollectionName::APP_NOTIFICATION_IMAGE());
        return $url ?: null;
    }
}
