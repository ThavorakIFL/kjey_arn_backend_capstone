<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            $admin = Admin::where('username', $credentials['username'])->first();

            if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
                Log::warning('Failed login attempt', [
                    'username' => $credentials['username'],
                    'ip' => $request->ip()
                ]);
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
            Log::info('Admin found: ' . $admin->id);
            // Delete old tokens (optional - for single session)
            $admin->tokens()->delete();
            $token = $admin->createToken('admin-token')->plainTextToken;
            Log::info('Token created: ' . substr($token, 0, 10) . '...');
            Log::info('Admin tokens count: ' . $admin->tokens()->count());

            Log::info('Admin logged in successfully', [
                'admin_id' => $admin->id,
                'username' => $admin->username,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'admin' => $admin,
                'token' => $token
            ]);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json(['message' => 'An error occurred during login'], 500);
        }
    }

    public function getAllData()
    {
        $totalUsers = User::count();
        $totalBooks = Book::count();

        return response()->json([
            'total_users' => $totalUsers,
            'total_books' => $totalBooks,
        ]);
    }

    public function getAllUsers()
    {
        $users = User::select('id', 'name', 'email', 'picture')->get();
        return response()->json($users);
    }

    public function getUserById($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json($user);
    }

    public function searchUsers(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:100',
        ]);
        $query = trim($request->input('query'));
        if (!$query) {
            return response()->json(['message' => 'Search query is required'], 400);
        }
        $users = User::where('name', 'LIKE', "%{$query}%")
            ->select('id', 'name', 'email', 'picture')
            ->get();
        return response()->json([
            'data' => $users,
            'count' => $users->count(),
            'type' => 'users',
        ]);
    }

    public function getAllBooks()
    {
        $book = Book::with(['user', 'genres', 'pictures', 'availability'])->get();

        return response()->json($book);
    }

    public function getBookById($bookId)
    {
        $book = Book::with(['user', 'genres', 'pictures', 'availability'])->find($bookId);
        if (!$book) {
            return response()->json(['message' => 'Book not found'], 404);
        }
        return response()->json($book);
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function check(Request $request)
    {
        return response()->json($request->user());
    }
}
