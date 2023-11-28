<?php

namespace Chatify\Models;

use App\Models\User;
use Chatify\Traits\UUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Class ChMessage
 *
 * @property string $id
 * @property int $from_id
 * @property int $to_id
 * @property string|null $body
 * @property string|null $attachment
 * @property bool $seen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $from
 * @property-read User $to
 */
class ChMessage extends Model
{
    use UUID;

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_id');
    }
}
