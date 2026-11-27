<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\User;
use App\Services\BankService;
use App\Support\NameMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Http\Resources\ShopResource;
use RuntimeException;
use Throwable;

// A vendor's own shop: create it, edit the public profile, and submit documents for verification
class VendorShopController extends Controller
{
    private const PHONE = '/^0[789][01]\d{8}$/';
    private const DOCUMENT = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';
    private const LOCKED_FIELDS = ['name', 'address', 'city', 'state', 'country', 'zipcode'];

    public function show(Request $request)
    {
        $shop = $this->myShop($request);

        return response()->json(['data' => $shop ? new ShopResource($shop) : null], 200);
    }

    public function banks(BankService $banks)
    {
        return response()->json(['banks' => $banks->banks()], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $shop = DB::transaction(function () use ($request, $user) {
            User::query()->lockForUpdate()->findOrFail($user->id);

            if (Shop::where('vendor_id', $user->id)->count() >= config('marketplace.max_shops_per_vendor')) {
                return response()->json(['message' => 'You already have a shop.'], 422);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:120', Rule::unique('shops', 'name')->whereNull('deleted_at')],
                'description' => 'nullable|string|max:1000',
                'phone' => ['required', 'string', 'regex:' . self::PHONE],
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'country' => 'nullable|string|max:100',
                'zipcode' => 'nullable|string|max:20',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->invalid($validator);
            }

            $shop = new Shop($request->only(['name', 'description', 'phone', 'address', 'city', 'state', 'zipcode']));
            $shop->vendor_id = $user->id;
            $shop->country = $request->input('country', 'Nigeria');

            if ($request->hasFile('logo')) {
                $shop->logo = $request->file('logo')->store('shop_logos', config('marketplace.public_disk'));
            }

            $shop->save();

            return $shop;
        });

        if (!$shop instanceof Shop) {
            return $shop;
        }

        return (new ShopResource($shop))
            ->additional(['message' => 'Shop created. Submit your documents to get verified.'])
            ->response()
            ->setStatusCode(201);
    }

    // Send as POST with _method=PUT when uploading a logo
    public function update(Request $request)
    {
        $oldLogo = null;
        $shop = DB::transaction(function () use ($request, &$oldLogo) {
            $shop = $this->myShop($request, lock: true);

            if (!$shop) {
                return response()->json(['message' => 'Create your shop first.'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => ['sometimes', 'required', 'string', 'max:120',
                    Rule::unique('shops', 'name')->whereNull('deleted_at')->ignore($shop->id)],
                'description' => 'nullable|string|max:1000',
                'phone' => ['sometimes', 'required', 'string', 'regex:' . self::PHONE],
                'address' => 'sometimes|required|string|max:255',
                'city' => 'sometimes|required|string|max:100',
                'state' => 'sometimes|required|string|max:100',
                'country' => 'sometimes|required|string|max:100',
                'zipcode' => 'nullable|string|max:20',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            ]);

            // After submission the shop's identity is locked
            $validator->after(function ($validator) use ($request, $shop) {
                if (!$shop->isLocked()) {
                    return;
                }

                foreach (self::LOCKED_FIELDS as $field) {
                    if ($request->has($field) && (string) $request->input($field) !== (string) $shop->{$field}) {
                        $validator->errors()->add($field, 'This can\'t be changed after you submit for verification. Contact support to change it.');
                    }
                }
            });

            if ($validator->fails()) {
                return $this->invalid($validator);
            }

            $shop->fill($request->only(['name', 'description', 'phone', 'address', 'city', 'state', 'country', 'zipcode']));

            if ($request->hasFile('logo')) {
                $oldLogo = $shop->logo;
                $shop->logo = $request->file('logo')->store('shop_logos', config('marketplace.public_disk'));
            }

            $shop->save();

            return $shop;
        });

        if (!$shop instanceof Shop) {
            return $shop;
        }

        if ($oldLogo) {
            Storage::disk(config('marketplace.public_disk'))->delete($oldLogo);
        }

        return (new ShopResource($shop))
            ->additional(['message' => 'Shop updated.'])
            ->response();
    }

    // Submit identity, business and bank details for review
    public function submitVerification(Request $request, BankService $banks)
    {
        $user = $request->user();
        $shop = $this->myShop($request);

        if (!$shop) {
            return response()->json(['message' => 'Create your shop first.'], 404);
        }

        if (!$user->email_verified_at) {
            return response()->json(['message' => 'Verify your email address before submitting your shop.'], 403);
        }

        if ($error = $this->submissionStatusError($shop)) {
            return $error;
        }

        $isBusiness = $request->input('business_type') === 'registered_business';
        $validator = $this->submissionValidator($request, $shop, $isBusiness);

        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        // Bank details: check the account exists and read the name the bank holds for it
        $bankList = collect($banks->banks());
        $bank = $bankList->firstWhere('code', $request->input('bank_code'));

        if (!$bank) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['bank_code' => ['Choose a bank from the list.']],
            ], 422);
        }

        $lookup = $banks->resolve($request->input('account_number'), $bank['code']);

        if ($lookup['status'] === 'invalid') {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['account_number' => ['We couldn\'t find this account. Check the number and the bank.']],
            ], 422);
        }

        $disk = config('marketplace.private_disk');
        $staged = [];
        $replaced = [];

        try {
            $result = DB::transaction(function () use ($request, $user, $shop, $isBusiness, $bank, $lookup, $disk, &$staged, &$replaced) {
                $shop = Shop::where('vendor_id', $user->id)->lockForUpdate()->find($shop->id);

                if (!$shop) {
                    return response()->json(['message' => 'Create your shop first.'], 404);
                }

                if ($error = $this->submissionStatusError($shop)) {
                    return $error;
                }

                // A concurrent submission and rejection can change which files are already on record.
                $validator = $this->submissionValidator($request, $shop, $isBusiness);
                if ($validator->fails()) {
                    return $this->invalid($validator);
                }

                // Does the account holder match the owner (or the business name)?
                $match = null;
                if ($lookup['status'] === 'verified') {
                    $owner = trim($user->firstname . ' ' . $user->surname);
                    $match = NameMatcher::matches($lookup['name'], $owner)
                        || ($isBusiness && NameMatcher::matches($lookup['name'], $shop->name));
                }

                $store = function (string $field) use ($request, $shop, $disk, &$staged, &$replaced) {
                    if (!$request->hasFile($field)) {
                        return $shop->{$field};
                    }

                    $path = $request->file($field)->store("shop_kyc/{$shop->id}", $disk);
                    if ($path === false) {
                        throw new RuntimeException("Could not store the {$field} document.");
                    }

                    $staged[] = $path;
                    if ($shop->{$field}) {
                        $replaced[] = $shop->{$field};
                    }

                    return $path;
                };

                $shop->business_type = $request->input('business_type');
                $shop->id_type = $request->input('id_type');
                $shop->id_document = $store('id_document');
                $shop->selfie = $store('selfie');
                $shop->address_proof = $store('address_proof');
                $shop->cac_number = $isBusiness
                    ? strtoupper(preg_replace('/[\s-]+/', '', $request->input('cac_number')))
                    : null;
                $shop->cac_certificate = $isBusiness ? $store('cac_certificate') : null;
                $shop->tin = $request->input('tin');
                $shop->bank_code = $bank['code'];
                $shop->bank_name = $bank['name'];
                $shop->account_number = $request->input('account_number');
                $shop->account_name = $request->input('account_name');
                $shop->bank_account_name = $lookup['name'];
                $shop->bank_name_match = $match;
                $shop->terms_accepted_at = now();
                $shop->verification_status = Shop::PENDING;
                $shop->rejection_reason = null;
                $shop->submitted_at = now();

                if (!$shop->save()) {
                    throw new RuntimeException('Could not save the shop verification.');
                }

                return $shop;
            });
        } catch (Throwable $exception) {
            $this->deleteVerificationFiles($disk, $staged);
            throw $exception;
        }

        if (!$result instanceof Shop) {
            return $result;
        }

        $this->deleteVerificationFiles($disk, $replaced);
        $shop = $result;

        return (new ShopResource($shop))
            ->additional(['message' => 'Submitted. We\'ll review your documents and get back to you.'])
            ->response();
    }

    private function submissionStatusError(Shop $shop)
    {
        if (!in_array($shop->verification_status, [Shop::UNSUBMITTED, Shop::REJECTED], true)) {
            return response()->json([
                'message' => $shop->verification_status === Shop::PENDING
                    ? 'Your shop is already under review.'
                    : 'This shop can\'t be submitted for verification right now.',
            ], 422);
        }

        return null;
    }

    private function submissionValidator(Request $request, Shop $shop, bool $isBusiness)
    {
        // On a resubmission, documents already on file don't have to be uploaded again
        $needs = fn (?string $existing) => $existing ? 'nullable' : 'required';

        return Validator::make($request->all(), [
            'business_type' => 'required|in:individual,registered_business',
            'id_type' => 'required|in:nin,passport,drivers_license,voters_card',
            'id_document' => [$needs($shop->id_document), ...explode('|', self::DOCUMENT)],
            'selfie' => [$needs($shop->selfie), 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'address_proof' => [$needs($shop->address_proof), ...explode('|', self::DOCUMENT)],
            'cac_number' => [$isBusiness ? 'required' : 'nullable', 'string', 'regex:/^(RC|BN|IT)?-?\s?\d{4,8}$/i'],
            'cac_certificate' => [$isBusiness ? $needs($shop->cac_certificate) : 'nullable', ...explode('|', self::DOCUMENT)],
            'tin' => ['nullable', 'string', 'regex:/^[0-9\-]{6,20}$/'],
            'bank_code' => 'required|string|max:10',
            'account_number' => 'required|digits:10',
            'account_name' => 'required|string|max:150',
            'accept_terms' => 'accepted',
        ], [
            'cac_number.regex' => 'Enter the number as printed on the certificate, e.g. RC1234567 or BN1234567.',
            'account_number.digits' => 'A Nigerian account number has 10 digits.',
            'accept_terms.accepted' => 'You must accept the seller terms.',
        ]);
    }

    private function deleteVerificationFiles(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (!Storage::disk($disk)->delete($path)) {
                    report(new RuntimeException('Could not delete a shop verification file.'));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function myShop(Request $request, bool $lock = false): ?Shop
    {
        $query = Shop::where('vendor_id', $request->user()->id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function invalid($validator)
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }
}
