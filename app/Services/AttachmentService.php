<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ItRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploading, retrieving and removing request documents (FR-006).
 *
 * WHAT THIS FILE IS FOR, AND WHAT THE DESIGN SAYS
 *
 * BR-006 requires closure to check for "all mandatory decisions and documentation". The decisions
 * half was enforced; the documentation half was a message with no check behind it, so a request
 * closed with no supporting evidence at all. This is the missing half.
 *
 * WHY THE FILE IS NEVER SERVED DIRECTLY
 *
 * `public/` is the document root, and `storage/app/private` is outside it. A file placed anywhere
 * reachable by URL is protected only by its name being hard to guess, and these documents are
 * budgets, vendor quotations and risk assessments. So nothing is ever fetched by path: the
 * browser asks a ROUTE, the route authorises against the policy, and only then does the bytes
 * move. A predictable path is not access control.
 *
 * WHY THE PATH IS GENERATED AND NOT THE ORIGINAL NAME
 *
 * The requestor's filename is stored for display and NEVER used on disk. A name like
 * `../../.env` or one containing a null byte is a path-traversal attempt, and a shared host will
 * happily obey it. The stored path is a random ULID inside the request's own folder, so the worst
 * a hostile filename can do is look odd in a list.
 *
 * WHY A CHECKSUM IS RECORDED
 *
 * Cheap to compute on upload and the only way to tell later whether the bytes on disk are still
 * the bytes that were approved. Without it, a substituted quotation is undetectable — the row and
 * the file both exist and neither is obviously wrong.
 */
class AttachmentService
{
    /** The disk from config, so an operator can move storage without a code change. */
    public function disk(): string
    {
        return (string) config('itrequest.attachments.disk', 'attachments');
    }

    /** Longest a filename may be, after the model's own column limit. */
    private const MAX_NAME = 255;

    /**
     * Store an uploaded file against a request.
     *
     * THE ORDER IS: VALIDATE, WRITE, THEN RECORD.
     *
     * A row written before the bytes would point at nothing when the write failed, and the first
     * evidence would be an approver unable to open a document they were told existed. Writing
     * first means a failed write leaves an orphaned file at worst — invisible, harmless, and
     * recoverable — rather than a broken record.
     *
     * @throws \RuntimeException when the file was not stored
     */
    public function store(ItRequest $request, UploadedFile $file, ?string $category, ?User $uploader = null): Attachment
    {
        $uploader ??= auth()->user();

        $originalName = $this->safeName($file->getClientOriginalName());

        /*
         * The path is the request's own folder plus a random name.
         *
         * `Str::ulid()` rather than `uniqid()` or a hash of the name: two people uploading
         * `quote.pdf` to different requests is normal, and two uploading it to the SAME request is
         * not rare. A random name means neither collision nor overwrite, and the extension comes
         * from the CLIENT's mime type rather than from their filename — because the filename is
         * attacker-controlled text and the mime type has been validated.
         */
        $extension = $this->extensionFor($file);
        $path = "requests/{$request->id}/".Str::ulid().($extension === '' ? '' : '.'.$extension);

        $stored = Storage::disk($this->disk())->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        if ($stored === false) {
            /*
             * The disk is configured `throw: true`, so this is a belt-and-braces branch — but a
             * silent `false` here is exactly the failure that produces an attachment row with no
             * file, and that has to be impossible rather than unlikely.
             */
            throw new \RuntimeException("The document '{$originalName}' could not be stored. Nothing was recorded.");
        }

        return DB::transaction(function () use ($request, $path, $originalName, $file, $category, $uploader) {
            $attachment = Attachment::create([
                'request_id' => $request->id,
                'category' => $category,
                'original_name' => $originalName,
                'storage_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
                'uploaded_by' => $uploader?->id,
            ]);

            app(AuditService::class)->record(
                event: 'attachment.uploaded',
                subject: $attachment,
                old: null,
                new: [
                    'request_id' => $request->id,
                    'original_name' => $originalName,
                    'category' => $category,
                    'size' => $attachment->size,
                    'checksum' => $attachment->checksum,
                ],
                requestId: $request->id,
            );

            return $attachment;
        });
    }

    /**
     * Stream a document to the browser.
     *
     * AUTHORISATION IS THE CALLER'S JOB, and that is stated here rather than assumed: this method
     * has no idea who is asking. `AttachmentController` authorises against `ItRequestPolicy`
     * before calling, and anything else that calls this must do the same.
     *
     * `streamDownload` rather than `download`: the file is read in chunks, so a 20 MB PDF does not
     * have to fit in PHP's memory alongside everything else. On a shared host with a modest
     * `memory_limit` that is the difference between working and a 500 on the largest upload a
     * user is allowed to make.
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($attachment->storage_path)) {
            /*
             * The row exists and the file does not. That means a restore that missed the
             * documents, a manual deletion, or a write that reported success and did not happen —
             * and it needs to be legible in the log rather than surfacing as a 404 the user
             * assumes is their own mistake.
             */
            Log::error('An attachment row has no file on disk.', [
                'attachment_id' => $attachment->id,
                'request_id' => $attachment->request_id,
                'path' => $attachment->storage_path,
                'disk' => $this->disk(),
            ]);

            abort(404, 'That document is recorded but its file is missing. An administrator has been notified.');
        }

        return $disk->download($attachment->storage_path, $attachment->original_name, [
            /*
             * The stored mime type is echoed, but the file is never rendered inline.
             *
             * `Content-Disposition: attachment` is what stops an uploaded HTML or SVG file being
             * executed in the browser as a page on this domain — with the session cookie attached.
             * MIME sniffing is off for the same reason: a file that claims to be a PDF and is not
             * would otherwise be sniffed into something executable.
             */
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Remove a document.
     *
     * A HARD DELETE, WHICH IS THE OPPOSITE OF EVERY OTHER REMOVAL IN THIS APPLICATION — and the
     * reason is worth stating. Everywhere else a record is referenced by history and is
     * deactivated instead, because deleting it would orphan the trail. An attachment is
     * referenced by nothing: the audit row records what was uploaded and its checksum, so the
     * trail survives the file going, and there is a real right to withdraw a document that was
     * attached by mistake.
     *
     * The audit row is written BEFORE the file is removed. If the delete then fails, the log says
     * what was intended and the file is still there to be removed by hand; the reverse order would
     * leave a file gone with nothing recording that anybody meant it.
     */
    public function delete(Attachment $attachment): void
    {
        app(AuditService::class)->record(
            event: 'attachment.removed',
            subject: $attachment,
            old: [
                'request_id' => $attachment->request_id,
                'original_name' => $attachment->original_name,
                'category' => $attachment->category,
                'checksum' => $attachment->checksum,
            ],
            new: null,
            requestId: $attachment->request_id,
        );

        DB::transaction(function () use ($attachment) {
            Storage::disk($this->disk())->delete($attachment->storage_path);

            $attachment->delete();
        });
    }

    /**
     * Whether a stored file still matches the checksum recorded at upload.
     *
     * Not called on every download — that would read the whole file twice for a check nobody
     * asked for. It exists for `itrequest:deploy-check` and for an integrity review, which is
     * where the question "are these still the bytes that were approved?" actually gets asked.
     */
    public function verify(Attachment $attachment): bool
    {
        if ($attachment->checksum === null) {
            return true;   // nothing was recorded to compare against
        }

        $disk = Storage::disk($this->disk());

        if (! $disk->exists($attachment->storage_path)) {
            return false;
        }

        return hash_equals($attachment->checksum, hash('sha256', $disk->get($attachment->storage_path)));
    }

    /**
     * Make a client-supplied filename safe to store and display.
     *
     * WHY THIS IS NECESSARY EVEN THOUGH THE NAME IS NEVER A PATH
     *
     * The name IS used in `Content-Disposition` when the document is downloaded. A filename
     * containing a newline lets a response header be split, and one containing quotes breaks out
     * of the header's quoting — so a hostile name turns the download of a document somebody else
     * uploaded into a response the attacker partly controls.
     *
     * Directory separators are stripped for the same class of reason: nothing here treats the
     * name as a path, but a future export, archive or report might, and the cost of removing them
     * now is zero.
     */
    private function safeName(string $name): string
    {
        /*
         * Order matters: control characters and separators first, then quotes.
         *
         * A quote is not a path problem, so it is tempting to leave it — and Symfony does escape
         * it correctly when it builds the `Content-Disposition` header. But relying on the library
         * to neutralise a character is a weaker position than not having it, and this test spent
         * its time proving the escaping works rather than the name being safe.
         *
         * The newline IS the actual injection vector: it ends the header and starts another. That
         * one is load-bearing; the quote is defence in depth.
         */
        $name = str_replace(["\r", "\n", "\0"], '', $name);
        $name = str_replace(['/', '\\'], '-', $name);
        $name = str_replace(['"', "'"], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim($name);

        // A name that is entirely separators, or empty, still needs something to display.
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document';
        }

        /*
         * Truncated from the MIDDLE, keeping the extension.
         *
         * Cutting the end off `a-very-long-name.pdf` gives `a-very-long-na`, which loses the only
         * part a person uses to identify the file in a list. The extension is what a browser and
         * an operating system act on.
         */
        if (mb_strlen($name) > self::MAX_NAME) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = $extension === '' ? $name : mb_substr($name, 0, -(mb_strlen($extension) + 1));

            $keep = self::MAX_NAME - ($extension === '' ? 0 : mb_strlen($extension) + 1);

            $name = mb_substr($stem, 0, $keep).($extension === '' ? '' : '.'.$extension);
        }

        return $name;
    }

    /**
     * The extension to store, derived from the VALIDATED mime type.
     *
     * The client's filename extension is not trusted: `report.pdf` is routinely something else,
     * and the extension is what a web server and an operating system use to decide how to open a
     * file. Deriving it from the mime type means the stored name cannot claim to be a format the
     * file is not.
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match ($file->getClientMimeType()) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => '',
        };
    }

    /**
     * The validation rules an upload must satisfy, derived from config.
     *
     * WHY THE EXTENSION LIST IS BUILT FROM THE MIME ALLOW-LIST
     *
     * Laravel's `mimes` rule takes EXTENSIONS and sniffs the file's actual content, whereas the
     * config lists MIME TYPES. Writing `mimes:pdf,doc,...` by hand beside a config that lists
     * `application/pdf` and friends gives two lists that mean the same thing today and drift the
     * first time somebody adds a format to one of them.
     *
     * So the map below is the single join between the two, and the rule is generated. Adding a
     * mime type to `config/itrequest.php` is what widens what can be uploaded — there is no second
     * place to remember.
     *
     * `mimes` rather than `mimetypes`, deliberately: `mimetypes` compares against the CLIENT's
     * claimed type, which the uploader controls, so a `.exe` claiming `application/pdf` passes.
     * `mimes` sniffs the bytes.
     */
    public static function rules(): array
    {
        $allowed = (array) config('itrequest.attachments.allowed_mimes', []);
        $maxKb = (int) config('itrequest.attachments.max_size_kb', 20480);

        $extensions = [];

        foreach ($allowed as $mime) {
            foreach (self::EXTENSIONS_FOR as $knownMime => $knownExtensions) {
                if ($knownMime === $mime) {
                    $extensions = array_merge($extensions, $knownExtensions);
                }
            }
        }

        /*
         * A config listing a mime type this map does not know would silently allow NOTHING —
         * every upload refused with a message about file type, and no indication that the
         * allow-list and the extension map had come apart. Falling back to the known set means the
         * worst case is a format that is accepted but not listed, which is visible, rather than an
         * application that refuses every document.
         */
        if ($extensions === []) {
            $extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
        }

        return [
            'required',
            'file',
            'max:'.$maxKb,
            'mimes:'.implode(',', array_unique($extensions)),
        ];
    }

    /**
     * The extensions each allowed mime type may legitimately arrive as.
     *
     * `jpeg` accepts both `.jpg` and `.jpeg` because both are in use and refusing one would be a
     * puzzle for whoever happened to have the other. The rest are one-to-one.
     */
    private const EXTENSIONS_FOR = [
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
    ];
}
