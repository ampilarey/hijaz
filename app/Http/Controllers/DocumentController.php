<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentVersion;
use App\Observers\AuditObserver;
use App\Services\Documents\DocumentWallet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way a scan leaves the private disk: signed link, signed-in
 * staff, policy, decrypted in memory, and an audit row every time.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentWallet $wallet) {}

    public function download(DocumentVersion $version, Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('download', $version->document), 403);
        abort_unless($this->wallet->exists($version), 404);

        $contents = $version->contents();
        abort_if($contents === null, 404);

        AuditObserver::record($version, AuditLog::DOWNLOADED, null, ['document_id' => $version->document_id, 'version' => $version->version, 'filename' => $version->original_filename]);

        return response()->streamDownload(fn () => print $contents, $version->original_filename, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Inline, for the screen where staff compare the scan with the proposed fields. */
    public function preview(DocumentVersion $version, Request $request): Response
    {
        abort_unless($request->user()?->can('download', $version->document), 403);
        abort_unless($this->wallet->exists($version), 404);

        $contents = $version->contents();
        abort_if($contents === null, 404);

        AuditObserver::record($version, 'viewed', null, ['document_id' => $version->document_id, 'version' => $version->version]);

        return response($contents, 200, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($version->original_filename).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
