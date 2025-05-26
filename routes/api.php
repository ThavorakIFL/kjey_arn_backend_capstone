<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\BorrowEventController;
use App\Http\Controllers\MeetUpDetailController;
use App\Http\Controllers\ReturnDetailController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
//public route
Route::post('login-user', [AuthController::class, 'loginUser']);
Route::get('/user-profile/{subId}', [UserController::class, 'getProfile']);


Route::middleware('auth:sanctum')->group(function () {
    //User APIs
    Route::put('/user-profile/{subId}', [UserController::class, 'editBio']);
    //Book APIs
    Route::post('/list-book', [BookController::class, 'listBook']);
    Route::post('/book/edit/{id}', [BookController::class, 'editBook']);
    Route::delete('/book/delete/{id}', [BookController::class, 'deleteBook']);
    //Borrow Event APIs
    Route::get('/borrow-requests', [BorrowEventController::class, 'viewBorrowRequests']);
    Route::post('/borrow-event', [BorrowEventController::class, 'borrowBook']);
    Route::get('/borrow-events', [BorrowEventController::class, 'viewBorrowEvents']);
    Route::get('/borrow-events/{borrowEventId}', [BorrowEventController::class, 'viewBorrowEvent']);
    Route::post('/borrow-events/{borrowEventId}/cancel', [BorrowEventController::class, 'cancelBorrowEvent']);
    Route::post('/borrow-events/set-meet-up/{borrowEventId}', [MeetUpDetailController::class, 'setMeetUp']);
    Route::post('/borrow-events/set-meet-up/confirm-meet-up/{borrowEventId}', [MeetUpDetailController::class, 'confirmMeetUp']);
    Route::post('/set-meet-up/{borrowEventId}/suggest-meet-up', [MeetUpDetailController::class, 'suggestMeetUp']);
    Route::post('/set-meet-up/{borrowEventId}/suggest-meet-up-confirmation', [MeetUpDetailController::class, 'confirmMeetUpSuggestion']);
    Route::post('/confirm-return-suggestion/{borrowEventId}', [ReturnDetailController::class, 'confirmReturnDetailSuggestion']);
    Route::post('/suggest-return-detail/{borrowEventId}', [ReturnDetailController::class, 'suggestReturnDetail']);
    Route::post('/set-return-date/{borrowEventId}', [ReturnDetailController::class, 'setReturnDetail']);
    Route::post('/reject-borrow-request/{borrowEventId}', [BorrowEventController::class, 'rejectBorrowRequest']);
    Route::post('/confirm-receive-book/{borrowEventId}', [BorrowEventController::class, 'confirmReceivedBook']);
    Route::get('/history', [BorrowEventController::class, 'getAllHistoryBorrowEvent']);

    //Admin APIs
    Route::post('/admin/logout', [AdminController::class, 'logout']);
    Route::get('/admin/check', [AdminController::class, 'check']);
    Route::get('/admin/all-data-number', [AdminController::class, 'getAllData']);
    Route::get('/admin/all-users', [AdminController::class, 'getAllUsers']);
    Route::get('/admin/get-user/{userId}', [AdminController::class, 'getUserById']);
    Route::post('/admin/search/users', [AdminController::class, 'searchUsers']);
    Route::get('/admin/all-books', [AdminController::class, 'getAllBooks']);
    Route::get('/admin/get-book/{bookId}', [AdminController::class, 'getBookById']);
});

//Public APIs

//User APIs

//Book APIs
Route::get('/all-books', [BookController::class, 'viewAllBooks']);
Route::get('/get-books/{bookId}', [BookController::class, 'viewBook']);
Route::get('/newly-added-books', [BookController::class, 'newlyAddedBooks']);
//Admin APIs


Route::post('/admin/login', [AdminController::class, 'login']);
Route::get('/user-profile/{subId}/get-books', [BookController::class, 'getUserBooks']);
Route::get('/books/search', [BookController::class, 'searchBooks']);
Route::fallback(function () {
    return response()->json([
        'message' => 'Route Not Found'
    ], 404);
});



// Route::post('/user-profile/{subId}', [UserController::class, 'updateProfilePicture']);
