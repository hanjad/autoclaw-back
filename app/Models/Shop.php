<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use SoftDeletes;

    public const UNSUBMITTED = 'unsubmitted';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';

    // Only profile fields are mass-assignable. Verification fields are always set explicitly.
    protected $fillable = [
        'vendor_id',
        'name',
        'address',
        'country',
        'state',
        'city',
        'zipcode',
        'logo',
        'description',
        'phone',
    ];

    // Never serialized by accident (ShopResource decides what is shown)
    protected $hidden = [
        'account_number',
        'id_document',
        'selfie',
        'address_proof',
        'cac_certificate',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'bank_name_match' => 'boolean',
        'account_number' => 'encrypted',
        'verified_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Once submitted, identity details can't be edited (stops a verified shop being swapped for another business)
    public function isLocked(): bool
    {
        return in_array($this->verification_status, [self::PENDING, self::APPROVED, self::SUSPENDED], true);
    }
}
