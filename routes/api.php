<?php

use App\Http\Controllers\ActivityController;
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
Route::get('/user-profile/{subId}', [UserController::class, 'getUserProfile']);


Route::middleware('auth:sanctum')->group(function () {
    //Frontend APIs
    Route::get('/test-token', [AuthController::class, 'testToken']);
    Route::put('/user-profile/{subId}', [UserController::class, 'editBio']);
    Route::post('/list-book', [BookController::class, 'listBook']);
    Route::get('/book/genres', [BookController::class, 'getGenres']);
    Route::post('/book/edit/{id}', [BookController::class, 'editBook']);
    Route::delete('/book/delete/{id}', [BookController::class, 'deleteBook']);
    Route::get('/borrow-requests', [BorrowEventController::class, 'viewBorrowRequests']);
    Route::post('/borrow-event', [BorrowEventController::class, 'borrowBook']);
    Route::get('/borrow-events', [BorrowEventController::class, 'viewBorrowEvents']);
    Route::get('/borrow-events/{borrowEventId}', [BorrowEventController::class, 'viewBorrowRequest']);
    Route::post('/borrow-events/{borrowEventId}/cancel', [BorrowEventController::class, 'cancelBorrowEvent']);
    Route::post('/borrow-events/set-meet-up/{borrowEventId}', [MeetUpDetailController::class, 'confirmAndSetMeetUp']);
    Route::post('/borrow-events/set-meet-up/confirm-meet-up/{borrowEventId}', [MeetUpDetailController::class, 'confirmMeetUp']);
    Route::post('/set-meet-up/{borrowEventId}/suggest-meet-up', [MeetUpDetailController::class, 'suggestMeetUp']);
    Route::post('/set-meet-up/{borrowEventId}/suggest-meet-up-confirmation', [MeetUpDetailController::class, 'confirmMeetUpSuggestion']);
    Route::post('/confirm-return-suggestion/{borrowEventId}', [ReturnDetailController::class, 'confirmReturnDetailSuggestion']);
    Route::post('/suggest-return-detail/{borrowEventId}', [ReturnDetailController::class, 'suggestReturnDetail']);
    Route::post('/set-return-date/{borrowEventId}', [ReturnDetailController::class, 'receiveBookAndSetReturnDetail']);
    Route::post('/reject-borrow-request/{borrowEventId}', [BorrowEventController::class, 'rejectBorrowRequest']);
    Route::post('/confirm-receive-book/{borrowEventId}', [BorrowEventController::class, 'confirmReceivedBook']);
    Route::post('/report-borrow-event/{borrowEventId}', [BorrowEventController::class, 'reportBorrowEvent']);
    Route::get('/history', [BorrowEventController::class, 'getAllHistoryBorrowEvent']);
    Route::post('/check-borrow-event', [BorrowEventController::class, 'checkForReturnBorrowEvent']);
    Route::post('/check-unconfirmed-meetups', [BorrowEventController::class, 'checkForUnconfirmedMeetups']);
    Route::post('/check-unaccepted-borrow-requests', [BorrowEventController::class, 'checkForUnacceptedRequests']);
    Route::post('/check-overdue-accepted-borrow-events', [BorrowEventController::class, 'checkForOverdueAcceptedEvents']);
    Route::post('/check-overdue-return-events', [BorrowEventController::class, 'checkForOverdueReturnEvents']);
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::get('/locations', [AdminController::class, 'getLocations']);


    //Admin APIs
    Route::post('/admin/logout', [AdminController::class, 'logout']);
    Route::get('/admin/check', [AdminController::class, 'check']);
    Route::get('/admin/all-data-number', [AdminController::class, 'getAllData']);
    Route::get('/admin/all-users', [AdminController::class, 'getAllUsers']);
    Route::get('/admin/get-user/{userId}', [AdminController::class, 'getUserById']);
    Route::get('/admin/get-user/{userId}/books', [AdminController::class, 'getUserBooks']);
    Route::get('/admin/get-user/{userId}/borrow-events', [AdminController::class, 'getUserBorrowEvents']);
    Route::post('/admin/search/users', [AdminController::class, 'users']);
    Route::post('/admin/search/books', [AdminController::class, 'books']);
    Route::get('/admin/genres', [AdminController::class, 'getGenres']);
    Route::get('/admin/all-books', [AdminController::class, 'getAllBooks']);
    Route::get('/admin/get-book/{bookId}', [AdminController::class, 'getBookById']);
    Route::get('/admin/dashboard/stats', [AdminController::class, 'dashboard']);
    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::get('/admin/users/total', [AdminController::class, 'getTotalUsers']);
    Route::get('/admin/users/user/{id}', [AdminController::class, 'fetchUserbyId']);
    Route::get('/admin/users/books/{id}', [AdminController::class, 'fetchUserBooksById']);
    Route::get('/admin/users/borrows/{id}', [AdminController::class, 'fetchUserBorrowEventsById']);
    Route::patch('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus']);
    Route::get('/admin/books', [AdminController::class, 'indexBooks']);
    Route::get('/admin/books/total', [AdminController::class, 'getTotalBooks']);
    Route::get('/admin/books/{id}', [AdminController::class, 'getBookById']);
    Route::patch('/admin/books/{id}/status', [AdminController::class, 'updateBookStatus']);
    Route::get('/admin/genres/popular-genres', [AdminController::class, 'getPopularGenres']);
    Route::get('/admin/borrow-activities', [AdminController::class, 'indexBorrowActivities']);
    Route::get('/admin/borrow-activities/{id}', [AdminController::class, 'getBorrowActivityById']);
    Route::get('/admin/reports/borrow-activities', [AdminController::class, 'getBorrowActivityReport']);
    Route::patch('/admin/reports/borrow-activities/{id}/status', [AdminController::class, 'updateBorrowActivityReportStatus']);
    Route::get('/admin/borrow-statuses', [AdminController::class, 'getBorrowStatuses']);
    Route::get('/admin/dashboard/borrow-activities', [AdminController::class, 'dashboardBorrowEvents']);
    Route::get('/admin/locations', [AdminController::class, 'getLocations']);
    Route::post('/admin/locations', [AdminController::class, 'createLocation']);
    Route::put('/admin/locations/{id}', [AdminController::class, 'updateLocation']);
    Route::delete('/admin/locations/{id}', [AdminController::class, 'deleteLocation']);
    Route::get('/admin/total-admins', [AdminController::class, 'getTotalAdmins']);
    Route::post('/admin/create-admin', [AdminController::class, 'createAdmin']);
});





Route::get('/all-books', [BookController::class, 'viewAllBooks']);
Route::get('/get-books/{bookId}', [BookController::class, 'viewBook']);
Route::get('/newly-added-books', [BookController::class, 'newlyAddedBooks']);
Route::post('/admin/login', [AdminController::class, 'login']);
Route::get('/user-profile/{subId}/get-books', [BookController::class, 'getUserBookshelf']);
Route::get('/users/search', [UserController::class, 'searchUsers']);
Route::get('/books/search', [BookController::class, 'searchBooks']);
Route::fallback(function () {
    return response()->json([
        'message' => 'Route Not Found'
    ], 404);
});
