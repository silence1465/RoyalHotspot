<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'package_id', 'router_id', 'status',
        'used_by_customer_id', 'used_at', 'expires_at', 'batch_id', 'generated_by',
        // Royal WiFi extension — see docs/DATABASE_SCHEMA.md
        'import_batch_id', 'purchase_id', 'assigned_to', 'assigned_at', 'amount', 'duration_days',
        'mikrotik_user_id',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
        'assigned_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function package()
    {
        return $this->belongsTo(InternetPackage::class, 'package_id');
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function usedBy()
    {
        return $this->belongsTo(Customer::class, 'used_by_customer_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function importBatch()
    {
        return $this->belongsTo(VoucherImportBatch::class, 'import_batch_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Who this code was handed to via a completed Royal WiFi order — see
     * the class docblock on the 2024_02_01_000003 migration for why this
     * is distinct from usedBy()/used_at.
     */
    public function assignedTo()
    {
        return $this->belongsTo(Customer::class, 'assigned_to');
    }

    /**
     * Long, random, non-sequential code — sequential/short codes are
     * brute-forceable against the redeem endpoint (see review note).
     * Used by the ORIGINAL in-app generate flow only — PDF-imported
     * Royal WiFi codes come from MikroTik itself via extraction, not
     * this generator.
     */
    public static function generateCode(): string
    {
        return strtoupper(Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4));
    }

    public function isRedeemable(): bool
    {
        if ($this->status !== 'unused' && $this->status !== 'available') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * True for a code that can be locked and handed out by the Royal
     * WiFi order-assignment flow (Phase 4) — 'available' only, same
     * "unused" concept the original enum used, just renamed per the
     * spec's vocabulary (see migration for the data-preserving rename).
     */
    public function isAvailableForAssignment(): bool
    {
        if ($this->status !== 'available') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}

