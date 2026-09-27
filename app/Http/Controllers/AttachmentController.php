<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorised document retrieval (FR-006).
 *
 * WHY A ROUTE AND NOT A PUBLIC PATH
 *
 * The file lives outside the document root and is never addressable by URL. This route is the only
 * way to it, and it authorises against the REQUEST the document belongs to before a single byte
 * moves — so access is derived from the record rather than from knowing a path or an id.
 *
 * WHY THE ID IS IN THE URL AND THAT IS SAFE
 *
 * `/documents/42` is guessable, which is exactly why guessing it achieves nothing: the id locates
 * the row, and the policy then decides whether THIS user may read the request behind it. An
 * unguessable name would be the alternative, and it would make access depend on secrecy rather
 * than on a check — the file would be readable by anyone the URL leaked to, including through a
 * proxy log or a screenshot.
 *
 * WHY IT STREAMS
 *
 * The largest upload a user may make is 20 MB, and `download()` reads it in chunks. Building a
 * response that holds the whole file works until somebody attaches a large PDF — and the failure
 * would be a 500 on exactly the upload the application said was allowed.
 */
class AttachmentController extends Controller
{
    /*
     * The slim skeleton's base `Controller` has no `AuthorizesRequests`, so `$this->authorize()`
     * would be an undefined method — a 500 rather than a 403. Pulled in explicitly.
     */
    use AuthorizesRequests;

    public function __invoke(Attachment $attachment, AttachmentService $attachments): StreamedResponse
    {
        /*
         * `viewDocuments` on the REQUEST, not on the attachment.
         *
         * An `Attachment` is not the thing being protected — the request is, and every document
         * inherits its visibility. Authorising against the attachment would need a second rule
         * saying the same thing, and the two would eventually disagree about who may see what.
         */
        $this->authorize('viewDocuments', $attachment->request);

        return $attachments->download($attachment);
    }
}
