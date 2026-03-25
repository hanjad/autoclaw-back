<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\AccountVerification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    //
    public function store(Request $request)
    {

        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'surname' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|regex:/^0[789][01]\d{8}$/|unique:users,phone',
            'password' => 'required|string|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/',
            'role' => 'nullable|string|in:admin,user,vendor',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {

            $user = new User();
            $user->firstname = $request->input('firstname');
            $user->surname = $request->input('surname');
            $user->email = $request->input('email');
            $user->phone = $request->input('phone');
            $user->password = $request->input('password');
            $user->role = $request->input('role', 'user');
            $user->save();

            // Remove existing verification tokens for the user
            DB::table('account_verifications')->where('email', $user->email)->delete();

            // Generate a new verification token
            $token = rand(100000, 999999);

            // Store the token in the database with an expiration time (e.g., 30 minutes)
            AccountVerification::create([
                'email' => $user->email,
                'token' => $token,
                'expires_at' => now()->addMinutes(30),
            ]);

            $url = config('app.frontend_url') . "/verify-email?token={$token}&email={$user->email}";
            // Send the verification email
            Mail::send('emails.user-verification', [
                'user' => $user, 
                'url' => $url, 
                'token' => $token
                ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Verify Your Email Address');
            });

            DB::commit();
            return response()->json([
                'message' => 'User created successfully.',
                'user' => $user,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while creating the user.',
                'errors' => $e->getMessage(),
            ], 500);
        }
    }

    // protected function sendEmailVerification(Model $user)
    // {
    //     // Remove existing verification tokens for the user
    //     DB::table('account_verifications')->where('email', $user->email)->delete();

    //     // Generate a new verification token
    //     $token = rand(100000, 999999);

    //     // Store the token in the database with an expiration time (e.g., 30 minutes)
    //     AccountVerification::create([
    //         'email' => $user->email,
    //         'token' => $token,
    //         'expires_at' => now()->addMinutes(30),
    //     ]);

    //     $url = config('app.frontend_url') . "/verify-email?token={$token}&email={$user->email}";
    //     // Send the verification email
    //     Mail::to($user->email)->send('emails.user-verification', [
    //         'user' => $user,
    //         'url' => $url,
    //         'token' => $token,
    //     ])->subject('Verify Your Email Address');
    // }
}
