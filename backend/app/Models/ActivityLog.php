<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'customer_id', 'action', 'description', 'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public static function record(string $action, ?string $description = null, array $actor = []): self
    {
        return static::create([
            'user_id' => $actor['user_id'] ?? null,
            'customer_id' => $actor['customer_id'] ?? null,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }
}
