<?php

namespace Chatify\Models;

use App\Models\User;
use Carbon\Carbon;
use Chatify\Traits\UUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ChFavorite
 *
 * @property string $id
 * @property int $user_id
 * @property int $favorite_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User $favorite
 */
class ChFavorite extends Model
{
    use UUID;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(User::class, 'favorite_id');
    }
}
