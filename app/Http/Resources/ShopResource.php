<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Only for the shop's owner and for admins. Public product responses never include this.
class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'name' => $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zipcode' => $this->zipcode,
            'logo_url' => Media::url($this->logo),
            'is_verified' => (bool) $this->is_verified,
            'verification_status' => $this->verification_status,
            'rejection_reason' => $this->rejection_reason,
            'verified_at' => $this->verified_at,
            'business_type' => $this->business_type,
            'kyc' => [
                'id_type' => $this->id_type,
                'has_id_document' => (bool) $this->id_document,
                'has_selfie' => (bool) $this->selfie,
                'has_address_proof' => (bool) $this->address_proof,
                'has_cac_certificate' => (bool) $this->cac_certificate,
                'cac_number' => $this->cac_number,
                'tin' => $this->tin,
                'bank_name' => $this->bank_name,
                'account_last4' => $this->account_number ? substr($this->account_number, -4) : null,
                'account_name' => $this->account_name,
                'bank_account_name' => $this->bank_account_name,
                'bank_name_match' => $this->bank_name_match,
                'submitted_at' => $this->submitted_at,
                'reviewed_at' => $this->reviewed_at,
            ],
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'name' => trim($this->vendor->firstname . ' ' . $this->vendor->surname),
                'email' => $this->vendor->email,
                'phone' => $this->vendor->phone,
            ]),
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at,
        ];
    }
}
