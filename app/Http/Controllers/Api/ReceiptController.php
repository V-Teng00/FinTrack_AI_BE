<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Models\Receipt;
use App\Services\ReceiptExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptExtractionService $extraction,
    ) {}

    public function index(Request $request)
    {
        $query = $request->user()->receipts()->latest('date');

        if ($request->filled('month')) {
            $query->inMonth($request->string('month'));
        }

        return ReceiptResource::collection($query->get());
    }

    public function store(StoreReceiptRequest $request)
    {
        $image = $request->file('image');

        try {
            $extracted = $this->extraction->extract($image);
        } catch (\Throwable $e) {
            Log::error('Receipt extraction failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Could not read this receipt. Try a clearer photo, or enter it manually.',
            ], 422);
        }

        // store the original image AFTER extraction succeeds — no point keeping
        // files for uploads that failed to parse
        $path = $image->store('receipts/' . $request->user()->id, 'public');

        $receipt = DB::transaction(function () use ($request, $extracted, $path) {
            $receipt = $request->user()->receipts()->create([
                'store_name' => $extracted['store_name'],
                'date' => $extracted['date'],
                'total' => $extracted['total'],
                'currency' => $extracted['currency'],
                'category' => $extracted['category'],
                'image_path' => $path,
                'raw_extraction' => $extracted['raw'],
                'confidence' => $extracted['confidence'],
            ]);

            foreach ($extracted['items'] as $item) {
                $receipt->items()->create($item);
            }

            return $receipt;
        });

        return new ReceiptResource($receipt);
    }

    public function destroy(Request $request, Receipt $receipt)
    {
        abort_unless($receipt->user_id === $request->user()->id, 403);

        if ($receipt->image_path) {
            Storage::disk('public')->delete($receipt->image_path);
        }

        $receipt->delete();

        return response()->json(null, 204);
    }
}
