<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;


class UserController extends Controller
{
    public function searchUsers(Request $request)
    {
        try {
            $query = $request->input('query');

            // Build the user query
            $userQuery = User::select(['name', 'email', 'picture', 'sub']);

            // Apply search filter only if query is provided
            if ($query && trim($query) !== '') {
                $userQuery->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', '%' . $query . '%')
                        ->orWhere('email', 'LIKE', '%' . $query . '%');
                });
            }

            $perPage = $request->get('per_page', 18); // Default 14 users per page
            $page = $request->get('page', 1);

            // Validate pagination parameters
            $perPage = max(1, min(100, (int)$perPage)); // Between 1 and 100
            $page = max(1, (int)$page);

            // Get paginated results
            $paginatedUsers = $userQuery->paginate($perPage, ['*'], 'page', $page);
            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $paginatedUsers->items(),
                    'pagination' => [
                        'current_page' => $paginatedUsers->currentPage(),
                        'last_page' => $paginatedUsers->lastPage(),
                        'per_page' => $paginatedUsers->perPage(),
                        'total' => $paginatedUsers->total(),
                        'from' => $paginatedUsers->firstItem(),
                        'to' => $paginatedUsers->lastItem(),
                        'has_more_pages' => $paginatedUsers->hasMorePages(),
                        'prev_page_url' => $paginatedUsers->previousPageUrl(),
                        'next_page_url' => $paginatedUsers->nextPageUrl(),
                    ]
                ],
                'message' => 'Users retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during search',
                'data' => [
                    'users' => [],
                    'pagination' => null
                ]
            ], 500);
        }
    }

    public function getUserSuggestions(Request $request)
    {
        try {
            $query = $request->input('query');

            if (!$query || trim($query) === '') {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            // Get users that match the search
            $suggestions = User::select('name', 'email', 'picture', 'sub', 'id')
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', '%' . $query . '%')
                        ->orWhere('email', 'LIKE', '%' . $query . '%');
                })
                ->limit(10) // Limit to 10 suggestions
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'picture' => $user->picture,
                        'sub' => $user->sub,
                        'display' => $user->name . ' (' . $user->email . ')'
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $suggestions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => []
            ], 500);
        }
    }

    public function getUserProfile($id)
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

    public function checkUserStatus(Request $request, $userId): JsonResponse
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return response()->json(['error' => 'User not found'], 404);
            }
            return response()->json([
                'status' => $user->status,
                'user_id' => $user->id,
                'checked_at' => now()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while checking user status: ' . $e->getMessage()], 500);
        }
    }
}
