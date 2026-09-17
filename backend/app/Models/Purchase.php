<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'free_trial_campaign_id', 'package_id', 'router_id', 'router_isp_id', 'subtotal', 'payment_fee', 'amount', 'reference',
        'payment_method', 'bonus_duration_minutes', 'fulfillment_type', 'status',
        'starts_at', 'voucher_id', 'verified_at', 'verification_method',
        'verified_by', 'momo_transaction_id', 'expires_at', 'admin_notes',
        'guest_phone', 'guest_code',
        'usage_policy', 'fup_period', 'base_speed_limit', 'data_allowance_bytes',
        'tier1_threshold_percent', 'tier2_threshold_percent',
        'tier1_speed_percent', 'tier2_speed_percent', 'tier3_speed_percent',
        'cycle_bytes_used', 'current_fup_tier', 'applied_speed_limit',
        'usage_policy_applied_at',
        'policy_access_status', 'capacity_month', 'capacity_reserved_bytes', 'rollover_bytes',
        'rollover_expires_on', 'queue_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'payment_fee' => 'decimal:2',
        'bonus_duration_minutes' => 'integer',
        'starts_at' => 'datetime',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'data_allowance_bytes' => 'integer',
        'cycle_bytes_used' => 'integer',
        'current_fup_tier' => 'integer',
        'tier1_threshold_percent' => 'integer',
        'tier2_threshold_percent' => 'integer',
        'tier1_speed_percent' => 'integer',
        'tier2_speed_percent' => 'integer',
        'tier3_speed_percent' => 'integer',
        'usage_policy_applied_at' => 'datetime',
        'capacity_month' => 'date',
        'capacity_reserved_bytes' => 'integer',
        'rollover_bytes' => 'integer',
        'rollover_expires_on' => 'date',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function freeTrialCampaign()
    {
        return $this->belongsTo(FreeTrialCampaign::class);
    }

    public function package()
    {
        return $this->belongsTo(InternetPackage::class, 'package_id');
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function routerIsp()
    {
        return $this->belongsTo(RouterIsp::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function smsLogs()
    {
        return $this->hasMany(PaymentSmsLog::class, 'matched_purchase_id');
    }

    public function hotspotUser()
    {
        return $this->hasOne(HotspotUser::class, 'customer_id', 'customer_id')
            ->where('router_id', $this->router_id);
    }

    public function hotspotSessions()
    {
        return $this->hasMany(HotspotSession::class);
    }

    // ── Reference generator ─────────────────────────────────────────

    public static function generateReference(): string
    {
        do {
            $reference = 'RW-'.strtoupper(Str::random(6));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    // ── Guest code generator ────────────────────────────────────────

    public static function generateGuestCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1 — avoids confusion
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (static::where('guest_code', $code)->exists());

        return $code;
    }

    // ── Status helpers ──────────────────────────────────────────────

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function isLive(): bool
    {
        return $this->fulfillment_type === 'live';
    }

    public function isVoucher(): bool
    {
        return $this->fulfillment_type === 'voucher';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpiredByTime();
    }

    public function isExpiredByTime(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'voucher_assigned', 'completed'])
            && $this->policy_access_status !== 'data_exhausted';
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function needsAttention(): bool
    {
        return in_array($this->status, ['manual_review', 'pending_activation']);
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'voucher_assigned', 'completed'])
            ->where('policy_access_status', '!=', 'data_exhausted');
    }

    public function scopeExpiring($query)
    {
        // 'active' is the live-fulfillment ongoing status (fulfillLive());
        // 'completed' is voucher-fulfillment's ongoing status
        // (fulfillVoucher()/Customer\VoucherController::redeem()) — a
        // voucher purchase's expires_at stays null until first use
        // (PurchaseService::activateVoucherAccess()), so this naturally
        // excludes any that haven't started their access window yet.
        return $query->whereIn('status', ['active', 'completed'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopePendingExpiry($query)
    {
        return $query->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopeLiveFulfilled($query)
    {
        return $query->where('fulfillment_type', 'live');
    }

    public function scopeVoucherFulfilled($query)
    {
        return $query->where('fulfillment_type', 'voucher');
    }

    public function scopeForGuest($query)
    {
        return $query->whereNull('customer_id');
    }
}
