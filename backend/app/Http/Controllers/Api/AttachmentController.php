<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(Request $request, int $transaction): JsonResponse
    {
        $item = Transaction::where('user_id', $request->user()->id)->findOrFail($transaction);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $file = $data['file'];
        $path = $file->store('attachments/'.$request->user()->id, 'local');
        $attachment = Attachment::create(['user_id' => $request->user()->id, 'transaction_id' => $item->id, 'path' => $path, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
        AuditService::record($request, 'attachment.created', $attachment);

        return response()->json(['data' => $attachment, 'message' => 'Anexo enviado.'], 201);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $item = Attachment::where('user_id', $request->user()->id)->findOrFail($id);

        return Storage::disk($item->disk)->download($item->path, $item->original_name, ['Content-Type' => $item->mime_type]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = Attachment::where('user_id', $request->user()->id)->findOrFail($id);
        Storage::disk($item->disk)->delete($item->path);
        $item->delete();
        AuditService::record($request, 'attachment.deleted', $item);

        return response()->json(['message' => 'Anexo removido.']);
    }
}
