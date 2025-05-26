<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function getProfile($id)
    {
        $sub = $id;
        if (!$sub) {
            return response()->json(['error' => 'Sub not provided'], 400);
        }
        $targetUser = User::where('sub', $sub)->first();
        if (!$targetUser) {
            return response()->json(['error' => 'User not found'], 404);
        }
        return response()->json([
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'bio' => $targetUser->bio ?? '',
            'picture' => $targetUser->picture,
            'sub' => $targetUser->sub,
        ]);
    }

    public function editBio(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $sub = $id;
        if (!$sub) {
            return response()->json(['error' => 'Sub not provided'], 400);
        }
        $validated = $request->validate([
            'bio' => 'string|max:255',
        ]);
        $profile = User::where('sub', $sub)->first();
        if (!$profile) {
            return response()->json(['error' => 'Profile not found'], 404);
        }
        $profile->update($validated);
        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => $profile
        ]);
    }

    public function updateProfilePicture(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $sub = $id;
        if (!$sub) {
            return response()->json(['error' => 'Sub not provided'], 400);
        }

        $request->validate([
            'picture' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120'
        ]);


        $profile = User::where('sub', $sub)->first();
        if (!$profile) {
            return response()->json(['error' => 'Profile not found'], 404);
        }

        try {
            // Create the profiles directory if it doesn't exist
            $profilesPath = 'profiles';
            if (!Storage::disk('public')->exists($profilesPath)) {
                Storage::disk('public')->makeDirectory($profilesPath);
            }
            $ramdomString = Str::random(16);
            // Generate filename following your original convention
            $filename =  $ramdomString . '.jpg';

            // Store the new picture in the profiles directory
            $request->file('picture')->storeAs(
                'profiles',
                $filename,
                'public'
            );

            // Update user's profile picture URL
            $localPicturePath = '/storage/profiles/' . $filename;
            $profile->picture = $localPicturePath;
            $profile->save();

            return response()->json([
                'picture' => $localPicturePath,
                'message' => 'Profile picture updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update profile picture: ' . $e->getMessage()], 500);
        }
    }
}
