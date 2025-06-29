<?php

// app/Http/Controllers/Auth/GoogleAuthController.php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function loginUser(Request $request)
    {
        // Validate the incoming data
        $validated = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email',
            'sub' => 'required|string',
            'picture' => 'nullable|string',
            'bio' => 'nullable|string',
        ]);

        if ($validated->fails()) {
            return response()->json([
                'error' => $validated->errors(),
            ], 422);
        }

        try {
            $validatedData = $validated->validated();
            $pictureUrl = $validatedData['picture'] ?? null;
            $localPicturePath = null;

            $user = User::where('email', $validatedData['email'])
                ->orWhere('sub', $validatedData['sub'])
                ->first();


            if ((!$user || empty($user->picture) || $user->picture === null) && $pictureUrl) {
                $ramdomString = Str::random(16);
                $filename = $ramdomString . '.jpg';

                // Check if image already exists in storage
                if (!Storage::disk('public')->exists('profiles/' . $filename)) {
                    $imageContent = file_get_contents($pictureUrl);
                    if ($imageContent) {
                        // Store the image in the 'public' disk
                        Storage::disk('public')->put('profiles/' . $filename, $imageContent);
                        // Generate the local URL for the image
                        $localPicturePath =  'storage/profiles/' . $filename;
                    }
                } else {
                    // If image exists, just set the path
                    $localPicturePath = '/storage/profiles/' . $filename;
                }
            }

            if (!$user) {
                // Create a new user if not found
                $user = User::create([
                    'name' => $validatedData['name'],
                    'email' => $validatedData['email'],
                    'sub' => $validatedData['sub'],
                    'picture' => $localPicturePath ?? $pictureUrl,
                    'bio' => $validatedData['bio'] ?? '',
                    'status' => 1,
                ]);
            } else {

                $updateData = [
                    'name' => $validatedData['name'],
                    'bio' => $validatedData['bio'] ?? $user->bio,
                ];

                if ((empty($user->picture) || $user->picture === null) && ($localPicturePath || $pictureUrl)) {
                    $updateData['picture'] = $localPicturePath ?? $pictureUrl;
                }

                // Update user with the appropriate fields
                $user->update($updateData);
            }
            $token = $user->createToken('API Token')->plainTextToken;
            // Calculate expiration time based on config
            $expirationMinutes = config('sanctum.expiration'); // Gets your 5 minutes from config
            $expiresAt = $expirationMinutes ? now()->addMinutes($expirationMinutes) : null;

            // Create a new Sanctum token with explicit expiration
            $token = $user->createToken('API Token', ['*'], $expiresAt)->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
                'expires_at' => $expiresAt ? $expiresAt->toISOString() : null,
                'expires_in' => $expiresAt ? $expiresAt->diffInSeconds(now()) : null, // seconds until expiration
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong. Please try again: ' . $e->getMessage()], 500);
        }
    }
}
