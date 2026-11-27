<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\ShopResource;

// Admin review of shops: list, inspect documents, approve / reject / suspend
class AdminShopController extends Controller
{
    private const DOCUMENT_FIELDS = ['id_document', 'selfie', 'address_proof', 'cac_certificate'];

    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,unsubmitted,pending,approved,rejected,suspended',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid query.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Shop::with('vendor:id,firstname,surname,email,phone')->withCount('products');

        $status = $request->query('status', 'all');
        if ($status !== 'all') {
            $query->where('verification_status', $status);
        }

        if ($q = $request->query('q')) {
            // lower() on both sides: LIKE is case-sensitive on PostgreSQL
            $like = '%' . mb_strtolower(addcslashes($q, '%_\\')) . '%';

            $query->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(shops.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(shops.city) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(shops.state) LIKE ?', [$like])
                    ->orWhereHas('vendor', function ($v) use ($like) {
                        $v->whereRaw('LOWER(users.email) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(users.firstname) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(users.surname) LIKE ?', [$like]);
                    });
            });
        }

        // Oldest submissions first so nobody waits forever; everything else newest first
        $status === 'pending'
            ? $query->orderBy('submitted_at')
            : $query->orderByDesc('id');

        return ShopResource::collection(
            $query->paginate((int) $request->query('per_page', 15))->withQueryString()
        );
    }

    // Stream one uploaded document (private disk). Every view is logged.
    public function document(Request $request, $id, string $type)
    {
        if (!in_array($type, self::DOCUMENT_FIELDS, true)) {
            return response()->json(['message' => 'Unknown document.'], 404);
        }

        $shop = Shop::find($id);
        $path = $shop?->{$type};

        if (!$path || !Storage::disk(config('marketplace.private_disk'))->exists($path)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        Log::info('KYC document viewed', [
            'admin_id' => $request->user()->id,
            'shop_id' => $shop->id,
            'document' => $type,
        ]);

        return Storage::disk(config('marketplace.private_disk'))->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    // PUT { decision: approve|reject|suspend, reason? }
    public function verification(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject,suspend',
            'reason' => 'required_if:decision,reject,suspend|nullable|string|min:10|max:500',
        ], [
            'reason.required_if' => 'Give the vendor a reason (at least 10 characters).',
            'reason.min' => 'Give the vendor a reason (at least 10 characters).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shop = Shop::find($id);

        if (!$shop) {
            return response()->json(['message' => 'Shop not found.'], 404);
        }

        $decision = $request->input('decision');
        $allowedFrom = [
            'approve' => [Shop::PENDING, Shop::SUSPENDED],
            'reject' => [Shop::PENDING],
            'suspend' => [Shop::APPROVED, Shop::PENDING, Shop::REJECTED, Shop::UNSUBMITTED],
        ];

        if (!in_array($shop->verification_status, $allowedFrom[$decision], true)) {
            return response()->json([
                'message' => "You can't {$decision} a shop that is {$shop->verification_status}.",
            ], 422);
        }

        $admin = $request->user();

        match ($decision) {
            'approve' => $this->apply($shop, Shop::APPROVED, true, null),
            'reject' => $this->apply($shop, Shop::REJECTED, false, $request->input('reason')),
            'suspend' => $this->apply($shop, Shop::SUSPENDED, false, $request->input('reason')),
        };

        $shop->reviewed_at = now();
        $shop->reviewed_by = $admin->id;
        $shop->verified_at = $decision === 'approve' ? now() : null;
        $shop->verified_by = $decision === 'approve' ? $admin->id : null;
        $shop->save();

        Log::info('Shop review decision', [
            'admin_id' => $admin->id,
            'shop_id' => $shop->id,
            'decision' => $decision,
        ]);

        return (new ShopResource($shop->load('vendor:id,firstname,surname,email,phone')->loadCount('products')))
            ->additional(['message' => 'Shop ' . $shop->verification_status . '.'])
            ->response();
    }

    private function apply(Shop $shop, string $status, bool $verified, ?string $reason): void
    {
        $shop->verification_status = $status;
        $shop->is_verified = $verified;
        $shop->rejection_reason = $reason;
    }
}
